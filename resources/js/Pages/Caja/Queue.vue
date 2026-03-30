<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { onMounted, ref, computed } from 'vue';

const props = defineProps({
    pendingSales: Array,
    branchId: Number,
    tenant: Object,
});

const { sales, initSales, removeSale } = useSaleQueue(props.branchId);

onMounted(() => {
    initSales(props.pendingSales);
});

const now = ref(new Date());
setInterval(() => now.value = new Date(), 1000);

const elapsed = (arrivedAt) => {
    const diff = Math.floor((now.value - new Date(arrivedAt)) / 1000);
    const mins = Math.floor(diff / 60);
    const secs = diff % 60;
    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
};

const paymentLabel = (method) => ({
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
}[method] || method);

const unitLabel = (type) => ({
    kg: 'kg',
    piece: 'pz',
    cut: 'pz',
}[type] || type);

const processing = ref(null);

const completeSale = (sale) => {
    processing.value = sale.id;
    router.patch(route('caja.sales.complete', [props.tenant.slug, sale.id]), {}, {
        preserveScroll: true,
        onSuccess: () => {
            removeSale(sale.id);
            processing.value = null;
        },
        onError: () => {
            processing.value = null;
        },
    });
};

const pendingCount = computed(() => sales.value.length);
</script>

<template>
    <Head title="Cola de Ventas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Cola de Ventas
                </h2>
                <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-sm font-medium text-indigo-800">
                    {{ pendingCount }} pendiente{{ pendingCount !== 1 ? 's' : '' }}
                </span>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">

                <!-- Empty state -->
                <div v-if="sales.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>
                    <p class="mt-4 text-lg font-medium text-gray-500">
                        Sin ventas pendientes
                    </p>
                    <p class="mt-1 text-sm text-gray-400">
                        Las ventas nuevas aparecen aqui automaticamente.
                    </p>
                </div>

                <!-- Sales queue -->
                <div class="space-y-4">
                    <TransitionGroup name="sale">
                        <div
                            v-for="sale in sales"
                            :key="sale.id"
                            class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200"
                        >
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-lg font-bold text-gray-900">
                                        {{ sale.folio }}
                                    </span>
                                    <span
                                        :class="{
                                            'bg-green-100 text-green-800': sale.payment_method === 'cash',
                                            'bg-blue-100 text-blue-800': sale.payment_method === 'card',
                                            'bg-purple-100 text-purple-800': sale.payment_method === 'transfer',
                                        }"
                                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    >
                                        {{ paymentLabel(sale.payment_method) }}
                                    </span>
                                </div>
                                <span class="text-sm text-gray-500">
                                    hace {{ elapsed(sale.arrived_at || sale.created_at) }}
                                </span>
                            </div>

                            <!-- Items -->
                            <div class="px-5 py-3">
                                <div
                                    v-for="item in sale.items"
                                    :key="item.id"
                                    class="flex items-center justify-between py-1.5"
                                >
                                    <div class="text-sm text-gray-700">
                                        <span class="font-medium">{{ item.product_name }}</span>
                                        <span class="ml-2 text-gray-400">
                                            {{ item.quantity }} {{ unitLabel(item.unit_type) }}
                                            × ${{ item.unit_price.toFixed(2) }}
                                        </span>
                                    </div>
                                    <span class="text-sm font-medium text-gray-900">
                                        ${{ item.subtotal.toFixed(2) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-between bg-gray-50 px-5 py-3">
                                <span class="text-xl font-bold text-gray-900">
                                    ${{ sale.total.toFixed(2) }}
                                </span>
                                <button
                                    @click="completeSale(sale)"
                                    :disabled="processing === sale.id"
                                    class="rounded-lg bg-green-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50"
                                >
                                    {{ processing === sale.id ? 'Cobrando...' : 'Cobrar' }}
                                </button>
                            </div>
                        </div>
                    </TransitionGroup>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.sale-enter-active {
    transition: all 0.4s ease-out;
}
.sale-leave-active {
    transition: all 0.3s ease-in;
}
.sale-enter-from {
    opacity: 0;
    transform: translateY(-20px);
}
.sale-leave-to {
    opacity: 0;
    transform: translateX(30px);
}
</style>
