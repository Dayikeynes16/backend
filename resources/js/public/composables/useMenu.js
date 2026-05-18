import { ref } from 'vue';
import { createApi } from '../api.js';
import { useBranding } from './useBranding.js';

export function useMenu(tenantSlug, branchId) {
    const branch = ref(null);
    const categories = ref([]);
    const products = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const { apply: applyBranding } = useBranding();

    const fetch = async () => {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await createApi(tenantSlug).get(`/branches/${branchId}/menu`);
            branch.value = data.branch;
            categories.value = data.categories;
            products.value = data.products;
            if (data.branding) applyBranding(data.branding);
        } catch (e) {
            error.value = e.response?.status === 404 ? 'not_found' : 'load_failed';
        } finally {
            loading.value = false;
        }
    };

    return { branch, categories, products, loading, error, fetch };
}
