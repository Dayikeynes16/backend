<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import SaleDetailModal from '@/Components/Clientes/SaleDetailModal.vue';
import GlobalPaymentDetailModal from '@/Components/Clientes/GlobalPaymentDetailModal.vue';
import Modal from '@/Components/Modal.vue';
import PaymentReceiptsPanel from '@/Components/PaymentReceiptsPanel.vue';

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
    paymentReceiptsEnabled: { type: Boolean, default: false },
});

const emit = defineEmits(['load', 'register-payment']);

// --- Carga de pagos (lo trae el composable padre) ---
onMounted(() => emit('load'));

// --- Histórico de ventas con scroll infinito ---
const sales = ref([]);
const salesPage = ref(1);
const salesHasMore = ref(true);
const salesLoading = ref(false);
const salesError = ref('');
const PER_PAGE = 25;

const loadMoreSales = async () => {
    if (salesLoading.value || !salesHasMore.value) return;
    salesLoading.value = true;
    salesError.value = '';
    try {
        const url = new URL(
            route('sucursal.clientes.historial', [props.tenantSlug, props.customerId]),
            window.location.origin,
        );
        url.searchParams.set('per_page', PER_PAGE);
        url.searchParams.set('page', salesPage.value);
        const res = await fetch(url.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        sales.value.push(...(data.data || []));
        // Laravel paginator: next_page_url null cuando es la última.
        salesHasMore.value = !!data.next_page_url;
        salesPage.value += 1;
    } catch (e) {
        salesError.value = e.message || 'No se pudo cargar el historial.';
    } finally {
        salesLoading.value = false;
    }
};

onMounted(() => loadMoreSales());

// Refresca todo el historial desde cero (tras un cambio en una venta).
const resetSales = async () => {
    sales.value = [];
    salesPage.value = 1;
    salesHasMore.value = true;
    salesError.value = '';
    await loadMoreSales();
};

// IntersectionObserver sobre un sentinel al final de la lista para
// auto-cargar la siguiente página.
const salesScrollEl = ref(null);
const salesSentinel = ref(null);
let salesObserver = null;

onMounted(() => {
    if (!salesSentinel.value) return;
    salesObserver = new IntersectionObserver((entries) => {
        if (entries[0]?.isIntersecting) loadMoreSales();
    }, { root: salesScrollEl.value, rootMargin: '0px 0px 200px 0px' });
    salesObserver.observe(salesSentinel.value);
});

onBeforeUnmount(() => {
    if (salesObserver) salesObserver.disconnect();
});

// --- Helpers ---
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

// --- Datos del backend ---
const data = computed(() => props.stats || props.statsSeed || {});
const totalSpent = computed(() => Number(data.value.total_spent || 0));
const totalOwed = computed(() => Number(props.payments?.total_owed ?? data.value.total_owed ?? 0));
const totalPaidHistorical = computed(() => Number(data.value.total_paid || 0));
const saleCount = computed(() => Number(data.value.sale_count || 0));

const movements = computed(() => props.payments?.recent_movements || []);
const lastMovement = computed(() => movements.value[0] || null);

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

const saleStatusMeta = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Pagada', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' });

const isPending = (sale) => Number(sale.amount_pending) > 0;

const selectedSaleId = ref(null);
const selectedGlobalId = ref(null);
const openMovement = (m) => {
    if (m.type === 'global') selectedGlobalId.value = m.id;
    else selectedSaleId.value = m.sale_id;
};

// --- Comprobantes de un cobro global por transferencia (ver/gestionar) ---
// `canManage` se pasa fijo en `true`: esta pestaña solo la ve admin-sucursal
// (siempre puede gestionar comprobantes de su sucursal) — ver comentario en
// PaymentReceiptsPanel.vue para el caso general (cajero + turno).
const receiptsMovementId = ref(null);
const receiptsMovement = computed(() => movements.value.find(m => m.type === 'global' && m.id === receiptsMovementId.value) || null);
const openReceipts = (m) => { if (m.type === 'global') receiptsMovementId.value = m.id; };
const closeReceipts = () => { receiptsMovementId.value = null; };
const onReceiptsChanged = () => emit('load');

const refreshAll = () => {
    emit('load');
    resetSales();
};
</script>

