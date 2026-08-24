/**
 * Traducción del estado del socket (protocolo pusher) a "¿hay tiempo real?".
 *
 * Portado tal cual del hub (`carniceria-hub/src/renderer/lib/realtimeState.js`),
 * donde ya llevaba tiempo funcionando. Vive aparte del composable para poder
 * probarlo sin Vue y sin un servidor Reverb: basta un objeto que imite
 * `pusher.connection`.
 *
 * Por qué `state_change` y no los eventos sueltos (`connected`, `disconnected`,
 * `unavailable`, `failed`, `error`):
 *
 *  1. `error` lo emite pusher también para errores de canal, con el socket
 *     perfectamente vivo; tomarlo por "sin conexión" deja el indicador en falso
 *     para siempre, porque `connected` sólo se re-emite si el socket llegó a
 *     caerse de verdad.
 *  2. Quien empieza a escuchar tarde no ve el estado en el que ya estaba la
 *     conexión, y se queda con el valor inicial.
 */

/** Estados de pusher en los que los eventos llegan de verdad. */
export const LIVE_STATES = new Set(['connected']);

/**
 * Estados desde los que pusher reintenta solo. Se distinguen de `failed` para
 * no llamar "caído" a algo que se está recuperando.
 */
export const RECOVERING_STATES = new Set(['connecting', 'unavailable']);

/** @returns {boolean} ¿llegan eventos en tiempo real en este estado? */
export function isLive(state) {
    return LIVE_STATES.has(state);
}

/** @returns {boolean} ¿está intentando recuperarse por su cuenta? */
export function isRecovering(state) {
    return RECOVERING_STATES.has(state);
}

/**
 * Engancha el seguimiento del estado a una conexión pusher.
 *
 * @param {{state?: string, bind: (evt: string, fn: Function) => void}} conn
 * @param {(state: string) => void} onState  recibe cada estado nuevo
 * @returns {() => void} función para dejar de escuchar
 */
export function trackConnection(conn, onState) {
    if (!conn || typeof conn.bind !== 'function') return () => {};

    const handler = (payload) => {
        // pusher entrega { previous, current }; se acepta también un string
        // suelto para no depender de la forma exacta en las pruebas.
        const next = typeof payload === 'string' ? payload : payload?.current;
        if (next) onState(next);
    };

    conn.bind('state_change', handler);

    // Estado en el que ya se encontraba: quien se engancha tarde (una segunda
    // visita a la pantalla, con el socket ya conectado) debe verlo de inmediato,
    // porque `state_change` no se volverá a disparar por sí solo.
    if (conn.state) onState(conn.state);

    return () => {
        if (typeof conn.unbind === 'function') conn.unbind('state_change', handler);
    };
}
