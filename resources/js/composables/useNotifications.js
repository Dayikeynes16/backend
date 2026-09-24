import { ref } from 'vue';
import { diffNew, sortQueue, pruneQueue, levelOf, hasPendingAction } from '@/lib/notificationsCore.js';
import { routeFor } from '@/lib/notificationRoutes.js';
import { isLive as isLiveState, trackConnection } from '@/lib/realtimeState.js';
import { playChime } from '@/lib/chime.js';

/**
 * Avisos personales del usuario: los que la nube guarda y además
 * anuncia por el canal `App.Models.User.{id}`.
 *
 * Tres reglas, las mismas que el resto del tiempo real (aquí y en el hub):
 *
 *  1. **El evento es una señal, no el dato.** Laravel pisa el `type` del aviso
 *     con el nombre de la clase al emitirlo, así que lo que llega por el socket
 *     no dice adónde lleva. Al recibirlo se relee la bandeja por HTTP.
 *  2. **El socket es el camino rápido, el sondeo la red de seguridad**: 20 s con
 *     el socket vivo, 4 s sin él.
 *  3. **La fila garantiza.** Lo que llegó mientras nadie miraba está en la
 *     bandeja al volver; lo que se anuncia es sólo lo que aparece nuevo después
 *     de la primera lectura.
 *
 * El motor está separado del singleton para probarlo con dobles. Es un port
 * del de `carniceria-hub` (`src/renderer/composables/useNotifications.js`),
 * con las mismas firmas, más lo que pide la web:
 *
 *  - `ensureStarted(user)`: en la web los layouts envuelven cada página y la
 *    isla se vuelve a montar en cada navegación de Inertia. El motor arranca
 *    una vez por usuario y NO se detiene al desmontarse la isla, para que un
 *    anuncio en curso sobreviva a la navegación y no se pierda lo que llegue
 *    entre dos páginas. Cerrar sesión recarga la página entera y lo reinicia.
 *  - `review(n, ctx)` devuelve la URL (Ziggy), no un nombre de ruta del hub.
 *  - `settle(n)`: las acciones del recordatorio de agenda (Hecho / +30 min /
 *    Visto) las resuelve el backend, que ya marca leídos sus avisos.
 */

export const POLL_LIVE_MS = 20000;
export const POLL_FALLBACK_MS = 4000;
export const AUTO_DISMISS_MS = 6000;
export const COALESCE_MS = 300;

/**
 * @param {{
 *   api: { list: Function, markRead: Function, markAllRead: Function },
 *   notifier: { native: Function, attention: Function },
 *   listenUser: (userId: number, cb: Function) => Promise<() => void>,
 *   chime: (level: string) => void,
 *   isLive: () => boolean,
 *   isWindowActive: () => boolean,
 * }} deps
 */
