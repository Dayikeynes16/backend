<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ sales: Object, filters: Object, tenant: Object });

const date = ref(props.filters?.date || '');
watch(date, (v) => {
    router.get(route('caja.historial', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const statusBadge = (s) => ({
    active: { l: 'Activa', c: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { l: 'Pendiente', c: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { l: 'Cobrada', c: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { l: 'Cancelada', c: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { l: s, c: 'bg-gray-100 text-gray-600' });

const selected = ref(null);
</script>

<template>
    <Head title="Historial" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mis Ventas Cobradas</h1></template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4">
                    <DatePicker v-model="date" />
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in sales.data" :key="sale.id" @click="selected = sale"
                        :class="['cursor-pointer rounded-xl p-4 transition-all', selected?.id === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <span :class="[statusBadge(sale.status).c, 'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(sale.status).l }}</span>
                        </div>
                        <div class="mt-2 flex items-end justify-between">
                            <p class="text-lg font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                            <span class="text-xs text-gray-400">{{ new Date(sale.created_at).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) }}</span>
                        </div>
                    </div>
                    <div v-if="sales.data.length === 0" class="py-16 text-center text-sm text-gray-400">Sin ventas.</div>
                </div>
                <div v-if="sales.last_page > 1" class="flex justify-center border-t border-gray-100 px-4 py-3">
                    <div class="flex gap-1">
                        <Link v-for="link in sales.links" :key="link.label" :href="link.url || '#'" :class="['rounded-lg px-3 py-1.5 text-xs font-medium', link.active ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']" v-html="link.label" />
                    </div>
                </div>
            </div>

            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center"><p class="text-sm text-gray-400">Selecciona una venta</p></div>
                <template v-else>
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                        <p class="text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX') }}</p>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <div class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                            <table class="min-w-full divide-y divide-gray-50">
                                <thead><tr class="bg-gray-50">
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Cant.</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="item in selected.items" :key="item.id">
                                        <td class="px-4 py-2.5 text-sm text-gray-900">{{ item.product_name }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="selected.payments?.length" class="space-y-1.5">
                            <div v-for="p in selected.payments" :key="p.id" class="flex justify-between rounded-lg bg-gray-50 px-4 py-2.5">
                                <span class="text-sm text-gray-600">{{ methodLabel(p.method) }}</span>
                                <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-5">
                            <div class="grid grid-cols-3 gap-4">
                                <div><p class="text-xs text-gray-400">Total</p><p class="text-lg font-bold text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pagado</p><p class="text-lg font-bold text-green-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pendiente</p><p class="text-lg font-bold" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </CajeroLayout>
</template>
