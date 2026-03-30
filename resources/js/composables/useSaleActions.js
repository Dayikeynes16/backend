import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

export function useSaleActions(statusRoute) {
    const processing = ref(false);

    function changeStatus(saleId, newStatus, cancelReason = null, options = {}) {
        processing.value = true;

        const data = { status: newStatus };
        if (cancelReason) {
            data.cancel_reason = cancelReason;
        }

        return new Promise((resolve) => {
            router.patch(statusRoute.replace('__SALE__', saleId), data, {
                preserveScroll: true,
                onSuccess: () => {
                    options.onSuccess?.();
                    resolve(true);
                },
                onError: () => resolve(false),
                onFinish: () => {
                    processing.value = false;
                    options.onFinish?.();
                },
            });
        });
    }

    const pauseSale = (saleId, options = {}) =>
        changeStatus(saleId, 'pending', null, options);

    const reactivateSale = (saleId, options = {}) =>
        changeStatus(saleId, 'active', null, options);

    const cancelSale = (saleId, reason, options = {}) =>
        changeStatus(saleId, 'cancelled', reason, options);

    const reopenSale = (saleId, options = {}) =>
        changeStatus(saleId, 'active', null, options);

    return {
        processing,
        changeStatus,
        pauseSale,
        reactivateSale,
        cancelSale,
        reopenSale,
    };
}
