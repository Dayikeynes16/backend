import { ref } from 'vue';
import { createApi } from '../api.js';
import { useBranding } from './useBranding.js';

const cache = new Map();

export function useTenant(tenantSlug) {
    const tenant = ref(null);
    const branches = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const { apply: applyBranding } = useBranding();

    const fetch = async () => {
        if (cache.has(tenantSlug)) {
            const cached = cache.get(tenantSlug);
            tenant.value = cached.tenant;
            branches.value = cached.branches;
            if (cached.branding) applyBranding(cached.branding);
            return;
        }

        loading.value = true;
        error.value = null;
        try {
            const { data } = await createApi(tenantSlug).get('/');
            tenant.value = data.tenant;
            branches.value = data.branches;
            if (data.branding) applyBranding(data.branding);
            cache.set(tenantSlug, { tenant: data.tenant, branches: data.branches, branding: data.branding ?? null });
        } catch (e) {
            error.value = e.response?.status === 404 ? 'not_found' : 'load_failed';
        } finally {
            loading.value = false;
        }
    };

    return { tenant, branches, loading, error, fetch };
}
