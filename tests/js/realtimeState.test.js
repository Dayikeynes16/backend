import { describe, it, expect, vi } from 'vitest';
import { isLive, isRecovering, trackConnection } from '@/lib/realtimeState';

/** Imitación mínima de `pusher.connection`: estado + bind/unbind + emit. */
function fakeConnection(initialState = 'initialized') {
    const handlers = new Map();
    return {
        state: initialState,
        bind(evt, fn) {
            if (!handlers.has(evt)) handlers.set(evt, []);
            handlers.get(evt).push(fn);
        },
        unbind(evt, fn) {
            const list = handlers.get(evt) ?? [];
            const i = list.indexOf(fn);
            if (i !== -1) list.splice(i, 1);
        },
        emitRaw(next) {
            this.state = next;
            for (const fn of handlers.get('state_change') ?? []) fn(next);
        },
        moveTo(next) {
            const previous = this.state;
            this.state = next;
            for (const fn of handlers.get('state_change') ?? []) fn({ previous, current: next });
        },
        listenerCount(evt) {
            return (handlers.get(evt) ?? []).length;
        },
    };
}

describe('isLive / isRecovering', () => {
    it('sólo "connected" cuenta como tiempo real', () => {
        expect(isLive('connected')).toBe(true);
        for (const s of ['connecting', 'unavailable', 'failed', 'disconnected', 'initialized']) {
            expect(isLive(s)).toBe(false);
        }
    });

    it('distingue lo que se está recuperando de lo que está caído', () => {
        expect(isRecovering('connecting')).toBe(true);
        expect(isRecovering('unavailable')).toBe(true);
        // 'failed' no se recupera solo: no debe pintarse como "reconectando".
        expect(isRecovering('failed')).toBe(false);
        expect(isRecovering('connected')).toBe(false);
    });
});

describe('trackConnection', () => {
    it('entrega el estado en el que ya estaba la conexión', () => {
        // Quien se engancha tarde —una segunda visita a la mesa, con el socket
        // ya conectado— no vería nunca un `state_change` y se quedaría creyendo
        // que no hay tiempo real.
        const conn = fakeConnection('connected');
        const seen = [];

        trackConnection(conn, (s) => seen.push(s));

        expect(seen).toEqual(['connected']);
    });

    it('sigue la caída y la recuperación del socket', () => {
        const conn = fakeConnection('connected');
        const seen = [];
        trackConnection(conn, (s) => seen.push(s));

        conn.moveTo('unavailable');
        conn.moveTo('connecting');
        conn.moveTo('connected');

        expect(seen).toEqual(['connected', 'unavailable', 'connecting', 'connected']);
        expect(isLive(seen.at(-1))).toBe(true);
    });

    it('acepta el estado como string suelto, no sólo como objeto de pusher', () => {
        const conn = fakeConnection();
        const onState = vi.fn();
        trackConnection(conn, onState);
        onState.mockClear();

        // Algunas versiones entregan el estado pelado en vez de {previous,current}.
        conn.emitRaw('connected');

        expect(onState).toHaveBeenCalledWith('connected');
    });

    it('deja de escuchar al soltar el seguimiento', () => {
        const conn = fakeConnection();
        const untrack = trackConnection(conn, () => {});

        expect(conn.listenerCount('state_change')).toBe(1);
        untrack();
        expect(conn.listenerCount('state_change')).toBe(0);
    });

    it('no revienta si no hay conexión que seguir', () => {
        // Pasa cuando Reverb no está configurado: window.Echo existe pero el
        // conector no llegó a crear la conexión.
        expect(() => trackConnection(null, () => {})()).not.toThrow();
        expect(() => trackConnection({}, () => {})()).not.toThrow();
    });
});
