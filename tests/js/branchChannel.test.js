import { describe, it, expect, beforeEach, vi } from 'vitest';
import { subscribeToBranch, watchSocketState, __resetBranchChannels } from '@/lib/branchChannel';

/**
 * El canal `sucursal.{id}` lo comparten tres consumidores en la mesa de trabajo.
 * Estas pruebas fijan la regla que faltaba: cada uno desengancha lo suyo, y el
 * canal sólo se abandona cuando se va el último.
 */

/** Imitación mínima de un canal privado de laravel-echo. */
function fakeChannel() {
    const listeners = new Map();
    return {
        listen(evt, fn) {
            if (!listeners.has(evt)) listeners.set(evt, []);
            listeners.get(evt).push(fn);
            return this;
        },
        stopListening(evt, fn) {
            const list = listeners.get(evt) ?? [];
            if (fn) {
                const i = list.indexOf(fn);
                if (i !== -1) list.splice(i, 1);
            } else {
                listeners.set(evt, []);
            }
            return this;
        },
        error(fn) {
            this._onError = fn;
            return this;
        },
        emit(evt, payload) {
            for (const fn of [...(listeners.get(evt) ?? [])]) fn(payload);
        },
        listenerCount(evt) {
            return (listeners.get(evt) ?? []).length;
        },
    };
}

function fakeEcho() {
    const channels = new Map();
    return {
        left: [],
        private(name) {
            if (!channels.has(name)) channels.set(name, fakeChannel());
            return channels.get(name);
        },
        leave(name) {
            this.left.push(name);
            channels.delete(name);
        },
        connector: { pusher: { connection: { state: 'connected', bind() {}, unbind() {} } } },
        _channels: channels,
    };
}

beforeEach(() => {
    __resetBranchChannels();
    global.window = { Echo: fakeEcho() };
});

describe('subscribeToBranch', () => {
    it('abre el canal una sola vez para varios consumidores', () => {
        subscribeToBranch(7, { NewExternalSale: () => {} });
        subscribeToBranch(7, { SaleLocked: () => {} });

        expect(window.Echo._channels.size).toBe(1);
        expect(window.Echo._channels.has('sucursal.7')).toBe(true);
    });

    it('desengancha sólo los handlers propios', () => {
        // El bug que motiva este módulo: useSaleQueue hacía Echo.leave() al
        // desmontarse y dejaba mudos a useSaleLock y a la propia página.
        const queue = vi.fn();
        const lock = vi.fn();

        const unsubQueue = subscribeToBranch(7, { NewExternalSale: queue });
        subscribeToBranch(7, { SaleLocked: lock });

        const channel = window.Echo.private('sucursal.7');
        unsubQueue();

        expect(channel.listenerCount('NewExternalSale')).toBe(0);
        expect(channel.listenerCount('SaleLocked')).toBe(1);

        channel.emit('SaleLocked', { sale_id: 1 });
        expect(lock).toHaveBeenCalledOnce();
        expect(queue).not.toHaveBeenCalled();
    });

    it('no abandona el canal mientras quede algún consumidor', () => {
        const unsubA = subscribeToBranch(7, { NewExternalSale: () => {} });
        subscribeToBranch(7, { SaleUpdated: () => {} });

        unsubA();
        expect(window.Echo.left).toEqual([]);
    });

    it('abandona el canal cuando se va el último', () => {
        const unsubA = subscribeToBranch(7, { NewExternalSale: () => {} });
        const unsubB = subscribeToBranch(7, { SaleUpdated: () => {} });

        unsubA();
        unsubB();

        expect(window.Echo.left).toEqual(['sucursal.7']);
    });

    it('desuscribirse dos veces no descuenta de más', () => {
        const unsubA = subscribeToBranch(7, { NewExternalSale: () => {} });
        subscribeToBranch(7, { SaleUpdated: () => {} });

        unsubA();
        unsubA();

        expect(window.Echo.left).toEqual([]);
    });

    it('mantiene canales de sucursales distintas separados', () => {
        subscribeToBranch(7, { NewExternalSale: () => {} });
        subscribeToBranch(9, { NewExternalSale: () => {} });

        expect(window.Echo._channels.size).toBe(2);
        expect(window.Echo._channels.has('sucursal.7')).toBe(true);
        expect(window.Echo._channels.has('sucursal.9')).toBe(true);
    });

    it('sin branchId o sin Echo devuelve un desuscriptor inofensivo', () => {
        expect(() => subscribeToBranch(null, { X: () => {} })()).not.toThrow();

        global.window = {};
        expect(() => subscribeToBranch(7, { X: () => {} })()).not.toThrow();
    });

    it('un fallo de autorización del canal deja de reportar "En vivo"', () => {
        // Sin esto el canal se queda mudo en silencio (sesión caducada) y la
        // pantalla seguiría diciendo que está en vivo.
        const seen = [];
        watchSocketState((s) => seen.push(s));
        subscribeToBranch(7, { NewExternalSale: () => {} });

        window.Echo.private('sucursal.7')._onError({});

        expect(seen.at(-1)).toBe('unavailable');
    });
});
