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
        <!-- Resumen sutil arriba (sin cards redundantes con el hero) -->
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-2xl bg-white px-5 py-3 text-xs text-gray-500 shadow-sm ring-1 ring-gray-100">
            <span class="flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                Total cobrado: <b class="font-mono tabular-nums text-emerald-700">{{ money(totalPaidHistorical) }}</b>
            </span>
            <span v-if="lastMovement" class="flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400" />
                Último pago <b class="font-semibold text-gray-700">{{ formatRelative(lastMovement.created_at) }}</b>
            </span>
            <span v-if="topMethod" class="flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400" />
                Método preferido: <b class="font-semibold text-gray-700">{{ methodLabel(topMethod.method) }}</b>
            </span>
        </div>

        <!-- Dos columnas: Ventas pendientes ↔ Pagos -->
        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Columna izquierda: Ventas pendientes -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <h3 class="text-sm font-bold text-gray-900">Ventas pendientes</h3>
                        <span v-if="pendingSales.length > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700 ring-1 ring-inset ring-red-600/20">{{ pendingSales.length }}</span>
                    </div>
                    <button v-if="pendingSales.length > 0" type="button"
                        :disabled="!canRegisterPayment"
                        :title="paymentDisabledReason"
                        @click="$emit('register-payment')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-2.5 py-1 text-[11px] font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Registrar cobro
                    </button>
                </div>
                <div v-if="pendingSales.length > 0" class="border-b border-gray-100 bg-red-50/40 px-5 py-2 text-xs text-gray-600">
                    Saldo total: <b class="font-mono tabular-nums text-red-700">{{ money(totalPending) }}</b>
                </div>
                <div v-if="loading" class="flex items-center justify-center px-5 py-10 text-sm text-gray-400">
                    <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    Cargando…
                </div>
                <div v-else-if="pendingSales.length === 0" class="px-5 py-10 text-center">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-gray-700">Sin ventas pendientes</p>
                    <p class="mt-1 text-xs text-gray-400">Este cliente está al corriente.</p>
                </div>
                <ol v-else class="max-h-[560px] divide-y divide-gray-50 overflow-y-auto">
                    <li v-for="sale in pendingSales" :key="sale.id" @click="selectedSaleId = sale.id"
                        class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-gray-50/60">
                        <div :class="[ageBadge(daysSince(sale.created_at)), 'flex h-9 w-12 shrink-0 flex-col items-center justify-center rounded-lg ring-1 ring-inset']">
                            <span class="text-sm font-bold tabular-nums leading-none">{{ daysSince(sale.created_at) }}</span>
                            <span class="text-[9px] font-semibold uppercase leading-none">días</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900">{{ sale.folio }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                Total <span class="font-mono tabular-nums">{{ money(sale.total) }}</span>
                                <span v-if="Number(sale.amount_paid) > 0"> · Abonado <span class="font-mono tabular-nums text-emerald-700">{{ money(sale.amount_paid) }}</span></span>
                            </p>
                        </div>
                        <span class="shrink-0 font-mono text-sm font-bold tabular-nums text-red-600">{{ money(sale.amount_pending) }}</span>
                    </li>
                </ol>
            </section>

            <!-- Columna derecha: Pagos / movimientos -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <h3 class="text-sm font-bold text-gray-900">Pagos</h3>
                        <span v-if="movements.length > 0" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">{{ movements.length }}</span>
                    </div>
                </div>
                <div v-if="loading" class="px-5 py-10 text-center text-sm text-gray-400">Cargando…</div>
                <div v-else-if="movements.length === 0" class="px-5 py-10 text-center">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-gray-700">Sin pagos registrados</p>
                    <p class="mt-1 text-xs text-gray-400">Aquí aparecen los pagos y cobros globales del cliente.</p>
                </div>
                <ol v-else class="max-h-[560px] divide-y divide-gray-50 overflow-y-auto">
                    <li v-for="m in movements" :key="`${m.type}-${m.id}`"
                        @click="openMovement(m)"
                        class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-gray-50/60">
                        <div :class="[
                            m.type === 'global' ? 'bg-violet-100 text-violet-700' : 'bg-emerald-100 text-emerald-700',
                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-full'
                        ]">
                            <svg v-if="m.type === 'global'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span v-if="m.type === 'global'" class="text-sm font-bold text-gray-900">{{ m.folio }}</span>
                                <span v-else class="truncate text-sm font-bold text-gray-900">a {{ m.sale_folio }}</span>
                                <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-gray-500">{{ methodLabel(m.method) }}</span>
                                <span v-if="m.type === 'global'" class="rounded-full bg-violet-50 px-1.5 py-0.5 text-[9px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20">
                                    {{ m.sales_affected_count }} ventas
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-500">
                                <span class="font-semibold text-gray-700">{{ m.cashier_name || 'Sistema' }}</span> · {{ formatRelative(m.created_at) }}
                                <span v-if="m.type === 'global' && Number(m.change_given) > 0"> · Cambio {{ money(m.change_given) }}</span>
                            </p>
                        </div>
                        <span class="shrink-0 text-right">
                            <span class="block font-mono text-sm font-bold tabular-nums text-gray-900">
                                {{ money(m.type === 'global' ? m.amount_applied : m.amount) }}
                            </span>
                            <span v-if="m.type === 'global' && Number(m.amount_received) !== Number(m.amount_applied)"
                                class="text-[10px] text-gray-400">
                                recibido {{ money(m.amount_received) }}
                            </span>
                        </span>
                    </li>
                </ol>
            </section>
        </div>

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
