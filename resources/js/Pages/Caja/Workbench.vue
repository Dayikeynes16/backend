<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ sales: Array, tenant: Object, branchId: Number });

const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value));
const showPayment = ref(false);
const showTicket = ref(false);

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m] || 'text-gray-600');
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const unitLabel = (t) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[t] || t);
const timeAgo = (d) => { const diff = Math.floor((Date.now() - new Date(d)) / 1000); if (diff < 60) return 'ahora'; if (diff < 3600) return `${Math.floor(diff / 60)}m`; return `${Math.floor(diff / 3600)}h`; };
const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;

const paymentForm = useForm({ method: 'cash', amount: '' });
const submitPayment = () => {
    paymentForm.post(route('caja.payment.store', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { paymentForm.reset('amount'); showPayment.value = false; },
    });
};
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <CajeroLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1>
        </template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <!-- LEFT -->
            <div class="flex w-[360px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Ventas Activas</h2>
                    <p class="text-xs text-gray-400">{{ sales.length }} venta{{ sales.length !== 1 ? 's' : '' }}</p>
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in sales" :key="sale.id" @click="selectedId = sale.id; showPayment = false"
                        :class="['cursor-pointer rounded-xl p-4 transition-all', selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                        </div>
                        <div class="mt-2 flex items-end justify-between">
                            <div>
                                <p class="text-xl font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                                <p v-if="parseFloat(sale.amount_pending) > 0" class="mt-0.5 text-xs font-semibold text-amber-600">Pendiente: ${{ parseFloat(sale.amount_pending).toFixed(2) }}</p>
                                <p v-else class="mt-0.5 text-xs font-semibold text-green-600">Pagada</p>
                            </div>
                            <span class="text-xs text-gray-400">{{ timeAgo(sale.created_at) }}</span>
                        </div>
                    </div>
                    <div v-if="sales.length === 0" class="flex flex-col items-center py-20 text-center">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">Sin ventas activas</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <p class="text-sm text-gray-400">Selecciona una venta</p>
                </div>
                <template v-else>
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                                <span :class="[originBadge(selected.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                            </div>
                            <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                Ticket
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <div class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                            <table class="min-w-full divide-y divide-gray-50">
                                <thead><tr class="bg-gray-50">
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Cant.</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Precio</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="item in selected.items" :key="item.id">
                                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900">{{ item.product_name }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }} {{ unitLabel(item.unit_type) }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="selected.payments?.length > 0">
                            <h3 class="mb-2 text-sm font-bold text-gray-700">Pagos</h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2.5">
                                    <span :class="methodColor(p.method)" class="text-sm font-semibold">{{ methodLabel(p.method) }}</span>
                                    <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-5">
                            <div class="flex items-center justify-between text-sm mb-2">
                                <span class="text-gray-500">Progreso</span>
                                <span class="font-bold text-gray-900">{{ Math.round(paidPct(selected)) }}%</span>
                            </div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                                <div class="h-full rounded-full transition-all duration-500" :class="paidPct(selected) >= 100 ? 'bg-green-500' : 'bg-red-500'" :style="{ width: Math.max(paidPct(selected), 2) + '%' }" />
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-4">
                                <div><p class="text-xs text-gray-400">Total</p><p class="text-lg font-bold text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pagado</p><p class="text-lg font-bold text-green-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pendiente</p><p class="text-lg font-bold" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p></div>
                            </div>
                        </div>
                        <div v-if="showPayment" class="rounded-xl border-l-4 border-red-500 bg-white p-5 ring-1 ring-gray-100">
                            <form @submit.prevent="submitPayment" class="flex items-end gap-3">
                                <div class="w-36">
                                    <label class="text-xs font-medium text-gray-500">Metodo</label>
                                    <select v-model="paymentForm.method" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="transfer">Transferencia</option>
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <label class="text-xs font-medium text-gray-500">Monto</label>
                                    <div class="relative mt-1"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                    <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="selected.amount_pending" required placeholder="0.00" class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" /></div>
                                </div>
                                <button type="submit" :disabled="paymentForm.processing" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Cobrar</button>
                                <button type="button" @click="showPayment = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                            </form>
                        </div>
                    </div>
                    <div v-if="parseFloat(selected.amount_pending) > 0" class="border-t border-gray-100 px-6 py-4">
                        <button @click="showPayment = !showPayment" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            Registrar Pago
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name" @close="showTicket = false" />
        <FlashToast />
    </CajeroLayout>
</template>
