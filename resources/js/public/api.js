import axios from 'axios';

export function createApi(tenantSlug) {
    return axios.create({
        baseURL: `/api/public/${tenantSlug}`,
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        timeout: 15000,
    });
}
