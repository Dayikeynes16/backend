import { computed, reactive, watch } from 'vue';

const storageKey = (branchId) => `cart:${branchId}`;

function load(branchId) {
    try {
        const raw = localStorage.getItem(storageKey(branchId));
        if (!raw) return { items: [], cart_note: '', updated_at: Date.now() };
        const parsed = JSON.parse(raw);
        // 90-day TTL
        if (parsed.updated_at && Date.now() - parsed.updated_at > 90 * 24 * 60 * 60 * 1000) {
            return { items: [], cart_note: '', updated_at: Date.now() };
        }
        return parsed;
    } catch {
        return { items: [], cart_note: '', updated_at: Date.now() };
    }
}

const states = new Map();

export function useCart(branchId) {
    if (!states.has(branchId)) {
        const state = reactive(load(branchId));
        watch(
            () => state,
            (v) => {
                try {
                    localStorage.setItem(storageKey(branchId), JSON.stringify({ ...v, updated_at: Date.now() }));
                } catch {
                    // localStorage disabled or full
                }
            },
            { deep: true },
        );
        states.set(branchId, state);
    }

    const state = states.get(branchId);

    const addItem = (item) => {
        // Merge if same product + same presentation + same notes (typical "add again")
        const existing = state.items.find(
            (i) =>
                i.product_id === item.product_id &&
                i.presentation_id === item.presentation_id &&
                (i.notes || '') === (item.notes || ''),
        );

        if (existing) {
            existing.quantity = Number((Number(existing.quantity) + Number(item.quantity)).toFixed(3));
        } else {
            state.items.push({ ...item, line_id: Date.now() + Math.random() });
        }
    };

    const updateItem = (lineId, updates) => {
        const item = state.items.find((i) => i.line_id === lineId);
        if (item) Object.assign(item, updates);
    };

    const removeItem = (lineId) => {
        const idx = state.items.findIndex((i) => i.line_id === lineId);
        if (idx !== -1) state.items.splice(idx, 1);
    };

    const clear = () => {
        state.items.splice(0);
        state.cart_note = '';
    };

    const setNote = (note) => {
        state.cart_note = note;
    };

    const subtotal = computed(() =>
        state.items.reduce((sum, i) => sum + Number(i.quantity) * Number(i.unit_price), 0),
    );

    const count = computed(() => state.items.length);

    const totalItems = computed(() =>
        state.items.reduce((sum, i) => sum + Number(i.quantity), 0),
    );

    return {
        state,
        addItem,
        updateItem,
        removeItem,
        clear,
        setNote,
        subtotal,
        count,
        totalItems,
    };
}
