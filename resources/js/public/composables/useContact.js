import { reactive, watch } from 'vue';

const KEY = 'web_contact_v1';

function load() {
    try {
        const raw = localStorage.getItem(KEY);
        if (!raw) return empty();
        const parsed = JSON.parse(raw);
        if (parsed.updated_at && Date.now() - parsed.updated_at > 90 * 24 * 60 * 60 * 1000) {
            return empty();
        }
        return parsed;
    } catch {
        return empty();
    }
}

function empty() {
    return {
        contact_name: '',
        contact_phone: '',
        last_address: '',
        last_lat: null,
        last_lng: null,
        updated_at: Date.now(),
    };
}

let instance = null;

export function useContact() {
    if (!instance) {
        instance = reactive(load());
        watch(
            () => instance,
            (v) => {
                try {
                    localStorage.setItem(KEY, JSON.stringify({ ...v, updated_at: Date.now() }));
                } catch {}
            },
            { deep: true },
        );
    }

    return instance;
}
