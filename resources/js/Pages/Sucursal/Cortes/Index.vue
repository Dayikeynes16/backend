<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ shifts: Object, filters: Object, tenant: Object });

const date = ref(props.filters?.date || '');

watch(date, (value) => {
    router.get(route('sucursal.cortes.index', props.tenant.slug), { date: value }, { preserveState: true, replace: true });
});

const formatDateTime = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head title="Historial de Cortes" />
    <SucursalLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Historial de Cortes</h2>
        </template>
        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <input v-model="date" type="date"
                            class="mb-4 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" />

                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Cajero</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Apertura</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Cierre</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Ventas</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Efectivo</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Tarjeta</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Transf.</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="s in shifts.data" :key="s.id">
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-200">{{ s.user?.name || '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ formatDateTime(s.opened_at) }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ formatDateTime(s.closed_at) }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-200">{{ s.sale_count }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-green-600 dark:text-green-400">${{ Number(s.total_cash).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-blue-600 dark:text-blue-400">${{ Number(s.total_card).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-purple-600 dark:text-purple-400">${{ Number(s.total_transfer).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-bold text-gray-900 dark:text-white">${{ Number(s.total_sales).toFixed(2) }}</td>
                                </tr>
                                <tr v-if="shifts.data.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No hay cortes registrados.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="shifts.last_page > 1" class="mt-4 flex justify-center gap-2">
                            <a v-for="link in shifts.links" :key="link.label" :href="link.url || '#'"
                                :class="['rounded px-3 py-1 text-sm', link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300', !link.url && 'pointer-events-none opacity-50']"
                                v-html="link.label" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SucursalLayout>
</template>
