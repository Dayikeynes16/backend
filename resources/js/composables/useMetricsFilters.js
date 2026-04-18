import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Sincroniza rango (preset/from/to), comparativo y branch con query params.
 * Mantiene una sola fuente de verdad para los filtros globales de Métricas.
 */
export function useMetricsFilters(routeName) {
    const page = usePage();
    const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

    const initial = page.props.range ?? {};
    const preset = ref(initial.preset ?? 'today');
    const from = ref(initial.from ?? '');
    const to = ref(initial.to ?? '');
    const compare = ref(page.props.compare ?? true);
    const branchId = ref(page.props.selected_branch_id ?? null);

    const isCustom = computed(() => preset.value === '__custom__' || (!preset.value && from.value && to.value));

    let navTimer = null;
    const navigate = (opts = {}) => {
        if (navTimer) clearTimeout(navTimer);
        navTimer = setTimeout(() => {
            const query = { compare: compare.value ? 1 : 0 };
            if (branchId.value !== null && branchId.value !== '') query.branch_id = branchId.value;
            if (isCustom.value && from.value && to.value) {
                query.from = from.value;
                query.to = to.value;
            } else if (preset.value) {
                query.preset = preset.value;
            }
            if (opts.refresh) query.refresh = 1;
            router.get(route(routeName, slug.value), query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 150);
    };

    const setPreset = (name) => {
        preset.value = name;
        from.value = '';
        to.value = '';
        navigate();
    };

    const setCustom = (fromVal, toVal) => {
        preset.value = '__custom__';
        from.value = fromVal;
        to.value = toVal;
        if (fromVal && toVal) navigate();
    };

    const setCompare = (val) => {
        compare.value = val;
        navigate();
    };

    const setBranchId = (val) => {
        branchId.value = val === '' ? null : val;
        navigate();
    };

    const refresh = () => navigate({ refresh: true });

    return {
        preset, from, to, compare, branchId, isCustom,
        setPreset, setCustom, setCompare, setBranchId, refresh, slug,
    };
}
