import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Qué equipos de esta sucursal están pidiendo un cargador.
 *
 * Se pregunta cada 60 s y no por WebSocket a propósito: una batería cambia en
 * minutos, y meter un evento más en el canal `sucursal.{id}` —que ya comparten
 * varios consumidores— cuesta más de lo que ahorra.
 *
 * En pausa mientras la pestaña no está visible, como el tablero de equipos: una
 * caja con diez pestañas abiertas no tiene por qué preguntar diez veces.
 */
export function useDeviceAlerts() {
    const page = usePage();
    const alerts = ref([]);
    let timer = null;

    const slug = computed(() => page.props.auth?.tenant_slug ?? null);
    const hasBranch = computed(() => !!page.props.auth?.branch?.id);

    const worst = computed(() => {
        if (alerts.value.some((a) => a.severity === 'critical')) return 'critical';
        return alerts.value.length ? 'warn' : null;
    });

    async function load() {
        if (!slug.value || !hasBranch.value) return;
        if (document.visibilityState !== 'visible') return;

        try {
            const res = await fetch(route('equipos.alertas', slug.value), {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;
            const body = await res.json();
            alerts.value = body.data ?? [];
        } catch {
            // Una franja que no se pudo refrescar no puede tumbar la caja: se
            // queda con lo último que supo y lo intenta dentro de un minuto.
        }
    }

    function onVisible() {
        if (document.visibilityState === 'visible') load();
    }

    onMounted(() => {
        load();
        timer = setInterval(load, 60000);
        document.addEventListener('visibilitychange', onVisible);
    });

    onUnmounted(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });

    return { alerts, worst };
}
