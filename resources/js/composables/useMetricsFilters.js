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
    // Estados de venta a considerar para métricas de "venta generada"
    // (Resumen, Ventas, Productos, Clientes). Default: Completed + Pending —
    // refleja lo entregado en el período, esté cobrado o no. Persiste en
    // query string para que sobreviva la navegación entre tabs.
    const DEFAULT_STATUSES = ['completed', 'pending'];
    const statuses = ref(
        Array.isArray(page.props.statuses) && page.props.statuses.length > 0
            ? [...page.props.statuses]
            : [...DEFAULT_STATUSES]
    );

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
            // Solo agregar statuses si NO es el default. Mantiene URLs limpias.
            const statusesValue = (statuses.value || []).slice().sort();
            const defaultSorted = [...DEFAULT_STATUSES].sort();
            const isDefault =
                statusesValue.length === defaultSorted.length &&
                statusesValue.every((s, i) => s === defaultSorted[i]);
            if (!isDefault && statusesValue.length > 0) {
                query.statuses = statusesValue.join(',');
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

    const toggleStatus = (s) => {
        const current = new Set(statuses.value || []);
        if (current.has(s)) {
            current.delete(s);
        } else {
            current.add(s);
        }
        statuses.value = Array.from(current);
        navigate();
    };

    const setStatuses = (arr) => {
        statuses.value = Array.isArray(arr) ? [...arr] : [...DEFAULT_STATUSES];
        navigate();
    };

    return {
        preset, from, to, compare, branchId, statuses, isCustom,
        setPreset, setCustom, setCompare, setBranchId,
        toggleStatus, setStatuses,
        refresh, slug,
    };
}
