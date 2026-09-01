import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Rango de fechas para pantallas que no son Métricas.
 *
 * `useMetricsFilters` hace esto mismo, pero arrastra sucursal, estados de venta
 * y la forma de las props de Métricas. Aquí solo hace falta el rango, con el
 * contrato que espera `DateRangeFilter`: preset, from, to, isCustom, setPreset
 * y setCustom.
 *
 * El servidor resuelve el rango con `DateRange::fromRequest`, así que la query
 * lleva `preset` o el par `from`/`to`, nunca los dos.
 *
 * @param {string} routeName Ruta Ziggy de la pantalla.
 * @param {() => Record<string, unknown>} extraQuery Los demás filtros de la
 *   pantalla. Se vuelven a mandar al cambiar el rango; sin esto se perderían.
 * @param {{ onNavigate?: () => void }} [opts] `onNavigate` corre antes de
 *   navegar, para limpiar selección o estado local.
 */
export function useDateRangeFilter(routeName, extraQuery = () => ({}), opts = {}) {
    const page = usePage();
    const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

    const initial = page.props.range ?? {};
    const preset = ref(initial.preset ?? 'today');
    const from = ref(initial.from ?? '');
    const to = ref(initial.to ?? '');

    const isCustom = computed(
        () => preset.value === '__custom__' || (!preset.value && !!from.value && !!to.value)
    );

    const navigate = () => {
        opts.onNavigate?.();

        const query = { ...extraQuery() };

        if (isCustom.value && from.value && to.value) {
            query.from = from.value;
            query.to = to.value;
        } else if (preset.value) {
            query.preset = preset.value;
        }

        router.get(route(routeName, slug.value), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const setPreset = (p) => {
        preset.value = p;
        navigate();
    };

    const setCustom = (f, t) => {
        // `__custom__` es lo que `isCustom` reconoce y lo que apaga los chips
        // de preset. No viaja al servidor: allí lo que manda es from/to.
        preset.value = '__custom__';
        from.value = f;
        to.value = t;
        navigate();
    };

    /** Los parámetros del rango, para reutilizarlos en otras peticiones (scroll infinito). */
    const rangeQuery = () =>
        (isCustom.value && from.value && to.value
            ? { from: from.value, to: to.value }
            : { preset: preset.value || 'today' });

    return { preset, from, to, isCustom, setPreset, setCustom, navigate, rangeQuery };
}
