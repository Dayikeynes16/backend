<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import CancelSaleDialog from '@/Components/CancelSaleDialog.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({ sales: Array, tenant: Object, branchId: Number, branchInfo: Object, paymentMethods: Array });

const allMethodLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };
const enabledMethods = computed(() =>
    (props.paymentMethods || ['cash', 'card', 'transfer']).map(id => ({ id, label: allMethodLabels[id] }))
);
const defaultMethod = computed(() => enabledMethods.value[0]?.id || 'cash');

const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value));
const showTicket = ref(false);
const showCancelRequest = ref(false);

// Real-time updates (same as Sucursal)
const { sales: queuedSales } = useSaleQueue(props.branchId);
watch(queuedSales, () => router.reload({ only: ['sales'], preserveScroll: true }), { deep: true });

let saleUpdateChannel = null;
onMounted(() => {
    if (!props.branchId || !window.Echo) return;
    saleUpdateChannel = window.Echo.private(`sucursal.${props.branchId}`);
    saleUpdateChannel.listen('SaleUpdated', () => router.reload({ only: ['sales'], preserveScroll: true }));
});
onUnmounted(() => { if (saleUpdateChannel) saleUpdateChannel.stopListening('SaleUpdated'); });

// Concurrency lock
const { lockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    usePage().props.auth.user.id,
    route('caja.sale.lock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
        showCancelRequest.value = false;
        paymentForm.reset();
        paymentForm.method = defaultMethod.value;
    }
};

// Helpers
const methodLabel = (m) => allMethodLabels[m] || m;
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m]);
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const unitLabel = (t) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[t] || t);
const timeAgo = (d) => { const diff = Math.floor((Date.now() - new Date(d)) / 1000); if (diff < 60) return 'ahora'; if (diff < 3600) return `${Math.floor(diff / 60)}m`; return `${Math.floor(diff / 3600)}h`; };
const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;

// --- Payment form (always visible) ---
const paymentForm = useForm({ method: 'cash', amount: '' });
const pendingAmount = computed(() => selected.value ? parseFloat(selected.value.amount_pending) : 0);
const enteredAmount = computed(() => parseFloat(paymentForm.amount) || 0);
const changeAmount = computed(() => Math.max(enteredAmount.value - pendingAmount.value, 0));
const hasPending = computed(() => pendingAmount.value > 0);

const submitPayment = () => {
    if (!selected.value || !hasPending.value) return;
    paymentForm.post(route('caja.payment.store', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { paymentForm.reset('amount'); paymentForm.method = defaultMethod.value; },
    });
};

// Cancel request
const cancelProcessing = ref(false);
const submitCancelRequest = (reason) => {
    cancelProcessing.value = true;
    router.post(route('caja.request-cancel', [props.tenant.slug, selected.value.id]), { cancel_request_reason: reason }, {
        preserveScroll: true,
        onSuccess: () => { showCancelRequest.value = false; },
        onFinish: () => { cancelProcessing.value = false; },
    });
};
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1></template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <!-- LEFT: Sales queue -->
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
                                <span v-if="isLockedByOther(sale.id)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                    {{ lockedByName(sale.id) || 'En uso' }}
                                </span>
                                <span v-else-if="sale.locked_by_user" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">
                                    {{ sale.locked_by_user.name }}
                                </span>
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

            <!-- RIGHT: Sale detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center"><p class="text-sm text-gray-400">Selecciona una venta</p></div>

                <template v-else>
                    <!-- Header -->
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

                    <!-- Scrollable content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-5">
                        <!-- Items table -->
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

                        <!-- Payments list -->
                        <div v-if="selected.payments?.length > 0">
                            <h3 class="mb-2 text-sm font-bold text-gray-700">Pagos</h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2.5">
                                    <span :class="methodColor(p.method)" class="text-sm font-semibold">{{ methodLabel(p.method) }}</span>
                                    <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Progress bar -->
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1.5">
                                <span class="font-medium text-gray-500">Progreso</span>
                                <span class="font-bold text-gray-900">{{ Math.round(paidPct(selected)) }}%</span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                                <div class="h-full rounded-full transition-all duration-500" :class="paidPct(selected) >= 100 ? 'bg-green-500' : 'bg-red-500'" :style="{ width: Math.max(paidPct(selected), 2) + '%' }" />
                            </div>
                        </div>

                        <!-- Cancel requested badge -->
                        <div v-if="selected.cancel_requested_at" class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                            <p class="text-sm font-semibold text-amber-800">Cancelacion solicitada</p>
                            <p class="mt-0.5 text-xs text-amber-600">Motivo: {{ selected.cancel_request_reason }}</p>
                        </div>
                    </div>

                    <!-- STICKY FOOTER: Cobro (identical to Sucursal) -->
                    <div v-if="hasPending" class="border-t-2 border-gray-200 bg-gray-50">
                        <div class="grid grid-cols-3 divide-x divide-gray-200 border-b border-gray-200">
                            <div class="px-4 py-3 text-center">
                                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Pendiente</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums text-amber-600">${{ pendingAmount.toFixed(2) }}</p>
                            </div>
                            <div class="px-4 py-3 text-center">
                                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Recibido</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums" :class="enteredAmount > 0 ? 'text-gray-900' : 'text-gray-300'">
                                    ${{ enteredAmount > 0 ? enteredAmount.toFixed(2) : '0.00' }}
                                </p>
                            </div>
                            <div class="px-4 py-3 text-center">
                                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Cambio</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums" :class="changeAmount > 0 ? 'text-green-600' : 'text-gray-300'">
                                    ${{ changeAmount.toFixed(2) }}
                                </p>
                            </div>
                        </div>
                        <form @submit.prevent="submitPayment" class="flex items-center gap-3 px-5 py-3">
                            <select v-model="paymentForm.method" class="w-36 rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                <option v-for="m in enabledMethods" :key="m.id" :value="m.id">{{ m.label }}</option>
                            </select>
                            <div class="relative flex-1">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" required
                                    :placeholder="pendingAmount.toFixed(2)"
                                    class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <button type="submit" :disabled="paymentForm.processing"
                                class="rounded-lg bg-red-600 px-8 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95 disabled:opacity-50">
                                Cobrar
                            </button>
                        </form>
                        <p v-if="paymentForm.errors.method" class="px-5 pb-2 text-xs text-red-600">{{ paymentForm.errors.method }}</p>
                        <p v-if="paymentForm.errors.amount" class="px-5 pb-2 text-xs text-red-600">{{ paymentForm.errors.amount }}</p>
                    </div>
                </template>
            </div>
        </div>

        <CancelSaleDialog v-if="showCancelRequest" :folio="selected?.folio" mode="request" :processing="cancelProcessing" @confirm="submitCancelRequest" @cancel="showCancelRequest = false" />
        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name"
            :branch-name="branchInfo?.name" :branch-address="branchInfo?.address" :branch-phone="branchInfo?.phone"
            :ticket-config="branchInfo?.ticket_config" @close="showTicket = false" />
        <FlashToast />
    </CajeroLayout>
</template>
