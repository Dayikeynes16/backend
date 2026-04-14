import { ref, watch } from 'vue';

/**
 * Lazy-loads customer dashboard data per section with request cancellation
 * when the selected customer changes.
 */
export function useCustomerStats(selectedCustomerRef, tenantSlug) {
    const stats = ref(null);
    const history = ref(null);
    const topProducts = ref(null);
    const payments = ref(null);

    const loading = ref({ stats: false, history: false, topProducts: false, payments: false });
    const errors = ref({ stats: null, history: null, topProducts: null, payments: null });

    let controllers = {};

    const resetAll = () => {
        stats.value = null;
        history.value = null;
        topProducts.value = null;
        payments.value = null;
        errors.value = { stats: null, history: null, topProducts: null, payments: null };
    };

    const abortKey = (key) => {
        if (controllers[key]) {
            controllers[key].abort();
            delete controllers[key];
        }
    };

    const abortAll = () => {
        Object.keys(controllers).forEach(abortKey);
    };

    const fetchJson = async (key, url) => {
        abortKey(key);
        const ctrl = new AbortController();
        controllers[key] = ctrl;
        loading.value[key] = true;
        errors.value[key] = null;
        try {
            const res = await fetch(url, {
                signal: ctrl.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            loading.value[key] = false;
            delete controllers[key];
            return data;
        } catch (e) {
            if (e.name !== 'AbortError') {
                errors.value[key] = e.message || 'Error cargando datos';
                loading.value[key] = false;
            }
            return null;
        }
    };

    const loadStats = async () => {
        const c = selectedCustomerRef.value;
        if (!c) return;
        const data = await fetchJson('stats', route('sucursal.clientes.stats', [tenantSlug, c.id]));
        if (data) stats.value = data;
    };

    const loadHistory = async (params = {}) => {
        const c = selectedCustomerRef.value;
        if (!c) return;
        const url = new URL(route('sucursal.clientes.historial', [tenantSlug, c.id]), window.location.origin);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
        const data = await fetchJson('history', url.toString());
        if (data) history.value = data;
    };

    const loadTopProducts = async (limit = 10) => {
        const c = selectedCustomerRef.value;
        if (!c) return;
        const url = new URL(route('sucursal.clientes.productos-top', [tenantSlug, c.id]), window.location.origin);
        url.searchParams.set('limit', limit);
        const data = await fetchJson('topProducts', url.toString());
        if (data) topProducts.value = data;
    };

    const loadPayments = async () => {
        const c = selectedCustomerRef.value;
        if (!c) return;
        const data = await fetchJson('payments', route('sucursal.clientes.pagos', [tenantSlug, c.id]));
        if (data) payments.value = data;
    };

    const registerGlobalPayment = async (payload) => {
        const c = selectedCustomerRef.value;
        if (!c) throw new Error('Sin cliente seleccionado');

        const res = await fetch(route('sucursal.clientes.cobro-global', [tenantSlug, c.id]), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            const message = err.message || 'Error al registrar el pago';
            throw Object.assign(new Error(message), { payload: err, status: res.status });
        }

        const data = await res.json();

        // Invalidate cached data and refresh what's relevant
        stats.value = null;
        payments.value = null;
        history.value = null;
        await Promise.all([loadStats(), loadPayments()]);

        return data;
    };

    watch(selectedCustomerRef, (c, prev) => {
        if (c?.id !== prev?.id) {
            abortAll();
            resetAll();
            if (c) loadStats();
        }
    });

    return {
        stats, history, topProducts, payments,
        loading, errors,
        loadStats, loadHistory, loadTopProducts, loadPayments,
        registerGlobalPayment,
    };
}
