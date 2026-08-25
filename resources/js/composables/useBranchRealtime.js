import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { isLive, isRecovering, subscribeToBranch, watchSocketState } from '@/lib/branchChannel';

/**
 * Tiempo real de una sucursal, con sondeo de respaldo.
 *
 * Es el patrón que el hub ya usaba en su Mesa de Trabajo, traído a la web: el
 * WebSocket es el mecanismo principal y el sondeo sólo existe para cuando no
 * hay WebSocket. Hasta ahora la web dependía únicamente de Reverb, así que un
 * socket caído dejaba la mesa congelada y en silencio: el cajero veía una lista
 * quieta y concluía que no habían entrado ventas.
 *
 * - Con socket vivo: sondeo lento (20 s), sólo por si se perdió algún evento.
 * - Sin socket: sondeo rápido (4 s), que es lo que sostiene la pantalla.
 * - Al recuperarse el socket: se refresca de inmediato para recuperar lo que
 *   pasó mientras no había conexión (los eventos perdidos no se reenvían).
 *
 * Las recargas se agrupan 300 ms: una sola venta genera varios eventos casi
 * seguidos (NewExternalSale, SaleLocked, SaleUpdated) y sin agrupar cada uno
 * lanzaba su propia petición y su propio repintado.
 */
export function useBranchRealtime(branchId, options = {}) {
    const {
        handlers = {},
        refresh = null,
        liveIntervalMs = 20000,
        fallbackIntervalMs = 4000,
        coalesceMs = 300,
    } = options;

    const socketState = ref('initialized');
    const live = computed(() => isLive(socketState.value));
    const recovering = computed(() => isRecovering(socketState.value));

    let coalesceTimer = null;
    let pollTimer = null;
    let unsubscribeChannel = null;
    let unwatchState = null;

    /** Agrupa las recargas de una ráfaga de eventos en una sola. */
    function refreshSoon() {
        if (!refresh) return;
        clearTimeout(coalesceTimer);
        coalesceTimer = setTimeout(() => refresh(), coalesceMs);
    }

    function startPolling(ms) {
        clearInterval(pollTimer);
        if (!refresh) return;
        pollTimer = setInterval(() => refresh(), ms);
    }

    // Los handlers reciben `refreshSoon` para no tener que importarlo aparte.
    const boundHandlers = Object.fromEntries(
        Object.entries(handlers).map(([event, fn]) => [event, (e) => fn(e, refreshSoon)]),
    );

    onMounted(() => {
        unwatchState = watchSocketState((state) => {
            socketState.value = state;
        });

        unsubscribeChannel = subscribeToBranch(branchId, boundHandlers);

        startPolling(live.value ? liveIntervalMs : fallbackIntervalMs);
    });

    watch(live, (isNowLive, wasLive) => {
        startPolling(isNowLive ? liveIntervalMs : fallbackIntervalMs);

        // Reverb no reenvía lo que se perdió mientras el socket estaba caído:
        // al volver hay que leer el estado real por HTTP.
        if (isNowLive && wasLive === false) refreshSoon();
    });

    onUnmounted(() => {
        clearTimeout(coalesceTimer);
        clearInterval(pollTimer);
        if (unsubscribeChannel) unsubscribeChannel();
        if (unwatchState) unwatchState();
    });

    return { live, recovering, refreshSoon };
}
