<script setup>
import { ref, computed, onMounted } from 'vue';
import SaleDetailModal from '@/Components/Clientes/SaleDetailModal.vue';
import GlobalPaymentDetailModal from '@/Components/Clientes/GlobalPaymentDetailModal.vue';

const props = defineProps({
    customerId: { type: Number, required: true },
    tenantSlug: { type: String, required: true },
    payments: { type: Object, default: null },
    stats: { type: Object, default: null },
    statsSeed: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    canRegisterPayment: { type: Boolean, default: false },
    paymentDisabledReason: { type: String, default: '' },
    products: { type: Array, default: () => [] },
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    saleItemEditReasonMode: { type: String, default: 'optional' },
});

const emit = defineEmits(['load', 'register-payment']);

onMounted(() => emit('load'));

const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));
const formatRelative = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    const diff = Math.floor((Date.now() - d) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} h`;
    if (diff < 86400 * 7) return `hace ${Math.floor(diff / 86400)} días`;
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};
const daysSince = (iso) => {
    if (!iso) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86400000));
};

const pendingSales = computed(() => props.payments?.pending_sales || []);
const movements = computed(() => props.payments?.recent_movements || []);

// Métricas útiles (no duplican el hero).
const data = computed(() => props.stats || props.statsSeed || {});

// `total_paid` = histórico cobrado al cliente (suma de amount_paid de
// todas las ventas no canceladas). Viene en stats y en statsSeed.
const totalPaidHistorical = computed(() => Number(data.value.total_paid || 0));

// Último pago: tomamos el primero de recent_movements (vienen desc).
const lastMovement = computed(() => movements.value[0] || null);

// Método más usado en los últimos movimientos cargados.
const methodCount = computed(() => {
    const counts = {};
    for (const m of movements.value) {
        const key = m.method || 'otro';
        counts[key] = (counts[key] || 0) + 1;
    }

    return counts;
});
const topMethod = computed(() => {
    const entries = Object.entries(methodCount.value);
    if (!entries.length) return null;
    const [method, count] = entries.sort((a, b) => b[1] - a[1])[0];

    return { method, count };
});

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const ageBadge = (days) => {
    if (days >= 30) return 'bg-red-100 text-red-700 ring-red-600/20';
    if (days >= 7) return 'bg-amber-100 text-amber-700 ring-amber-600/20';

    return 'bg-gray-100 text-gray-600 ring-gray-300/50';
};

const selectedSaleId = ref(null);
const selectedGlobalId = ref(null);
const openMovement = (m) => {
    if (m.type === 'global') selectedGlobalId.value = m.id;
    else selectedSaleId.value = m.sale_id;
};

const totalPending = computed(() => Number(props.payments?.total_owed ?? 0));

const refresh = () => emit('load');
</script>

<template>
    <div class="space-y-5">
        <!-- Ventas pendientes -->
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-bold text-gray-900">Ventas pendientes</h3>
                    <span v-if="pendingSales.length > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700 ring-1 ring-inset ring-red-600/20">{{ pendingSales.length }}</span>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-gray-500">Saldo total:
                        <b class="font-mono tabular-nums text-red-700">{{ money(totalPending) }}</b>
                    </span>
                    <button v-if="pendingSales.length > 0" type="button"
                        :disabled="!canRegisterPayment"
                        :title="paymentDisabledReason"
                        @click="$emit('register-payment')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Registrar cobro
                    </button>
                </div>
            </div>
            <div v-if="loading" class="flex items-center justify-center px-6 py-10 text-sm text-gray-400">
                <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                Cargando…
            </div>
            <div v-else-if="pendingSales.length === 0" class="px-6 py-8 text-center">
                <p class="text-sm font-semibold text-gray-700">Sin ventas pendientes</p>
                <p class="mt-1 text-xs text-gray-400">Este cliente está al corriente con sus pagos.</p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-5 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Folio</th>
                            <th class="px-5 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Antigüedad</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Total</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Pagado</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Pendiente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="sale in pendingSales" :key="sale.id" @click="selectedSaleId = sale.id"
                            class="cursor-pointer transition hover:bg-gray-50/60">
                            <td class="whitespace-nowrap px-5 py-3 text-sm font-bold text-gray-900">{{ sale.folio }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-sm">
                                <span :class="[ageBadge(daysSince(sale.created_at)), 'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset']">
                                    {{ daysSince(sale.created_at) }} días
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums text-gray-600">{{ money(sale.total) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums text-gray-600">{{ money(sale.amount_paid) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right text-sm font-bold tabular-nums text-red-600">{{ money(sale.amount_pending) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Movimientos recientes -->
        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <h3 class="text-sm font-bold text-gray-900">Movimientos recientes</h3>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-gray-500">
                    <span>Total cobrado al cliente:
                        <b class="font-mono tabular-nums text-emerald-700">{{ money(totalPaidHistorical) }}</b>
                    </span>
                    <span v-if="lastMovement">·
                        Último pago <b class="font-semibold text-gray-700">{{ formatRelative(lastMovement.created_at) }}</b>
                    </span>
                    <span v-if="topMethod">·
                        Método preferido: <b class="font-semibold text-gray-700">{{ methodLabel(topMethod.method) }}</b>
                    </span>
                </div>
            </div>
            <div v-if="loading" class="px-6 py-8 text-center text-sm text-gray-400">Cargando…</div>
            <div v-else-if="movements.length === 0" class="px-6 py-8 text-center">
                <p class="text-sm font-semibold text-gray-700">Sin pagos registrados</p>
                <p class="mt-1 text-xs text-gray-400">Aquí aparecen los pagos y cobros globales del cliente.</p>
            </div>
            <ol v-else class="divide-y divide-gray-50">
                <li v-for="m in movements" :key="`${m.type}-${m.id}`"
                    @click="openMovement(m)"
                    class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-gray-50/60">
                    <div :class="[
                        m.type === 'global' ? 'bg-violet-100 text-violet-700' : 'bg-emerald-100 text-emerald-700',
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full'
                    ]">
                        <svg v-if="m.type === 'global'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span v-if="m.type === 'global'" class="text-sm font-bold text-gray-900">{{ m.folio }}</span>
                            <span v-else class="text-sm font-bold text-gray-900">Pago a {{ m.sale_folio }}</span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ methodLabel(m.method) }}</span>
                            <span v-if="m.type === 'global'" class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20">
                                {{ m.sales_affected_count }} ventas afectadas
                            </span>
                            <span v-if="m.type === 'global' && Number(m.change_given) > 0" class="text-[10px] text-gray-400">
                                Cambio: {{ money(m.change_given) }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">
                            <span class="font-semibold text-gray-700">{{ m.cashier_name || 'Sistema' }}</span> · {{ formatRelative(m.created_at) }}
                        </p>
                    </div>
                    <span class="shrink-0 text-right">
                        <span class="block font-mono text-sm font-bold tabular-nums text-gray-900">
                            {{ money(m.type === 'global' ? m.amount_applied : m.amount) }}
                        </span>
                        <span v-if="m.type === 'global'" class="text-[10px] text-gray-400">
                            recibido: {{ money(m.amount_received) }}
                        </span>
                    </span>
                </li>
            </ol>
        </section>

        <SaleDetailModal :show="!!selectedSaleId" :tenant-slug="tenantSlug" :customer-id="customerId" :sale-id="selectedSaleId"
            :products="products" :allowed-payment-methods="allowedPaymentMethods"
            :sale-item-edit-reason-mode="saleItemEditReasonMode"
            @close="selectedSaleId = null" @sale-changed="refresh" />
        <GlobalPaymentDetailModal :show="!!selectedGlobalId" :tenant-slug="tenantSlug" :customer-id="customerId" :customer-payment-id="selectedGlobalId"
            @close="selectedGlobalId = null"
            @open-sale="(id) => { selectedGlobalId = null; selectedSaleId = id; }"
            @cancelled="refresh" />
    </div>
</template>
