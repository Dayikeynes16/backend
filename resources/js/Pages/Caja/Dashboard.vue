<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    totals: Object,
    recentSales: Array,
    shiftOpened: String,
    tenant: Object,
});

const paymentLabel = (method) => ({
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
}[method] || method);

const formatTime = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head title="Dashboard del Dia" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Dashboard del Dia</h2>
                <span v-if="shiftOpened" class="text-sm text-gray-500 dark:text-gray-400">
                    Turno desde {{ formatTime(shiftOpened) }}
                </span>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Stats cards -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total del turno</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">${{ totals.total_sales.toFixed(2) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Transacciones</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ totals.sale_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Promedio por venta</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">${{ totals.average.toFixed(2) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Desglose</p>
                        <div class="mt-1 space-y-0.5 text-sm">
                            <p class="text-green-600 dark:text-green-400">Efectivo: ${{ totals.total_cash.toFixed(2) }}</p>
                            <p class="text-blue-600 dark:text-blue-400">Tarjeta: ${{ totals.total_card.toFixed(2) }}</p>
                            <p class="text-purple-600 dark:text-purple-400">Transf: ${{ totals.total_transfer.toFixed(2) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Recent sales -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Ventas recientes</h3>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Folio</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Metodo</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Total</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Hora</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="sale in recentSales" :key="sale.id">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-200">{{ sale.folio }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ paymentLabel(sale.payment_method) }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-gray-200">${{ sale.total.toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">{{ formatTime(sale.completed_at) }}</td>
                                </tr>
                                <tr v-if="recentSales.length === 0">
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No hay ventas cobradas en este turno.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
