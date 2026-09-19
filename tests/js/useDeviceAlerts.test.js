// @vitest-environment happy-dom
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createApp } from 'vue';
import { useDeviceAlerts } from '@/composables/useDeviceAlerts';

/**
 * `useDeviceAlerts` es la única pieza detrás de la franja de batería
 * (`DeviceAlertStrip.vue`): decide cuándo preguntar y qué tan grave es lo peor
 * que encontró. A diferencia de `branchChannel`/`realtimeState`, depende de
 * Vue (onMounted/onUnmounted) y del navegador (`document`), así que este
 * archivo monta un componente real de Vue en un DOM de mentira (happy-dom) en
 * vez de invocar el composable suelto.
 */

const state = vi.hoisted(() => ({ pageProps: {} }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.pageProps }),
}));

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

const withBranch = (authOverrides = {}) => ({
    auth: { tenant_slug: 'el-toro', branch: { id: 1 }, role: 'cajero', ...authOverrides },
});

function okResponse(alerts) {
    return { ok: true, json: async () => ({ data: alerts }) };
}

// Las apps que un test monta y no llega a desmontar (por ejemplo, porque una
// aserción revienta antes) dejarían un listener de `visibilitychange` vivo
// que contaminaría el resto de los tests del archivo. `afterEach` las cierra
// todas, monte lo que monte cada test.
let mountedApps = [];

/** Monta el composable dentro de un componente que no pinta nada. */
function mountComposable() {
    let result;
    const app = createApp({
        setup() {
            result = useDeviceAlerts();
            return () => null;
        },
    });
    app.mount(document.createElement('div'));
    mountedApps.push(app);
    return { app, result };
}

function setVisibility(value) {
    Object.defineProperty(document, 'visibilityState', { value, configurable: true });
}

beforeEach(() => {
    state.pageProps = withBranch();
    setVisibility('visible');
    global.route = vi.fn((name, params) => `/${name}/${params}`);
    global.fetch = vi.fn().mockResolvedValue(okResponse([]));
});

afterEach(() => {
    mountedApps.forEach((app) => app.unmount());
    mountedApps = [];
    setVisibility('visible');
    vi.restoreAllMocks();
});

describe('useDeviceAlerts', () => {
    it('no consulta si la sucursal del usuario no tiene sucursal asignada', async () => {
        state.pageProps = withBranch({ branch: null });

        const { app } = mountComposable();
        await flushPromises();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('no consulta mientras la pestaña está oculta', async () => {
        setVisibility('hidden');

        mountComposable();
        await flushPromises();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('consulta al montar cuando hay sucursal y la pestaña está visible', async () => {
        global.fetch = vi.fn().mockResolvedValue(okResponse([{ device_id: 'd1', name: 'Balanza 1', battery_level: 15, severity: 'warn' }]));

        const { result } = mountComposable();
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(result.alerts.value).toHaveLength(1);
    });

    describe('worst', () => {
        it('devuelve critical si algún equipo está en critical', async () => {
            const { result } = mountComposable();
            await flushPromises();

            result.alerts.value = [
                { device_id: 'a', severity: 'warn' },
                { device_id: 'b', severity: 'critical' },
            ];

            expect(result.worst.value).toBe('critical');
        });

        it('devuelve warn si sólo hay avisos', async () => {
            const { result } = mountComposable();
            await flushPromises();

            result.alerts.value = [{ device_id: 'a', severity: 'warn' }];

            expect(result.worst.value).toBe('warn');
        });

        it('devuelve null si no hay ningún equipo en alerta', async () => {
            const { result } = mountComposable();
            await flushPromises();

            expect(result.alerts.value).toEqual([]);
            expect(result.worst.value).toBe(null);
        });
    });

    it('un fallo de la consulta deja la lista anterior intacta', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce(okResponse([{ device_id: 'd1', name: 'Balanza 1', battery_level: 15, severity: 'warn' }]))
            .mockRejectedValueOnce(new Error('network down'));

        const { result } = mountComposable();
        await flushPromises();
        expect(result.alerts.value).toHaveLength(1);

        // Dispara un segundo `load()` reentrando por el mismo camino que usa la
        // pestaña al volver a primer plano: la pestaña ya está visible, así que
        // el listener de `visibilitychange` vuelve a preguntar.
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(2);
        expect(result.alerts.value).toHaveLength(1);
        expect(result.alerts.value[0].device_id).toBe('d1');
    });

    it('al desmontar limpia el intervalo y el listener de visibilidad', async () => {
        const addSpy = vi.spyOn(document, 'addEventListener');
        const removeSpy = vi.spyOn(document, 'removeEventListener');
        const clearSpy = vi.spyOn(global, 'clearInterval');

        const { app } = mountComposable();
        await flushPromises();

        const [, handler] = addSpy.mock.calls.find(([evt]) => evt === 'visibilitychange');
        expect(handler).toBeTypeOf('function');

        app.unmount();
        mountedApps = mountedApps.filter((a) => a !== app);

        expect(clearSpy).toHaveBeenCalledTimes(1);
        expect(removeSpy).toHaveBeenCalledWith('visibilitychange', handler);
    });
});