export function createNotificationCenter({ api, notifier, listenUser: listen, chime, isLive, isWindowActive }) {
    const items = ref([]);
    const unreadCount = ref(0);
    const online = ref(null);
    const lastSyncedAt = ref(null);
    const current = ref(null);
    const queue = ref([]);
    const trayOpen = ref(false);
    const pulse = ref(0);
    const highlightId = ref(null);

    let seen = new Set();
    /**
     * Sube con cada `stop()`. Una lectura que vuelve después de cerrar sesión no
     * debe pintar los avisos del cajero anterior sobre el que acaba de entrar.
     */
    let generation = 0;
    let unlisten = () => {};
    /**
     * Para quién corre el motor. Se fija en cuanto arranca (no al terminar la
     * primera lectura): una isla que se vuelve a montar mientras esa lectura
     * sigue en vuelo no debe tirarla y empezar otra.
     */
    let startedFor = null;
    let pollTimer = null;
    let autoTimer = null;
    let coalesceTimer = null;

    async function start(user) {
        stop();
        if (!user?.id) return;
        startedFor = user.id;
        const gen = generation;

        await load({ seed: true });
        if (gen !== generation) return;

        const off = await listen(user.id, signal);
        if (gen !== generation) {
            off();
            return;
        }
        unlisten = off;
        schedule();
    }

    /** Arranca sólo si no está corriendo ya para este mismo usuario. */
    async function ensureStarted(user) {
        if (user?.id && startedFor === user.id) return;
        await start(user);
    }

    function stop() {
        generation++;
        startedFor = null;
        clearTimeout(pollTimer);
        clearTimeout(autoTimer);
        clearTimeout(coalesceTimer);
        pollTimer = autoTimer = coalesceTimer = null;
        unlisten();
        unlisten = () => {};
        seen = new Set();
        items.value = [];
        unreadCount.value = 0;
        online.value = null;
        lastSyncedAt.value = null;
        current.value = null;
        queue.value = [];
        trayOpen.value = false;
        highlightId.value = null;
        notifier.attention(false);
    }

    /** Una ráfaga de eventos es una sola lectura. */
    function signal() {
        clearTimeout(coalesceTimer);
        coalesceTimer = setTimeout(() => {
            coalesceTimer = null;
            load();
        }, COALESCE_MS);
    }

    function schedule() {
        const gen = generation;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(
            async () => {
                await load();
                if (gen === generation) schedule();
            },
            isLive() ? POLL_LIVE_MS : POLL_FALLBACK_MS,
        );
    }

    async function load({ seed = false } = {}) {
        const gen = generation;
        let res;
        try {
            res = await api.list();
        } catch {
            res = null;
        }
        if (gen !== generation) return;

        // Sin respuesta: se conserva lo último que se leyó y se dice desde cuándo.
        if (!res?.ok) {
            if (!res || res.error === 'offline') online.value = false;
            return;
        }

        online.value = true;
        lastSyncedAt.value = Date.now();
        const list = res.data?.notifications ?? [];
        items.value = list;
        unreadCount.value = res.data?.unread_count ?? list.filter((n) => !n.read_at).length;

        const fresh = diffNew(seen, list, { seed });
        queue.value = pruneQueue(queue.value, list);
        // Lo que se está anunciando se leyó en otro sitio: fuera.
        if (current.value && !list.some((n) => n.id === current.value.id && !n.read_at)) dismiss();
        if (fresh.length) announce(fresh);
        syncAttention();
    }

    function announce(fresh) {
        let loudest = null;
        for (const n of fresh) {
            const level = levelOf(n);
            if (level === 'info') {
                pulse.value++;
                continue;
            }
            // Con la bandeja abierta el aviso ya se ve en la lista; sólo lo que
            // espera una decisión se guarda para anunciarse al cerrarla.
            if (trayOpen.value && level !== 'action') continue;
            queue.value.push(n);
            if (!loudest || level === 'action') loudest = level;
            if (!isWindowActive()) notifier.native({ id: n.id, title: n.title, body: n.body });
        }
        queue.value = sortQueue(queue.value);
        if (loudest) chime(loudest);
        advance();
    }

    function advance() {
        if (current.value || trayOpen.value) return;
        const next = queue.value.shift();
        if (!next) return;
        current.value = next;
        armAuto();
    }

    function armAuto() {
        pauseAuto();
        if (current.value && levelOf(current.value) === 'important') {
            autoTimer = setTimeout(dismiss, AUTO_DISMISS_MS);
        }
    }

    function pauseAuto() {
        clearTimeout(autoTimer);
        autoTimer = null;
    }

    function resumeAuto() {
        armAuto();
    }

    /** «Luego», o el fin de los 6 s: se recoge y pasa el siguiente. */
    function dismiss() {
        pauseAuto();
        current.value = null;
        advance();
    }

    function openTray(id = null) {
        pauseAuto();
        if (current.value && levelOf(current.value) === 'action') queue.value.unshift(current.value);
        current.value = null;
        queue.value = queue.value.filter((n) => levelOf(n) === 'action');
        highlightId.value = id;
        trayOpen.value = true;
    }

    function closeTray() {
        trayOpen.value = false;
        highlightId.value = null;
        queue.value = pruneQueue(queue.value, items.value);
        advance();
    }

    async function markRead(id) {
        if (online.value === false) return;
        const target = items.value.find((n) => n.id === id);
        if (!target || target.read_at) return;

        const gen = generation;
        target.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        queue.value = queue.value.filter((n) => n.id !== id);
        syncAttention();

        let res;
        try {
            res = await api.markRead(id);
        } catch {
            res = null;
        }
        if (gen !== generation) return;

        if (res?.ok) {
            unreadCount.value = res.data?.unread_count ?? unreadCount.value;
        } else {
            target.read_at = null;
            unreadCount.value++;
        }
        syncAttention();
    }

    async function markAllRead() {
        if (online.value === false) return;
        const gen = generation;
        const now = new Date().toISOString();
        const changed = items.value.filter((n) => !n.read_at);
        const before = unreadCount.value;

        changed.forEach((n) => {
            n.read_at = now;
        });
        unreadCount.value = 0;
        queue.value = [];
        syncAttention();

        let res;
        try {
            res = await api.markAllRead();
        } catch {
            res = null;
        }
        if (gen !== generation) return;

        if (res?.ok) {
            unreadCount.value = res.data?.unread_count ?? 0;
        } else {
            changed.forEach((n) => {
                n.read_at = null;
            });
            unreadCount.value = before;
        }
        syncAttention();
    }

    /**
     * «Revisar» (o clic en una fila): marca, recoge y devuelve adónde ir (la URL,
     * o null si el aviso no lleva a ningún lado). La navegación la hace quien
     * llama: el motor no conoce el router.
     *
     * @param {{ id: string, type?: string }} n
     * @param {{ role?: string, slug?: string|null, route: Function }} ctx
     * @returns {string|null}
     */
    function review(n, ctx) {
        markRead(n.id);
        queue.value = queue.value.filter((q) => q.id !== n.id);
        if (current.value?.id === n.id) dismiss();
        if (trayOpen.value) closeTray();
        return routeFor(n, ctx);
    }

    /**
     * Tras una acción de agenda (Hecho / +30 min / Visto) sobre el aviso `n`.
     * El backend ya marcó leídos los avisos de ese ítem, así que aquí no se
     * llama a `markRead` (sería una segunda petición para nada): se recoge el
     * anuncio, se da por leído en local para que el contador baje ya, y se
     * relee la bandeja para quedar en lo que diga la nube.
     */
    function settle(n) {
        queue.value = queue.value.filter((q) => q.id !== n.id);
        if (current.value?.id === n.id) dismiss();
        const target = items.value.find((x) => x.id === n.id);
        if (target && !target.read_at) {
            target.read_at = new Date().toISOString();
            unreadCount.value = Math.max(0, unreadCount.value - 1);
        }
        syncAttention();
        signal();
    }

    function syncAttention() {
        notifier.attention(hasPendingAction(items.value) && !isWindowActive());
    }

    /** La ventana vuelve a verse o a tener foco: apagar el parpadeo y ponerse al día. */
    function onWindowActive() {
        syncAttention();
        signal();
    }

    /** Alguien tocó la notificación nativa (en el hub; la web no tiene). */
    function openFromNative(id) {
        openTray(id);
    }

    return {
        items,
        unreadCount,
        online,
        lastSyncedAt,
        current,
        queue,
        trayOpen,
        pulse,
        highlightId,
        start,
        ensureStarted,
        stop,
        load,
        signal,
        markRead,
        markAllRead,
        review,
        settle,
        dismiss,
        pauseAuto,
        resumeAuto,
        openTray,
        closeTray,
        onWindowActive,
        openFromNative,
    };
}

