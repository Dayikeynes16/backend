import { ref, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';

export function useSaleLock(branchId, lockRoute, unlockRoute, heartbeatRoute) {
    const lockedSales = ref({}); // { saleId: { by: userId, name: userName } }
    let heartbeatInterval = null;
    let currentLockedSaleId = null;

    // Lock a sale when selected
    const lockSale = async (saleId) => {
        if (currentLockedSaleId === saleId) return true;

        // Unlock previous
        if (currentLockedSaleId) {
            await unlockSale();
        }

        try {
            const url = lockRoute.replace('__SALE__', saleId);
            await axios.post(url);
            currentLockedSaleId = saleId;
            startHeartbeat(saleId);
            return true;
        } catch (e) {
            if (e.response?.status === 409) {
                const data = e.response.data;
                lockedSales.value[saleId] = { name: data.locked_by_name };
                return false;
            }
            return true; // On other errors, allow operation
        }
    };

    const unlockSale = async () => {
        if (!currentLockedSaleId) return;

        try {
            const url = unlockRoute.replace('__SALE__', currentLockedSaleId);
            await axios.post(url);
        } catch (e) {
            // Silently fail
        }

        stopHeartbeat();
        currentLockedSaleId = null;
    };

    const startHeartbeat = (saleId) => {
        stopHeartbeat();
        heartbeatInterval = setInterval(async () => {
            if (!currentLockedSaleId) return;
            try {
                const url = heartbeatRoute.replace('__SALE__', saleId);
                await axios.post(url);
            } catch (e) {
                // Silently fail
            }
        }, 60000); // Every minute
    };

    const stopHeartbeat = () => {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
    };

    const isLockedByOther = (saleId) => {
        return !!lockedSales.value[saleId];
    };

    const lockedByName = (saleId) => {
        return lockedSales.value[saleId]?.name || null;
    };

    // Listen for lock/unlock events via Echo
    onMounted(() => {
        if (!branchId || !window.Echo) return;

        window.Echo.private(`sucursal.${branchId}`)
            .listen('SaleLocked', (e) => {
                lockedSales.value[e.sale_id] = { by: e.locked_by, name: e.locked_by_name };
            })
            .listen('SaleUnlocked', (e) => {
                delete lockedSales.value[e.sale_id];
            });
    });

    // Unlock on unmount
    onUnmounted(() => {
        unlockSale();
        stopHeartbeat();
        if (branchId && window.Echo) {
            window.Echo.private(`sucursal.${branchId}`)
                .stopListening('SaleLocked')
                .stopListening('SaleUnlocked');
        }
    });

    // Unlock on page unload
    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', () => {
            if (currentLockedSaleId) {
                const url = unlockRoute.replace('__SALE__', currentLockedSaleId);
                navigator.sendBeacon(url);
            }
        });
    }

    return {
        lockSale,
        unlockSale,
        isLockedByOther,
        lockedByName,
        lockedSales,
    };
}