<template>
    <div class="space-y-5">
        <!-- Banner sutil con métricas que vienen del backend -->
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

        <!-- Dos columnas: Ventas ↔ Pagos -->
        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Columna izquierda: Ventas (todas, scroll infinito) -->
            <section class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                        <h3 class="text-sm font-bold text-gray-900">Ventas</h3>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-500">{{ saleCount }}</span>
                    </div>
                    <button v-if="totalOwed > 0" type="button"
                        :disabled="!canRegisterPayment"
                        :title="paymentDisabledReason"
                        @click="$emit('register-payment')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-2.5 py-1 text-[11px] font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Registrar cobro
                    </button>
                </div>

                <!-- Banda con totales (calculados en el backend) -->
                <div class="grid grid-cols-2 divide-x divide-gray-100 border-b border-gray-100 bg-gray-50/40 text-center">
                    <div class="px-3 py-2.5">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total gastado</p>
                        <p class="mt-0.5 font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(totalSpent) }}</p>
                    </div>
                    <div class="px-3 py-2.5" :class="totalOwed > 0 ? 'bg-red-50/40' : ''">
                        <p :class="['text-[10px] font-bold uppercase tracking-wider', totalOwed > 0 ? 'text-red-700' : 'text-gray-500']">Saldo pendiente</p>
                        <p :class="['mt-0.5 font-mono text-sm font-bold tabular-nums', totalOwed > 0 ? 'text-red-700' : 'text-gray-400']">{{ money(totalOwed) }}</p>
                    </div>
                </div>

                <!-- Lista con scroll infinito -->
                <div v-if="salesError && sales.length === 0" class="px-5 py-10 text-center text-sm text-red-600">{{ salesError }}</div>
                <div v-else-if="!salesLoading && sales.length === 0" class="px-5 py-10 text-center">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-gray-700">Sin compras registradas</p>
                    <p class="mt-1 text-xs text-gray-400">Cuando este cliente compre algo, aparecerá aquí.</p>
                </div>
                <ol v-else ref="salesScrollEl" class="max-h-[560px] divide-y divide-gray-50 overflow-y-auto">
                    <li v-for="sale in sales" :key="sale.id" @click="selectedSaleId = sale.id"
                        class="flex cursor-pointer items-center gap-3 px-5 py-3 transition hover:bg-gray-50/60">
                        <div v-if="isPending(sale)" class="flex h-9 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-amber-100 text-amber-700 ring-1 ring-inset ring-amber-600/20">
                            <span class="text-sm font-bold tabular-nums leading-none">{{ daysSince(sale.created_at) }}</span>
                            <span class="text-[9px] font-semibold uppercase leading-none">días</span>
                        </div>
                        <div v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                                <span :class="[saleStatusMeta(sale.status).cls, 'rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider ring-1 ring-inset']">
                                    {{ isPending(sale) ? 'Pendiente' : saleStatusMeta(sale.status).label }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-500">{{ formatRelative(sale.created_at) }}</p>
                        </div>
                        <span class="shrink-0 text-right">
                            <span class="block font-mono text-sm font-bold tabular-nums"
                                :class="isPending(sale) ? 'text-red-600' : 'text-gray-900'">
                                {{ money(isPending(sale) ? sale.amount_pending : sale.total) }}
                            </span>
                            <span v-if="isPending(sale) && Number(sale.amount_paid) > 0" class="text-[10px] text-gray-400">
                                abonado {{ money(sale.amount_paid) }}
                            </span>
                            <span v-else-if="!isPending(sale)" class="text-[10px] text-gray-400">
                                {{ money(sale.total) }} pagado
                            </span>
                        </span>
                    </li>

                    <!-- Sentinel para IntersectionObserver -->
                    <li ref="salesSentinel" class="py-3 text-center text-xs text-gray-400">
                        <span v-if="salesLoading" class="inline-flex items-center gap-2">
                            <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            Cargando…
                        </span>
                        <span v-else-if="!salesHasMore && sales.length > 0" class="text-gray-300">— Final del historial —</span>
                    </li>
                </ol>
            </section>

            <!-- Columna derecha: Pagos -->
            <section class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
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
                                <button v-if="m.type === 'global' && m.method === 'transfer' && paymentReceiptsEnabled" type="button" @click.stop="openReceipts(m)"
                                    class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-1.5 py-0.5 text-[9px] font-bold text-violet-700 ring-1 ring-inset ring-violet-600/20 transition hover:bg-violet-100"
                                    title="Comprobantes de esta transferencia">
                                    📎 {{ m.receipts?.length ?? 0 }}
                                </button>
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
            @close="selectedSaleId = null" @sale-changed="refreshAll" />
        <GlobalPaymentDetailModal :show="!!selectedGlobalId" :tenant-slug="tenantSlug" :customer-id="customerId" :customer-payment-id="selectedGlobalId"
            @close="selectedGlobalId = null"
            @open-sale="(id) => { selectedGlobalId = null; selectedSaleId = id; }"
            @cancelled="refreshAll" />

        <!-- Comprobantes de un cobro global por transferencia -->
        <Modal :show="!!receiptsMovement" max-width="lg" @close="closeReceipts">
            <PaymentReceiptsPanel v-if="receiptsMovement"
                :receipts="receiptsMovement.receipts ?? []"
                parent-type="customer-payment"
                :parent-id="receiptsMovement.id"
                :can-manage="true"
                :tenant-slug="tenantSlug"
                route-prefix="sucursal"
                @changed="onReceiptsChanged"
                @close="closeReceipts" />
        </Modal>
    </div>
</template>
