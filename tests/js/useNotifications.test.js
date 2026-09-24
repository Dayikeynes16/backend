import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * El motor de la isla, portado del hub con sus mismas pruebas. Corre en `node`
 * a propósito: el singleton no debe tocar `window` ni `route` al importarse
 * (sólo al usarse), y esta importación lo comprueba.
 */

vi.mock('@/lib/chime.js', () => ({ playChime: vi.fn() }));

const {
    createNotificationCenter, useNotifications, AUTO_DISMISS_MS, COALESCE_MS, POLL_LIVE_MS, POLL_FALLBACK_MS,
} = await import('@/composables/useNotifications.js');

/** El `route` de Ziggy, de mentira: deja ver qué ruta se eligió y con qué slug. */
const ctx = (role) => ({ role, slug: 'el-toro', route: (name, slug) => `/${slug}/${name}` });

const n = (id, level, extra = {}) => ({
    id, level, type: 'sale.cancellation.requested', title: `T${id}`, body: `B${id}`,
    read_at: null, created_at: `2026-09-23T15:00:0${id.length}Z`, ...extra,
});

function setup({ active = true, live = true } = {}) {
    let list = [];
    let listResult = null; // para forzar errores
    const api = {
        list: vi.fn(async () => listResult ?? ({
            ok: true,
            data: { notifications: list.map((x) => ({ ...x })), unread_count: list.filter((x) => !x.read_at).length },
        })),
        markRead: vi.fn(async () => ({ ok: true, data: { unread_count: 0 } })),
        markAllRead: vi.fn(async () => ({ ok: true, data: { unread_count: 0 } })),
    };
    const notifier = { native: vi.fn(), attention: vi.fn() };
    const off = vi.fn();
    let onSignal = null;
    const listenUser = vi.fn(async (_id, cb) => { onSignal = cb; return off; });
    const chime = vi.fn();
    const state = { active, live };
    const center = createNotificationCenter({
        api, notifier, listenUser, chime,
        isLive: () => state.live,
        isWindowActive: () => state.active,
    });
    return {
        center, api, notifier, off, chime, listenUser, state,
        setList: (l) => { list = l; },
        failList: (r) => { listResult = r; },
        fire: () => onSignal({ id: 'x', type: 'App\\Notifications\\Whatever' }),
    };
}

