/**
 * Lógica pura de los avisos: sin Vue, sin IPC, sin DOM. Todo lo que decide
 * «qué se anuncia, en qué orden y dónde se agrupa» vive aquí para poder
 * probarlo sin montar nada.
 *
 * Portado del hub (`carniceria-hub/src/renderer/lib/notificationsCore.js`)
 * sin cambios de lógica: la isla de la web y la del hub deben decidir igual.
 * Lo único propio de la web es `isAgendaReminder`, al final.
 */

const LEVELS = ['action', 'important', 'info'];
const RANK = { action: 0, important: 1, info: 2 };

/** El nivel del aviso; uno desconocido se trata como `info` (el más callado). */
export function levelOf(n) {
    return LEVELS.includes(n?.level) ? n.level : 'info';
}

/**
 * Qué hay de nuevo en una lectura de la bandeja.
 *
 * `seen` son los ids que ya pasaron por aquí y se muta. La primera lectura
 * (`seed`) sólo los registra: entrar (al hub o a la web) no debe disparar una isla por cada
 * aviso viejo. Un aviso que llega ya leído se registra pero no se devuelve.
 *
 * @param {Set<string>} seen
 * @param {Array<{id: string, read_at: string|null}>} items
 * @param {{ seed?: boolean }} [opts]
 */
export function diffNew(seen, items, { seed = false } = {}) {
    const fresh = [];
    for (const n of items) {
        if (seen.has(n.id)) continue;
        seen.add(n.id);
        if (!seed && !n.read_at) fresh.push(n);
    }
    return fresh;
}

/** Cola de anuncios: primero lo que espera una decisión, y lo más viejo antes. */
export function sortQueue(queue) {
    return [...queue].sort(
        (a, b) => RANK[levelOf(a)] - RANK[levelOf(b)] || Date.parse(a.created_at) - Date.parse(b.created_at),
    );
}

/** Quita de la cola lo que ya se leyó (aquí, en otro equipo o en la web). */
export function pruneQueue(queue, items) {
    const unread = new Set(items.filter((n) => !n.read_at).map((n) => n.id));
    return queue.filter((n) => unread.has(n.id));
}

/** Secciones de la bandeja: Por atender, Hoy, Antes. */
export function groupItems(items, now = new Date()) {
    const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const groups = { pending: [], today: [], earlier: [] };
    for (const n of items) {
        if (levelOf(n) === 'action' && !n.read_at) groups.pending.push(n);
        else if (Date.parse(n.created_at) >= startOfDay) groups.today.push(n);
        else groups.earlier.push(n);
    }
    return groups;
}

/** ¿Queda algo que espera una decisión? Es lo que mantiene el pulso y el parpadeo. */
export function hasPendingAction(items) {
    return items.some((n) => levelOf(n) === 'action' && !n.read_at);
}

/** «ahora», «hace 12 min», «hace 2 h», «hace 3 d»: la misma que la campana web. */
export function relativeTime(value, now = Date.now()) {
    if (value === null || value === undefined || value === '') return '';
    const at = typeof value === 'number' ? value : Date.parse(value);
    const mins = Math.floor((now - at) / 60000);
    if (mins < 1) return 'ahora';
    if (mins < 60) return `hace ${mins} min`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `hace ${hours} h`;
    return `hace ${Math.floor(hours / 24)} d`;
}

/**
 * Icono y colores por nivel, sobre fondo oscuro. Mismo mapa semántico que la
 * campana web (rojo / ámbar / gris), en su versión nocturna.
 */
export const LEVEL_STYLE = {
    action: { icon: 'exclamation-triangle', chip: 'bg-rose-950 text-rose-300' },
    important: { icon: 'bell', chip: 'bg-amber-950 text-amber-300' },
    info: { icon: 'information-circle', chip: 'bg-gray-800 text-gray-400' },
};

/** Ancho máximo de la isla en reposo (con «9+») y aire mínimo con sus vecinos. */
export const ISLAND_REST_MAX = 62;
export const ISLAND_GAP = 12;

/**
 * Dónde se pone la isla en el encabezado.
 *
 * Centrada si cabe. A 880 px —el tamaño con el que abre la ventana— el grupo de
 * la derecha (Nota, conexión, rol) empieza antes del centro, y la píldora en
 * reposo tapaba «Nota» todo el tiempo. Cuando no cabe, se acopla justo a la
 * izquierda de ese grupo (`right` en px desde el borde derecho del encabezado)
 * y crece desde ahí hacia la izquierda.
 *
 * Recibe rectángulos (`left`, `right`, `width`) para poder probarlo sin DOM.
 * Devuelve también el ancho del encabezado: acoplada, la isla abierta no puede
 * crecer más allá de su borde izquierdo (ver `islandRight`).
 *
 * @returns {{ docked: boolean, right: number, width: number }}
 */
export function islandPlacement({ header, title, group }) {
    const half = ISLAND_REST_MAX / 2 + ISLAND_GAP;
    const middle = header.left + header.width / 2;
    const docked = middle - half < title.right || middle + half > group.left;
    return { docked, right: Math.round(header.right - group.left + ISLAND_GAP), width: Math.round(header.width) };
}

/**
 * Distancia al borde derecho del encabezado para una isla acoplada de ancho
 * `boxWidth`. Pegada al grupo derecho mientras quepa; si al abrirse no cabe
 * (anuncio de 340 o bandeja de 380 a 880 px), se corre a la derecha lo justo
 * para no meterse detrás del menú lateral.
 */
export function islandRight(placement, boxWidth) {
    const room = placement.width - ISLAND_GAP - boxWidth;
    return Math.max(ISLAND_GAP, Math.min(placement.right, room));
}

/**
 * ¿Es un recordatorio de agenda? Es el único aviso que se resuelve desde la
 * propia isla (Hecho / +30 min / Visto) en vez de sólo llevar a una pantalla.
 */
export const isAgendaReminder = (n) => n?.type === 'agenda.reminder.due';
