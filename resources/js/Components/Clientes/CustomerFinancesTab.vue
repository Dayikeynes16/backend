<script setup>
import { ref, computed, onMounted } from 'vue';
import SaleDetailModal from '@/Components/Clientes/SaleDetailModal.vue';
import GlobalPaymentDetailModal from '@/Components/Clientes/GlobalPaymentDetailModal.vue';

const props = defineProps({
    customerId: { type: Number, required: true },
    tenantSlug: { type: String, required: true },
    payments: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    canRegisterPayment: { type: Boolean, default: false },
    paymentDisabledReason: { type: String, default: '' },
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
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
};
const daysSince = (iso) => {
    if (!iso) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86400000));
};

const pendingSales = computed(() => props.payments?.pending_sales || []);
const movements = computed(() => props.payments?.recent_movements || []);
const totalOwed = computed(() => Number(props.payments?.total_owed ?? 0));

const globalCount = computed(() => movements.value.filter(m => m.type === 'global').length);
const singleCount = computed(() => movements.value.filter(m => m.type === 'single').length);
const totalPaid = computed(() => movements.value.reduce((acc, m) => acc + Number(m.amount ?? m.amount_applied ?? 0), 0));

const ageBadge = (days) => {
    if (days >= 30) return 'bg-red-50 text-red-700 ring-red-600/20';
    if (days >= 7) return 'bg-amber-50 text-amber-700 ring-amber-600/20';

    return 'bg-gray-100 text-gray-600 ring-gray-300/50';
};

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const selectedSaleId = ref(null);
const selectedGlobalId = ref(null);
const openMovement = (m) => {
    if (m.type === 'global') selectedGlobalId.value = m.id;
    else selectedSaleId.value = m.sale_id;
};
</script>

<template>
    <div class="space-y-5">
        <!-- KPIs + acción -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl bg-gradient-to-br from-red-50/50 to-white px-5 py-4 shadow-sm ring-1 ring-red-100 sm:col-span-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-red-700">Deuda actual</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-red-700">{{ money(totalOwed) }}</p>
                <p v-if="pendingSales.length > 0" class="mt-1 text-xs text-red-600/70">
                    {{ pendingSales.length }} {{ pendingSales.length === 1 ? 'venta pendiente' : 'ventas pendientes' }}
                </p>
                <p v-else class="mt-1 text-xs text-gray-500">Al corriente</p>
            </div>
            <div class="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Cobros globales</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-900">{{ globalCount }}</p>
            </div>
            <div class="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Pagos individuales</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-900">{{ singleCount }}</p>
            </div>
        </div>

        <!-- Ventas pendientes -->
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
                <h3 class="text-sm font-bold text-gray-900">
                    Ventas pendientes
                    <span v-if="pendingSales.length > 0" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">{{ pendingSales.length }}</span>
                </h3>
                <button v-if="pendingSales.length > 0" type="button"
                    :disabled="!canRegisterPayment"
                    :title="paymentDisabledReason"
                    @click="$emit('register-payment')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Registrar cobro
                </button>
            </div>
            <div v-if="loading" class="flex items-center justify-center px-6 py-10 text-sm text-gray-400">
                <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                Cargando…
            </div>
            <div v-else-if="pendingSales.length === 0" class="px-6 py-8 text-center">
                <p class="text-sm font-semibold text-gray-700">Sin ventas pendientes</p>
                <p class="mt-1 text-xs text-gray-400">Este cliente está al corriente con sus pagos.</p>
            </div>
            <table v-else class="min-w-full divide-y divide-gray-100">
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
        </section>

        <!-- Movimientos recientes -->
        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div class="border-b border-gray-100 px-5 py-3">
                <h3 class="text-sm font-bold text-gray-900">Movimientos recientes</h3>
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
                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-full'
                    ]">
                        <svg v-if="m.type === 'global'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span v-if="m.type === 'global'" class="text-sm font-bold text-gray-900">{{ m.folio }}</span>
                            <span v-else class="text-sm font-bold text-gray-900">Pago a {{ m.sale_folio }}</span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ methodLabel(m.method) }}</span>
                            <span v-if="m.type === 'global'" class="text-xs text-gray-400">{{ m.sales_affected_count }} ventas afectadas</span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">{{ m.cashier_name || 'Sistema' }} · {{ formatRelative(m.created_at) }}</p>
                    </div>
                    <span class="shrink-0 font-mono text-sm font-bold tabular-nums text-gray-900">
                        {{ money(m.type === 'global' ? m.amount_applied : m.amount) }}
                    </span>
                </li>
            </ol>
        </section>

        <SaleDetailModal :show="!!selectedSaleId" :tenant-slug="tenantSlug" :customer-id="customerId" :sale-id="selectedSaleId" @close="selectedSaleId = null" />
        <GlobalPaymentDetailModal :show="!!selectedGlobalId" :tenant-slug="tenantSlug" :customer-id="customerId" :customer-payment-id="selectedGlobalId" @close="selectedGlobalId = null" @open-sale="(id) => { selectedGlobalId = null; selectedSaleId = id; }" />
    </div>
</template>
