import { ref } from 'vue';

/**
 * Carga lazy de los datos del detalle de un proveedor (resumen, compras, pagos,
 * productos) por rango de fechas, con cancelación de peticiones en vuelo.
 *
 * `routeNames` mapea los nombres de ruta Ziggy según el rol:
 *   { resumen, compras, pagos, productos } → empresa.* o sucursal.*
 */
export function useProviderStats(providerId, tenantSlug, routeNames) {
    const resumen = ref(null);
    const productos = ref(null);
    const compras = ref({ items: [], page: 0, lastPage: 1, total: 0 });
    const pagos = ref({ items: [], page: 0, lastPage: 1, total: 0 });

    const loading = ref({ resumen: false, compras: false, pagos: false, productos: false });
    const errors = ref({ resumen: null, compras: null, pagos: null, productos: null });

    let controllers = {};

    const abortKey = (key) => {
        if (controllers[key]) {
            controllers[key].abort();
            delete controllers[key];
        }
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

    const buildUrl = (routeName, params = {}) => {
        const url = new URL(route(routeName, [tenantSlug, providerId]), window.location.origin);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') {
                url.searchParams.set(k, v);
            }
        });
        return url.toString();
    };

    const loadResumen = async ({ from, to } = {}) => {
        const data = await fetchJson('resumen', buildUrl(routeNames.resumen, { from, to }));
        if (data) {
            resumen.value = data;
        }
    };

    const loadProductos = async ({ from, to } = {}) => {
        const data = await fetchJson('productos', buildUrl(routeNames.productos, { from, to }));
        if (data) {
            productos.value = data.items || [];
        }
    };

    const loadCompras = async ({ from, to, page = 1, append = false } = {}) => {
        const data = await fetchJson('compras', buildUrl(routeNames.compras, { from, to, page, per_page: 15 }));
        if (data) {
            compras.value = {
                items: append ? [...compras.value.items, ...data.data] : data.data,
                page: data.current_page,
                lastPage: data.last_page,
                total: data.total,
            };
        }
    };

    const loadPagos = async ({ from, to, page = 1, append = false } = {}) => {
        const data = await fetchJson('pagos', buildUrl(routeNames.pagos, { from, to, page, per_page: 15 }));
        if (data) {
            pagos.value = {
                items: append ? [...pagos.value.items, ...data.data] : data.data,
                page: data.current_page,
                lastPage: data.last_page,
                total: data.total,
            };
        }
    };

    const resetTabs = () => {
        compras.value = { items: [], page: 0, lastPage: 1, total: 0 };
        pagos.value = { items: [], page: 0, lastPage: 1, total: 0 };
        productos.value = null;
    };

    return {
        resumen, compras, pagos, productos,
        loading, errors,
        loadResumen, loadCompras, loadPagos, loadProductos, resetTabs,
    };
}
