<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import PaymentForm from '@/Components/PaymentForm.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ sales: Array, tenant: Object, branchId: Number, paymentMethods: Array });

const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value));
const showPayment = ref(false);
const showTicket = ref(false);
const showCancelRequest = ref(false);

const { lockSale, unlockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    route('caja.sale.lock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
        showPayment.value = false;
        showCancelRequest.value = false;
    }
};

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m]);
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const unitLabel = (t) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[t] || t);
const timeAgo = (d) => { const diff = Math.floor((Date.now() - new Date(d)) / 1000); if (diff < 60) return 'ahora'; if (diff < 3600) return `${Math.floor(diff / 60)}m`; return `${Math.floor(diff / 3600)}h`; };
const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;

// Cancel request
const cancelReasons = ['Venta duplicada', 'Producto equivocado', 'Cliente ya no quiso la compra', 'Error de captura'];
const cancelForm = useForm({ cancel_request_reason: '' });
const selectedReason = ref('');
const customReason = ref('');

const setReason = (reason) => {
    selectedReason.value = reason;
    cancelForm.cancel_request_reason = reason === 'otro' ? customReason.value : reason;
};

const submitCancelRequest = () => {
    if (selectedReason.value === 'otro') cancelForm.cancel_request_reason = customReason.value;
    cancelForm.post(route('caja.request-cancel', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { showCancelRequest.value = false; selectedReason.value = ''; customReason.value = ''; cancelForm.reset(); },
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
                    <div v-for="sale in sales" :key="sale.id" @click="selectSale(sale.id)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all',
                            selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' :
                            isLockedByOther(sale.id) ? 'ring-1 ring-amber-200 bg-amber-50/30 opacity-75' :
                            'ring-1 ring-gray-100 hover:ring-gray-200']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <div class="flex items-center gap-1.5">
                                <span v-if="isLockedByOther(sale.id)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">En uso por {{ lockedByName(sale.id) }}</span>
                                <span v-if="sale.cancel_requested_at" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Cancelacion solicitada</span>
                                <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                            </div>
                        </div>
                        <div class="mt-2.5 flex items-end justify-between">
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
                <div v-if="!selected" class="flex flex-1 items-center justify-center"><p class="text-sm text-gray-400">Selecciona una venta</p></div>
                <template v-else>
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                                <span :class="[originBadge(selected.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                    Ticket
                                </button>
                                <button v-if="!selected.cancel_requested_at" @click="showCancelRequest = !showCancelRequest" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                    Solicitar cancelacion
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Items -->
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

                        <!-- Payments -->
                        <div v-if="selected.payments?.length > 0">
                            <h3 class="mb-2 text-sm font-bold text-gray-700">Pagos</h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2.5">
                                    <span :class="methodColor(p.method)" class="text-sm font-semibold">{{ methodLabel(p.method) }}</span>
                                    <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Summary -->
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

                        <!-- Payment form with change -->
                        <PaymentForm v-if="showPayment" :sale="selected"
                            :payment-route="route('caja.payment.store', [tenant.slug, selected.id])"
                            :payment-methods="paymentMethods"
                            @success="showPayment = false" @cancel="showPayment = false" />

                        <!-- Cancel request -->
                        <div v-if="showCancelRequest" class="rounded-xl border-2 border-amber-200 bg-amber-50 p-5">
                            <h3 class="mb-3 text-sm font-bold text-amber-900">Solicitar Cancelacion</h3>
                            <p class="mb-4 text-xs text-amber-700">Tu solicitud sera revisada por el administrador de sucursal.</p>
                            <div class="space-y-2 mb-4">
                                <button v-for="reason in cancelReasons" :key="reason" type="button" @click="setReason(reason)"
                                    :class="['w-full rounded-lg px-4 py-2.5 text-left text-sm transition', selectedReason === reason ? 'bg-amber-200 font-semibold text-amber-900' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50']">
                                    {{ reason }}
                                </button>
                                <button type="button" @click="setReason('otro')"
                                    :class="['w-full rounded-lg px-4 py-2.5 text-left text-sm transition', selectedReason === 'otro' ? 'bg-amber-200 font-semibold text-amber-900' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50']">
                                    Otro motivo
                                </button>
                            </div>
                            <div v-if="selectedReason === 'otro'" class="mb-4">
                                <textarea v-model="customReason" rows="2" required placeholder="Describe el motivo..." class="block w-full rounded-lg border-amber-200 text-sm focus:border-amber-400 focus:ring-amber-300" />
                            </div>
                            <div class="flex gap-3">
                                <button @click="submitCancelRequest" :disabled="!selectedReason || (selectedReason === 'otro' && !customReason) || cancelForm.processing"
                                    class="rounded-lg bg-amber-600 px-5 py-2 text-sm font-bold text-white hover:bg-amber-700 disabled:opacity-50">Enviar solicitud</button>
                                <button @click="showCancelRequest = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                            </div>
                        </div>

                        <!-- Cancel requested badge -->
                        <div v-if="selected.cancel_requested_at" class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                            <p class="text-sm font-semibold text-amber-800">Cancelacion solicitada</p>
                            <p class="mt-0.5 text-xs text-amber-600">Motivo: {{ selected.cancel_request_reason }}</p>
                            <p class="mt-0.5 text-xs text-amber-600/70">{{ new Date(selected.cancel_requested_at).toLocaleString('es-MX') }}</p>
                        </div>
                    </div>

                    <!-- Sticky actions -->
                    <div v-if="parseFloat(selected.amount_pending) > 0" class="border-t border-gray-100 px-6 py-4">
                        <button @click="showPayment = !showPayment" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            Registrar Cobro
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name" @close="showTicket = false" />
        <FlashToast />
    </CajeroLayout>
</template>
