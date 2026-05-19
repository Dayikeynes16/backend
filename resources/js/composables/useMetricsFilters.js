import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Sincroniza rango (preset/from/to), branch y statuses con query params.
 * Mantiene una sola fuente de verdad para los filtros globales de Métricas.
 */
export function useMetricsFilters(routeName) {
    const page = usePage();
    const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

    const initial = page.props.range ?? {};
    const preset = ref(initial.preset ?? 'today');
    const from = ref(initial.from ?? '');
    const to = ref(initial.to ?? '');
    const branchId = ref(page.props.selected_branch_id ?? null);
    // Estados de venta a considerar para métricas de "venta generada"
    // (Resumen, Ventas, Productos, Clientes). Default: solo Completed —
    // igual que el resto de pantallas (Dashboard, Historial). Los chips
    // permiten añadir Pending/Cancelled; persiste en query string para que
    // sobreviva la navegación entre tabs. Debe coincidir con
    // ResolvesMetricsRequest::resolveStatuses() / SalesMetrics::DEFAULT_STATUSES.
    const DEFAULT_STATUSES = ['completed'];
    const statuses = ref(
        Array.isArray(page.props.statuses) && page.props.statuses.length > 0
            ? [...page.props.statuses]
            : [...DEFAULT_STATUSES]
    );

    const isCustom = computed(() => preset.value === '__custom__' || (!preset.value && from.value && to.value));

    let navTimer = null;
    const navigate = () => {
        if (navTimer) clearTimeout(navTimer);
        navTimer = setTimeout(() => {
            const query = {};
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

    const setBranchId = (val) => {
        branchId.value = val === '' ? null : val;
        navigate();
    };

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
        preset, from, to, branchId, statuses, isCustom,
        setPreset, setCustom, setBranchId,
        toggleStatus, setStatuses,
        slug,
    };
}