let center = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * `fetch` adaptado a la forma `{ ok, data, error }` que espera el motor (la del
 * puente del hub): `offline` si ni siquiera hubo respuesta, `server` si la hubo
 * pero no fue 2xx. La diferencia importa: sólo `offline` pinta «Sin conexión».
 */
async function request(url, method = 'GET') {
    let res;
    try {
        res = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrfToken() }),
            },
        });
    } catch {
        return { ok: false, error: 'offline' };
    }
    if (!res.ok) return { ok: false, error: 'server', status: res.status };
    try {
        return { ok: true, data: await res.json() };
    } catch {
        return { ok: false, error: 'server', status: res.status };
    }
}

// `route` (Ziggy) y `window` sólo se tocan dentro de estas funciones, nunca al
// importar el módulo: así se puede importar en pruebas sin navegador.
const webApi = {
    list: () => request(route('notifications.index')),
    markRead: (id) => request(route('notifications.read', id), 'PATCH'),
    markAllRead: () => request(route('notifications.read-all'), 'PATCH'),
};

/**
 * Escucha las notificaciones del canal privado del usuario. Al soltar se quita
 * SÓLO este oyente: nunca `Echo.leave()`, que abandonaría el canal entero (la
 * misma trampa que llevó a `branchChannel.js`).
 */
function listenUser(userId, cb) {
    const ch = window.Echo?.private(`App.Models.User.${userId}`);
    ch?.notification(cb);
    return () => ch?.stopListeningForNotification(cb);
}

/**
 * Estado del socket para decidir el ritmo del sondeo (20 s vivo, 4 s no). Se
 * engancha la primera vez que el motor pregunta, y se reintenta mientras Echo
 * no exista todavía.
 */
let socketState = 'initialized';
let tracking = false;
function socketLive() {
    if (!tracking && window.Echo) {
        tracking = true;
        trackConnection(window.Echo.connector?.pusher?.connection, (s) => {
            socketState = s;
        });
    }
    return isLiveState(socketState);
}

/** Un solo centro de avisos por aplicación, como un solo socket. */
export function useNotifications() {
    if (!center) {
        center = createNotificationCenter({
            api: webApi,
            // La web no manda notificaciones del sistema ni hace parpadear nada:
            // con la pestaña oculta, la isla sólo suena.
            notifier: { native() {}, attention() {} },
            listenUser,
            chime: playChime,
            isLive: socketLive,
            isWindowActive: () => !document.hidden && document.hasFocus(),
        });
    }
    return center;
}
