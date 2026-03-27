import { ref, onMounted, onUnmounted } from 'vue';

export function useSaleQueue(branchId) {
    const sales = ref([]);
    let channel = null;

    const playNotificationSound = () => {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();

            oscillator.connect(gain);
            gain.connect(ctx.destination);

            oscillator.frequency.value = 880;
            oscillator.type = 'sine';
            gain.gain.value = 0.3;

            oscillator.start();
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
            oscillator.stop(ctx.currentTime + 0.5);
        } catch (e) {
            // Audio not available
        }
    };

    const addSale = (sale) => {
        // Avoid duplicates
        if (sales.value.find(s => s.id === sale.id)) return;

        sales.value.unshift({ ...sale, arrived_at: new Date() });
        playNotificationSound();
    };

    const removeSale = (saleId) => {
        sales.value = sales.value.filter(s => s.id !== saleId);
    };

    const initSales = (initialSales) => {
        sales.value = initialSales.map(s => ({
            ...s,
            arrived_at: new Date(s.created_at),
        }));
    };

    onMounted(() => {
        if (!branchId || !window.Echo) return;

        channel = window.Echo.private(`sucursal.${branchId}`);
        channel.listen('NewExternalSale', (e) => {
            addSale(e.sale);
        });
    });

    onUnmounted(() => {
        if (channel) {
            channel.stopListening('NewExternalSale');
            window.Echo.leave(`sucursal.${branchId}`);
        }
    });

    return {
        sales,
        addSale,
        removeSale,
        initSales,
    };
}
