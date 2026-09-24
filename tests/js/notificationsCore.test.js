import { describe, it, expect } from 'vitest';
import {
    levelOf, diffNew, sortQueue, pruneQueue, groupItems, hasPendingAction, relativeTime, islandPlacement, islandRight,
    isAgendaReminder,
} from '@/lib/notificationsCore.js';

const n = (id, level, extra = {}) => ({
    id, level, title: `T${id}`, body: `B${id}`, read_at: null, created_at: '2026-09-23T15:00:00Z', ...extra,
});

describe('levelOf', () => {
    it('un nivel desconocido o ausente es info', () => {
        expect(levelOf(n('a', 'action'))).toBe('action');
        expect(levelOf(n('a', 'urgente'))).toBe('info');
        expect(levelOf({ id: 'a' })).toBe('info');
    });
});

describe('diffNew', () => {
    it('la semilla registra los ids pero no devuelve nada', () => {
        const seen = new Set();
        expect(diffNew(seen, [n('a', 'action'), n('b', 'info')], { seed: true })).toEqual([]);
        expect([...seen]).toEqual(['a', 'b']);
    });

    it('después, sólo devuelve lo no visto y no leído', () => {
        const seen = new Set(['a']);
        const fresh = diffNew(seen, [n('a', 'action'), n('b', 'important'), n('c', 'info', { read_at: '2026-09-23T15:01:00Z' })]);
        expect(fresh.map((x) => x.id)).toEqual(['b']);
        expect(seen.has('c')).toBe(true); // leído, pero ya visto: no se anunciará después
    });
});

describe('sortQueue', () => {
    it('action antes que important, y por antigüedad dentro del nivel', () => {
        const q = [
            n('i1', 'important', { created_at: '2026-09-23T15:00:00Z' }),
            n('a2', 'action', { created_at: '2026-09-23T15:05:00Z' }),
            n('a1', 'action', { created_at: '2026-09-23T15:01:00Z' }),
        ];
        expect(sortQueue(q).map((x) => x.id)).toEqual(['a1', 'a2', 'i1']);
    });
});

describe('pruneQueue', () => {
    it('saca lo que ya se leyó por otra vía o ya no está en la bandeja', () => {
        const q = [n('a', 'action'), n('b', 'important'), n('c', 'action')];
        const items = [n('a', 'action', { read_at: '2026-09-23T15:02:00Z' }), n('b', 'important')];
        expect(pruneQueue(q, items).map((x) => x.id)).toEqual(['b']);
    });
});

describe('groupItems', () => {
    const now = new Date(2026, 8, 23, 18, 0, 0); // 23-sep local, 18:00

    it('por atender = action sin leer; el resto por día', () => {
        const today = new Date(2026, 8, 23, 9, 0, 0).toISOString();
        const yesterday = new Date(2026, 8, 22, 23, 59, 0).toISOString();
        const g = groupItems([
            n('a', 'action', { created_at: yesterday }),
            n('b', 'action', { created_at: today, read_at: today }),
            n('c', 'info', { created_at: today }),
            n('d', 'important', { created_at: yesterday }),
        ], now);
        expect(g.pending.map((x) => x.id)).toEqual(['a']);
        expect(g.today.map((x) => x.id)).toEqual(['b', 'c']);
        expect(g.earlier.map((x) => x.id)).toEqual(['d']);
    });
});

describe('hasPendingAction', () => {
    it('sólo cuenta action sin leer', () => {
        expect(hasPendingAction([n('a', 'important'), n('b', 'action', { read_at: 'x' })])).toBe(false);
        expect(hasPendingAction([n('a', 'action')])).toBe(true);
    });
});

describe('relativeTime', () => {
    const now = Date.parse('2026-09-23T18:00:00Z');
    it('igual que la campana web', () => {
        expect(relativeTime('2026-09-23T17:59:40Z', now)).toBe('ahora');
        expect(relativeTime('2026-09-23T17:48:00Z', now)).toBe('hace 12 min');
        expect(relativeTime('2026-09-23T16:00:00Z', now)).toBe('hace 2 h');
        expect(relativeTime('2026-09-20T18:00:00Z', now)).toBe('hace 3 d');
        expect(relativeTime(now - 5 * 60000, now)).toBe('hace 5 min');
        expect(relativeTime(null, now)).toBe('');
    });
});

describe('islandPlacement', () => {
    const rect = (left, right) => ({ left, right, width: right - left });

    it('centrada cuando el centro del encabezado está libre (1280 px)', () => {
        const p = islandPlacement({ header: rect(240, 1280), title: rect(260, 410), group: rect(914, 1260) });
        expect(p.docked).toBe(false);
    });

    it('acoplada a la izquierda del grupo derecho cuando no cabe (880 px)', () => {
        const p = islandPlacement({ header: rect(240, 880), title: rect(260, 330), group: rect(514, 860) });
        expect(p).toEqual({ docked: true, right: 378, width: 640 });
    });

    it('acoplada si un título largo llega al centro', () => {
        const p = islandPlacement({ header: rect(240, 1280), title: rect(260, 740), group: rect(914, 1260) });
        expect(p.docked).toBe(true);
    });
});

describe('islandRight', () => {
    const placement = { docked: true, right: 378, width: 640 };

    it('en reposo se queda pegada al grupo derecho', () => {
        expect(islandRight(placement, 54)).toBe(378);
    });

    it('abierta se corre lo justo para no salirse por la izquierda', () => {
        expect(islandRight(placement, 340)).toBe(288); // 640 - 12 - 340
        expect(islandRight(placement, 380)).toBe(248);
    });

    it('nunca pega al borde derecho', () => {
        expect(islandRight({ docked: true, right: 378, width: 300 }, 380)).toBe(12);
    });
});

describe('isAgendaReminder', () => {
    it('sólo el recordatorio de agenda lleva las acciones Hecho / +30 min / Visto', () => {
        expect(isAgendaReminder({ type: 'agenda.reminder.due' })).toBe(true);
        expect(isAgendaReminder({ type: 'agenda.item.assigned' })).toBe(false);
        expect(isAgendaReminder({ type: 'sale.cancellation.requested' })).toBe(false);
        expect(isAgendaReminder(null)).toBe(false);
        expect(isAgendaReminder(undefined)).toBe(false);
    });
});
