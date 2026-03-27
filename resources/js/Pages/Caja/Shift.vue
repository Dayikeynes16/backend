<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
});

const formatTime = (iso) => {
    return new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

const closeShift = () => {
    if (confirm('¿Cerrar turno? El corte de caja se generará con los totales actuales. Esta acción no se puede deshacer.')) {
        router.patch(route('caja.shift.close', props.tenant.slug));
    }
};
</script>

<template>
    <Head title="Corte de Caja" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Corte de Caja</h2>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Turno abierto desde {{ formatTime(shift.opened_at) }}
                </span>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8 space-y-6">
                <!-- Totals -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6 space-y-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Resumen del turno</h3>

                        <div class="space-y-3">
                            <div class="flex justify-between border-b border-gray-100 pb-3 dark:border-gray-700">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Ventas cobradas</span>
                                <span class="font-semibold text-gray-900 dark:text-white">{{ totals.sale_count }}</span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-3 dark:border-gray-700">
                                <span class="text-sm text-green-600 dark:text-green-400">Efectivo</span>
                                <span class="font-semibold text-gray-900 dark:text-white">${{ totals.total_cash.toFixed(2) }}</span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-3 dark:border-gray-700">
                                <span class="text-sm text-blue-600 dark:text-blue-400">Tarjeta</span>
                                <span class="font-semibold text-gray-900 dark:text-white">${{ totals.total_card.toFixed(2) }}</span>
                            </div>
                            <div class="flex justify-between border-b border-gray-100 pb-3 dark:border-gray-700">
                                <span class="text-sm text-purple-600 dark:text-purple-400">Transferencia</span>
                                <span class="font-semibold text-gray-900 dark:text-white">${{ totals.total_transfer.toFixed(2) }}</span>
                            </div>
                            <div class="flex justify-between pt-2">
                                <span class="text-lg font-bold text-gray-900 dark:text-white">Total</span>
                                <span class="text-2xl font-bold text-gray-900 dark:text-white">${{ totals.total_sales.toFixed(2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Close button -->
                <div class="flex justify-center">
                    <DangerButton @click="closeShift" class="px-8 py-3 text-base">
                        Cerrar Turno
                    </DangerButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
