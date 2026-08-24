import { isLive, isRecovering, trackConnection } from '@/lib/realtimeState';

/**
 * Registro único de la suscripción al canal `sucursal.{branchId}`.
 *
 * Existe porque tres consumidores distintos escuchan el MISMO canal a la vez en
 * la mesa de trabajo: `useSaleQueue` (NewExternalSale), `useSaleLock`
 * (SaleLocked/SaleUnlocked) y la propia página (SaleUpdated). Cada uno se
 * suscribía por su cuenta y `useSaleQueue` hacía `Echo.leave()` al desmontarse
 * — y `leave()` abandona el canal ENTERO, no sólo sus listeners, dejando mudos
 * a los otros dos.
 *
 * Aquí el canal se abre una vez, cada consumidor engancha y desengancha sólo sus
 * propios handlers, y sólo se abandona cuando se va el último.
 *
 * El socket es uno por aplicación, así que su estado también: vive a nivel de
 * módulo y todas las pantallas leen el mismo (misma decisión que en el hub, que
 * llegó a ella tras un bug de indicadores congelados).
 */

/** @type {Map<number, {channel: any, handlers: Map<string, Set<Function>>, count: number}>} */
const channels = new Map();

/** Estado del socket tal como lo reporta pusher. */
let socketState = 'initialized';
/** @type {Set<(state: string) => void>} */
const stateWatchers = new Set();
let untrack = null;

function setSocketState(next) {
    if (next === socketState) return;
    socketState = next;
    stateWatchers.forEach((fn) => fn(socketState));
}

/** Engancha el seguimiento del socket la primera vez que hace falta. */
function ensureConnectionTracked() {
    if (untrack || !window.Echo) return;
    untrack = trackConnection(window.Echo.connector?.pusher?.connection, setSocketState);
}

/**
 * Observa el estado del socket. Devuelve la función para dejar de observar.
 *
 * @param {(state: string) => void} fn
 */
export function watchSocketState(fn) {
    ensureConnectionTracked();
    stateWatchers.add(fn);
    fn(socketState); // estado actual, para quien llega tarde
    return () => stateWatchers.delete(fn);
}

export function currentSocketState() {
    return socketState;
}

export { isLive, isRecovering };

/**
 * Suscribe handlers al canal de una sucursal.
 *
 * @param {number} branchId
 * @param {Record<string, Function>} handlers  { NewExternalSale: fn, ... }
 * @returns {() => void} desengancha SÓLO estos handlers
 */
export function subscribeToBranch(branchId, handlers) {
    if (!branchId || !window.Echo) return () => {};

    ensureConnectionTracked();

    let entry = channels.get(branchId);
    if (!entry) {
        entry = {
            channel: window.Echo.private(`sucursal.${branchId}`),
            handlers: new Map(),
            count: 0,
        };
        channels.set(branchId, entry);

        // Si la firma del canal falla (sesión caducada, por ejemplo) el canal
        // se queda mudo en silencio y la pantalla seguiría diciendo "En vivo".
        if (typeof entry.channel.error === 'function') {
            entry.channel.error(() => setSocketState('unavailable'));
        }
    }

    entry.count++;

    const registered = [];
    for (const [event, fn] of Object.entries(handlers)) {
        if (typeof fn !== 'function') continue;
        entry.channel.listen(event, fn);
        if (!entry.handlers.has(event)) entry.handlers.set(event, new Set());
        entry.handlers.get(event).add(fn);
        registered.push([event, fn]);
    }

    let released = false;

    return () => {
        if (released) return;
        released = true;

        for (const [event, fn] of registered) {
            // Con la función concreta: laravel-echo desengancha ese handler y
            // deja vivos los de los demás consumidores.
            entry.channel.stopListening(event, fn);
            entry.handlers.get(event)?.delete(fn);
        }

        entry.count--;
        if (entry.count > 0) return;

        // Último consumidor: ahora sí se puede abandonar el canal.
        channels.delete(branchId);
        try {
            window.Echo.leave(`sucursal.${branchId}`);
        } catch {
            /* el socket ya podía estar cerrado */
        }
    };
}

/** Sólo para pruebas: olvida el estado acumulado entre casos. */
export function __resetBranchChannels() {
    channels.clear();
    stateWatchers.clear();
    if (untrack) untrack();
    untrack = null;
    socketState = 'initialized';
}