describe('createNotificationCenter', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('la primera lectura no anuncia: sólo llena el contador', async () => {
        const t = setup();
        t.setList([n('a', 'action'), n('b', 'important')]);
        await t.center.start({ id: 7, role: 'admin-sucursal' });

        expect(t.center.items.value).toHaveLength(2);
        expect(t.center.unreadCount.value).toBe(2);
        expect(t.center.current.value).toBeNull();
        expect(t.chime).not.toHaveBeenCalled();
        expect(t.notifier.native).not.toHaveBeenCalled();
        expect(t.listenUser).toHaveBeenCalledWith(7, expect.any(Function));
    });

    it('con la ventana oculta, la semilla sí enciende el parpadeo si hay un action pendiente', async () => {
        const t = setup({ active: false });
        t.setList([n('a', 'action')]);
        await t.center.start({ id: 7 });
        expect(t.notifier.attention).toHaveBeenLastCalledWith(true);
    });

    it('el evento es una señal: se coalesce y se relee la bandeja', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.api.list.mockClear();

        t.setList([n('a', 'action')]);
        t.fire(); t.fire(); t.fire();
        await vi.advanceTimersByTimeAsync(COALESCE_MS);

        expect(t.api.list).toHaveBeenCalledTimes(1);
        expect(t.center.current.value.id).toBe('a');
        expect(t.chime).toHaveBeenCalledWith('action');
    });

    it('con la ventana activa no hay notificación nativa; oculta, sí', async () => {
        const t = setup();
        await t.center.start({ id: 7 });

        t.setList([n('a', 'action')]);
        await t.center.load();
        expect(t.notifier.native).not.toHaveBeenCalled();

        t.state.active = false;
        t.setList([n('a', 'action'), n('bb', 'important')]);
        await t.center.load();
        expect(t.notifier.native).toHaveBeenCalledWith({ id: 'bb', title: 'Tbb', body: 'Bbb' });
    });

    it('un info sólo late: ni isla, ni sonido, ni notificación', async () => {
        const t = setup({ active: false });
        await t.center.start({ id: 7 });
        t.setList([n('a', 'info')]);
        await t.center.load();

        expect(t.center.pulse.value).toBe(1);
        expect(t.center.current.value).toBeNull();
        expect(t.chime).not.toHaveBeenCalled();
        expect(t.notifier.native).not.toHaveBeenCalled();
    });

    it('un aviso nuevo que ya llega leído no se anuncia', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action', { read_at: '2026-09-23T15:10:00Z' })]);
        await t.center.load();
        expect(t.center.current.value).toBeNull();
    });

    it('important se recoge solo a los 6 s, y el puntero encima lo pausa', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'important')]);
        await t.center.load();
        expect(t.center.current.value.id).toBe('a');

        t.center.pauseAuto();
        await vi.advanceTimersByTimeAsync(AUTO_DISMISS_MS + 1000);
        expect(t.center.current.value.id).toBe('a');

        t.center.resumeAuto();
        await vi.advanceTimersByTimeAsync(AUTO_DISMISS_MS);
        expect(t.center.current.value).toBeNull();
    });

    it('action se queda hasta que alguien decide', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action')]);
        await t.center.load();
        await vi.advanceTimersByTimeAsync(AUTO_DISMISS_MS * 3);
        expect(t.center.current.value.id).toBe('a');
    });

    it('la cola pone el action primero y avanza al descartar', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('i', 'important'), n('aa', 'action')]);
        await t.center.load();

        expect(t.center.current.value.id).toBe('aa');
        expect(t.center.queue.value.map((x) => x.id)).toEqual(['i']);
        t.center.dismiss();
        expect(t.center.current.value.id).toBe('i');
    });

    it('marcar leído es optimista y se corrige con lo que diga la nube', async () => {
        const t = setup();
        t.setList([n('a', 'action'), n('b', 'info')]);
        await t.center.start({ id: 7 });
        t.api.markRead.mockResolvedValueOnce({ ok: true, data: { unread_count: 5 } });

        const pending = t.center.markRead('a');
        expect(t.center.unreadCount.value).toBe(1);
        expect(t.center.items.value[0].read_at).not.toBeNull();
        await pending;
        expect(t.center.unreadCount.value).toBe(5);
    });

    it('si marcar falla, se revierte', async () => {
        const t = setup();
        t.setList([n('a', 'action')]);
        await t.center.start({ id: 7 });
        t.api.markRead.mockResolvedValueOnce({ ok: false, error: 'server' });

        await t.center.markRead('a');
        expect(t.center.unreadCount.value).toBe(1);
        expect(t.center.items.value[0].read_at).toBeNull();
    });

    it('marcar todo es optimista y se revierte si falla', async () => {
        const t = setup();
        t.setList([n('a', 'action'), n('b', 'info')]);
        await t.center.start({ id: 7 });
        t.api.markAllRead.mockResolvedValueOnce({ ok: false, error: 'server' });

        const pending = t.center.markAllRead();
        expect(t.center.unreadCount.value).toBe(0);
        await pending;
        expect(t.center.unreadCount.value).toBe(2);
        expect(t.center.items.value.every((x) => x.read_at === null)).toBe(true);
    });

    it('sin conexión conserva lo último y no deja marcar', async () => {
        const t = setup();
        t.setList([n('a', 'action')]);
        await t.center.start({ id: 7 });
        const synced = t.center.lastSyncedAt.value;

        t.failList({ ok: false, error: 'offline', status: 0 });
        await t.center.load();
        expect(t.center.online.value).toBe(false);
        expect(t.center.items.value).toHaveLength(1);
        expect(t.center.lastSyncedAt.value).toBe(synced);

        await t.center.markRead('a');
        expect(t.api.markRead).not.toHaveBeenCalled();
    });

    it('review marca, recoge y devuelve el destino', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action')]);
        await t.center.load();

        const to = t.center.review(t.center.current.value, ctx('admin-sucursal'));
        expect(to).toBe('/el-toro/sucursal.cancelaciones.index');
        expect(t.center.current.value).toBeNull();
        expect(t.api.markRead).toHaveBeenCalledWith('a');
    });

    it('abrir la bandeja guarda el action; al cerrarla sin leerlo vuelve a anunciarse', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action'), n('bb', 'important')]);
        await t.center.load();

        t.center.openTray();
        expect(t.center.current.value).toBeNull();
        expect(t.center.queue.value.map((x) => x.id)).toEqual(['a']); // el important se descarta

        t.center.closeTray();
        expect(t.center.current.value.id).toBe('a');
    });

    it('con la bandeja abierta no se anuncia nada', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.center.openTray();
        t.setList([n('a', 'action'), n('bb', 'important')]);
        await t.center.load();

        expect(t.center.current.value).toBeNull();
        expect(t.center.queue.value.map((x) => x.id)).toEqual(['a']);
    });

    it('openFromNative abre la bandeja resaltando ese aviso', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.center.openFromNative('a');
        expect(t.center.trayOpen.value).toBe(true);
        expect(t.center.highlightId.value).toBe('a');
    });

    it('sondea a 20 s con socket vivo y a 4 s sin él', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.api.list.mockClear();

        await vi.advanceTimersByTimeAsync(POLL_LIVE_MS - 1);
        expect(t.api.list).not.toHaveBeenCalled();
        t.state.live = false;
        await vi.advanceTimersByTimeAsync(1);
        expect(t.api.list).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(POLL_FALLBACK_MS);
        expect(t.api.list).toHaveBeenCalledTimes(2);
    });

    it('stop suelta el canal, para el sondeo y vacía todo', async () => {
        const t = setup();
        t.setList([n('a', 'action')]);
        await t.center.start({ id: 7 });
        t.api.list.mockClear();

        t.center.stop();
        expect(t.off).toHaveBeenCalled();
        expect(t.center.items.value).toEqual([]);
        expect(t.center.unreadCount.value).toBe(0);
        expect(t.notifier.attention).toHaveBeenLastCalledWith(false);

        await vi.advanceTimersByTimeAsync(POLL_LIVE_MS * 2);
        expect(t.api.list).not.toHaveBeenCalled();
    });
    it('ensureStarted con el mismo usuario no reinicia: la isla se remonta en cada navegación', async () => {
        const t = setup();
        t.setList([n('a', 'action')]);
        await t.center.ensureStarted({ id: 7 });
        await t.center.ensureStarted({ id: 7 });

        expect(t.api.list).toHaveBeenCalledTimes(1);
        expect(t.listenUser).toHaveBeenCalledTimes(1);
        expect(t.off).not.toHaveBeenCalled();
    });

    it('ensureStarted en plena primera lectura tampoco reinicia', async () => {
        const t = setup();
        const first = t.center.ensureStarted({ id: 7 });
        const second = t.center.ensureStarted({ id: 7 });
        await Promise.all([first, second]);

        expect(t.api.list).toHaveBeenCalledTimes(1);
        expect(t.listenUser).toHaveBeenCalledTimes(1);
    });

    it('ensureStarted con otro usuario suelta el anterior y arranca de nuevo', async () => {
        const t = setup();
        await t.center.ensureStarted({ id: 7 });
        await t.center.ensureStarted({ id: 8 });

        expect(t.off).toHaveBeenCalledTimes(1);
        expect(t.api.list).toHaveBeenCalledTimes(2);
        expect(t.listenUser).toHaveBeenLastCalledWith(8, expect.any(Function));
    });

    it('tras stop, ensureStarted con el mismo usuario vuelve a arrancar', async () => {
        const t = setup();
        await t.center.ensureStarted({ id: 7 });
        t.center.stop();
        await t.center.ensureStarted({ id: 7 });
        expect(t.api.list).toHaveBeenCalledTimes(2);
    });

    it('settle recoge el anuncio actual, lo saca de la cola, lo da por leído y relee', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action', { type: 'agenda.reminder.due' }), n('bb', 'action')]);
        await t.center.load();
        expect(t.center.current.value.id).toBe('a');
        t.api.list.mockClear();

        t.center.settle(t.center.current.value);
        // Pasa el siguiente: el recordatorio ya no está ni anunciándose ni en cola.
        expect(t.center.current.value.id).toBe('bb');
        expect(t.center.queue.value.map((x) => x.id)).toEqual([]);
        expect(t.center.items.value.find((x) => x.id === 'a').read_at).not.toBeNull();
        expect(t.center.unreadCount.value).toBe(1);
        // El backend ya lo marcó: no se pide marcarlo otra vez, sólo se relee.
        expect(t.api.markRead).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(COALESCE_MS);
        expect(t.api.list).toHaveBeenCalledTimes(1);
    });

    it('settle de uno que sólo esperaba en la cola lo quita sin tocar el actual', async () => {
        const t = setup();
        await t.center.start({ id: 7 });
        t.setList([n('a', 'action'), n('bb', 'action', { type: 'agenda.reminder.due' })]);
        await t.center.load();
        expect(t.center.current.value.id).toBe('a');

        t.center.settle({ id: 'bb' });
        expect(t.center.current.value.id).toBe('a');
        expect(t.center.queue.value).toEqual([]);
    });
});

describe('useNotifications', () => {
    it('es un solo centro por aplicación y crearlo no toca el navegador', () => {
        expect(() => useNotifications()).not.toThrow();
        expect(useNotifications()).toBe(useNotifications());
    });
});
