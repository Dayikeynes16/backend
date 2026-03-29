import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

export function useSaleLock(branchId, userId, lockRoute, unlockRoute, heartbeatRoute) {
    const lockedSales = ref({}); // { saleId: { by: userId, name: userName } }
    let heartbeatInterval = null;
    let currentLockedSaleId = null;
    let handleBeforeUnload = null;

    // Lock a sale when selected
    const lockSale = async (saleId) => {
        if (currentLockedSaleId === saleId) return true;

        // Unlock previous (best-effort — backend also enforces single-lock)
        if (currentLockedSaleId) {
            await unlockSale();
        }

        try {
            const url = lockRoute.replace('__SALE__', saleId);
            await axios.post(url);
            currentLockedSaleId = saleId;
            // Remove from lockedSales in case it was stale or we took over an expired lock
            delete lockedSales.value[saleId];
            startHeartbeat(saleId);
            return true;
        } catch (e) {
            if (e.response?.status === 409) {
                const data = e.response.data;
                lockedSales.value[saleId] = { by: data.locked_by, name: data.locked_by_name };
                return false;
            }
            return true; // On other errors, allow operation
        }
    };

    const unlockSale = async () => {
        if (!currentLockedSaleId) return;

        const saleId = currentLockedSaleId;
        stopHeartbeat();
        currentLockedSaleId = null;

        try {
            const url = unlockRoute.replace('__SALE__', saleId);
            await axios.post(url);
        } catch (e) {
            // Silently fail — backend auto-releases via expiry or when user locks another sale
        }
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

    // Listen for lock/unlock events via Echo + setup beforeunload
    onMounted(() => {
        if (branchId && window.Echo) {
            window.Echo.private(`sucursal.${branchId}`)
                .listen('SaleLocked', (e) => {
                    // Ignore own lock events — only track other users
                    if (e.locked_by === userId) return;
                    lockedSales.value[e.sale_id] = { by: e.locked_by, name: e.locked_by_name };
                })
                .listen('SaleUnlocked', (e) => {
                    delete lockedSales.value[e.sale_id];
                });
        }

        // Best-effort unlock on page close / refresh
        handleBeforeUnload = () => {
            if (currentLockedSaleId) {
                const url = unlockRoute.replace('__SALE__', currentLockedSaleId);
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (token) {
                    const formData = new FormData();
                    formData.append('_token', token);
                    navigator.sendBeacon(url, formData);
                }
            }
        };
        window.addEventListener('beforeunload', handleBeforeUnload);
    });

    // Cleanup on unmount
    onUnmounted(() => {
        unlockSale();
        stopHeartbeat();
        if (handleBeforeUnload) {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            handleBeforeUnload = null;
        }
        if (branchId && window.Echo) {
            window.Echo.private(`sucursal.${branchId}`)
                .stopListening('SaleLocked')
                .stopListening('SaleUnlocked');
        }
    });

    return {
        lockSale,
        unlockSale,
        isLockedByOther,
        lockedByName,
        lockedSales,
    };
}
