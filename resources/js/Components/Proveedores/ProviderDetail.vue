<script setup>
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import PagoProveedorModal from '@/Components/Compras/PagoProveedorModal.vue';
import { useProviderStats } from '@/composables/useProviderStats';
import { formatCurrency } from '@/composables/useCurrency';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    provider: { type: Object, required: true },
    // Snapshot inicial (deuda_actual, compras_count, ultima_compra) para pintar al instante.
    seed: { type: Object, default: () => ({}) },
    // 'empresa' | 'sucursal' — define los nombres de ruta y el alcance (back-end).
    routePrefix: { type: String, required: true },
    canRegisterPayment: { type: Boolean, default: true },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const routeNames = {
    resumen: `${props.routePrefix}.proveedores.resumen`,
    compras: `${props.routePrefix}.proveedores.compras`,
    pagos: `${props.routePrefix}.proveedores.pagos.index`,
    productos: `${props.routePrefix}.proveedores.productos`,
};
const paymentRoutes = {
    storePurchase: `${props.routePrefix}.compras.pagos.store`,
    storeProvider: `${props.routePrefix}.proveedores.pagos.store`,
};

const {
    resumen, compras, pagos, productos, loading,
    loadResumen, loadCompras, loadPagos, loadProductos, resetTabs,
} = useProviderStats(props.provider.id, slug.value, routeNames);

// ─── Rango de fechas ──────────────────────────────────────────────────────
const localIso = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
};
const presetRange = (key) => {
    const now = new Date();
    if (key === 'today') {
        const t = localIso(now);
        return { from: t, to: t };
    }
    if (key === 'last7') {
        const f = new Date(now);
        f.setDate(f.getDate() - 6);
        return { from: localIso(f), to: localIso(now) };
    }
    // month
    return { from: localIso(new Date(now.getFullYear(), now.getMonth(), 1)), to: localIso(now) };
};

const presets = [
    { key: 'today', label: 'Hoy' },
    { key: 'last7', label: 'Últimos 7 días' },
    { key: 'month', label: 'Este mes' },
    { key: 'custom', label: 'Personalizado' },
];
const preset = ref('month');
const customFrom = ref(presetRange('month').from);
const customTo = ref(presetRange('month').to);

const range = computed(() => (
    preset.value === 'custom'
        ? { from: customFrom.value, to: customTo.value }
        : presetRange(preset.value)
));

const setPreset = (key) => {
    if (key === 'custom' && preset.value !== 'custom') {
        const r = presetRange('month');
        customFrom.value = r.from;
        customTo.value = r.to;
    }
    preset.value = key;
};

// ─── Tabs ───────────────────────────────────────────────────────────────────
const activeTab = ref('compras');

const ensureTab = (tab) => {
    const r = range.value;
    if (tab === 'compras' && compras.value.items.length === 0 && !loading.value.compras) {
        loadCompras({ ...r, page: 1 });
    } else if (tab === 'pagos' && pagos.value.items.length === 0 && !loading.value.pagos) {
        loadPagos({ ...r, page: 1 });
    } else if (tab === 'productos' && productos.value === null && !loading.value.productos) {
        loadProductos(r);
    }
};

const setTab = (tab) => {
    activeTab.value = tab;
    ensureTab(tab);
};

const reload = () => {
    const r = range.value;
    loadResumen(r);
    resetTabs();
    ensureTab(activeTab.value);
};

watch(range, reload);
onMounted(reload);

const loadMore = (which) => {
    const r = range.value;
    if (which === 'compras') {
        loadCompras({ ...r, page: compras.value.page + 1, append: true });
    } else {
        loadPagos({ ...r, page: pagos.value.page + 1, append: true });
    }
};

// ─── Pago ─────────────────────────────────────────────────────────────────
const paymentOpen = ref(false);
const paymentPurchase = ref(null); // null ⇒ pago a cuenta (FIFO); set ⇒ pago a una compra

const openAccountPayment = () => { paymentPurchase.value = null; paymentOpen.value = true; };
const openPurchasePayment = (purchase) => { paymentPurchase.value = purchase; paymentOpen.value = true; };
const onPaymentCreated = () => { paymentOpen.value = false; reload(); };

// ─── Helpers de formato ─────────────────────────────────────────────────────
const fmt = (n) => formatCurrency(n);
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const fmtQty = (n) => Number(n || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });

const paymentBadge = (s) => ({
    paid: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-amber-100 text-amber-800',
    pending: 'bg-gray-100 text-gray-700',
    cancelled: 'bg-red-100 text-red-700',
}[s] || 'bg-gray-100 text-gray-700');
const paymentLabel = (s) => ({ paid: 'Pagada', partial: 'Abonada', pending: 'Pendiente', cancelled: 'Cancelada' }[s] || s);
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const typeBadgeColor = (type) => ({
    ganadero: 'bg-amber-100 text-amber-800',
    mayorista_carne: 'bg-rose-100 text-rose-800',
    insumos: 'bg-emerald-100 text-emerald-800',
    servicios: 'bg-sky-100 text-sky-800',
    otro: 'bg-gray-100 text-gray-700',
}[type] || 'bg-gray-100 text-gray-700');

// Deuda actual: prioriza el resumen cargado; cae al seed mientras carga.
const deudaActual = computed(() => resumen.value?.deuda_actual ?? props.seed?.deuda_actual ?? 0);
const ultimaCompra = computed(() => resumen.value?.ultima_compra ?? props.seed?.ultima_compra ?? null);

const relationState = computed(() => {
    if (props.provider.status === 'inactive') {
        return { label: 'Inactivo', cls: 'bg-gray-200 text-gray-700' };
    }
    return deudaActual.value > 0
        ? { label: 'Con deuda', cls: 'bg-amber-100 text-amber-800' }
        : { label: 'Al corriente', cls: 'bg-emerald-100 text-emerald-800' };
});
</script>

<template>
    <div class="space-y-5">
        <!-- Hero -->
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900">{{ provider.name }}</h2>
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeColor(provider.type)]">{{ provider.type_label }}</span>
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', relationState.cls]">{{ relationState.label }}</span>
                    </div>
                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600">
                        <div v-if="provider.phone"><dt class="inline text-gray-400">Tel:</dt> {{ provider.phone }}</div>
                        <div v-if="provider.email"><dt class="inline text-gray-400">Email:</dt> {{ provider.email }}</div>
                        <div v-if="provider.rfc"><dt class="inline text-gray-400">RFC:</dt> {{ provider.rfc }}</div>
                        <div v-if="provider.address"><dt class="inline text-gray-400">Dirección:</dt> {{ provider.address }}</div>
                    </dl>
                </div>
                <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                    <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-right">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-amber-700">Deuda actual</div>
                        <div class="text-2xl font-bold" :class="deudaActual > 0 ? 'text-amber-800' : 'text-gray-400'">
                            {{ deudaActual > 0 ? fmt(deudaActual) : '$0.00' }}
                        </div>
                    </div>
                    <button v-if="canRegisterPayment" type="button" @click="openAccountPayment"
                        class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:from-emerald-700 hover:to-teal-700">
                        Registrar pago a cuenta
                    </button>
                </div>
            </div>
            <p v-if="provider.notes" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ provider.notes }}</p>
        </div>

        <!-- Selector de rango -->
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-1">
                <button v-for="p in presets" :key="p.key" type="button" @click="setPreset(p.key)"
                    :class="['rounded-lg px-3 py-2 text-xs font-semibold transition',
                        preset === p.key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                    {{ p.label }}
                </button>
            </div>
            <div v-if="preset === 'custom'" class="flex items-center gap-2 text-sm">
                <input v-model="customFrom" type="date" class="rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <span class="text-gray-400">→</span>
                <input v-model="customTo" type="date" class="rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
            </div>
        </div>

        <!-- KPIs del periodo -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Total comprado" :value="fmt(resumen?.total_comprado ?? 0)" tone="neutral"
                :hint="ultimaCompra ? `Última compra: ${fmtDate(ultimaCompra.purchased_at)}` : 'Sin compras'" />
            <KpiCard label="Total pagado" :value="fmt(resumen?.total_pagado ?? 0)" tone="green" hint="Pagos en el rango" />
            <KpiCard label="# Compras" :value="resumen?.compras_count ?? 0" tone="blue" hint="En el rango" />
            <KpiCard label="Deuda actual" :value="fmt(deudaActual)" :tone="deudaActual > 0 ? 'amber' : 'neutral'" hint="Saldo total (histórico)" />
        </div>

        <!-- Tabs -->
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="flex gap-1 border-b border-gray-200 p-2">
                <button v-for="t in [{ k: 'compras', l: 'Compras' }, { k: 'pagos', l: 'Pagos' }, { k: 'productos', l: 'Productos' }]"
                    :key="t.k" type="button" @click="setTab(t.k)"
                    :class="['rounded-lg px-4 py-2 text-sm font-semibold transition',
                        activeTab === t.k ? 'bg-orange-50 text-orange-700' : 'text-gray-500 hover:bg-gray-50']">
                    {{ t.l }}
                </button>
            </div>

            <!-- Compras -->
            <div v-show="activeTab === 'compras'" class="p-2 sm:p-4">
                <div v-if="loading.compras && !compras.items.length" class="space-y-2">
                    <div v-for="i in 4" :key="i" class="h-12 animate-pulse rounded-lg bg-gray-100" />
                </div>
                <div v-else-if="!compras.items.length" class="py-10 text-center text-sm text-gray-500">Sin compras en este rango.</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2">Folio</th>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2 text-right">Pendiente</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in compras.items" :key="c.id" class="text-sm hover:bg-gray-50">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ c.folio }}
                                    <span v-if="c.invoice_number" class="block text-xs text-gray-400">{{ c.invoice_number }}</span>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ fmtDate(c.purchased_at) }}</td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ fmt(c.total) }}</td>
                                <td class="px-3 py-2 text-right" :class="c.amount_pending > 0 ? 'text-amber-700' : 'text-gray-400'">
                                    {{ c.amount_pending > 0 ? fmt(c.amount_pending) : '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', paymentBadge(c.payment_status)]">{{ paymentLabel(c.payment_status) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button v-if="canRegisterPayment && c.amount_pending > 0" type="button" @click="openPurchasePayment(c)"
                                        class="text-xs font-semibold text-emerald-700 hover:text-emerald-900">Pagar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="compras.page < compras.lastPage" class="pt-3 text-center">
                        <button type="button" @click="loadMore('compras')" :disabled="loading.compras"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50">
                            {{ loading.compras ? 'Cargando…' : 'Cargar más' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pagos -->
            <div v-show="activeTab === 'pagos'" class="p-2 sm:p-4">
                <div v-if="loading.pagos && !pagos.items.length" class="space-y-2">
                    <div v-for="i in 4" :key="i" class="h-12 animate-pulse rounded-lg bg-gray-100" />
                </div>
                <div v-else-if="!pagos.items.length" class="py-10 text-center text-sm text-gray-500">Sin pagos en este rango.</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2 text-right">Monto</th>
                                <th class="px-3 py-2">Método</th>
                                <th class="px-3 py-2">Aplicado a</th>
                                <th class="px-3 py-2">Referencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="pay in pagos.items" :key="pay.id" class="text-sm hover:bg-gray-50" :class="pay.cancelled_at ? 'opacity-50' : ''">
                                <td class="px-3 py-2 text-gray-600">{{ fmtDate(pay.paid_at) }}</td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ fmt(pay.amount) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ methodLabel(pay.payment_method) }}</td>
                                <td class="px-3 py-2 text-gray-600">
                                    <span v-if="pay.purchase">{{ pay.purchase.folio }}</span>
                                    <span v-else class="italic text-gray-400">A cuenta / excedente</span>
                                </td>
                                <td class="px-3 py-2 text-gray-500">
                                    <span v-if="pay.cancelled_at" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Cancelado</span>
                                    <span v-else>{{ pay.reference || '—' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="pagos.page < pagos.lastPage" class="pt-3 text-center">
                        <button type="button" @click="loadMore('pagos')" :disabled="loading.pagos"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50">
                            {{ loading.pagos ? 'Cargando…' : 'Cargar más' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Productos -->
            <div v-show="activeTab === 'productos'" class="p-2 sm:p-4">
                <div v-if="loading.productos && productos === null" class="space-y-2">
                    <div v-for="i in 4" :key="i" class="h-12 animate-pulse rounded-lg bg-gray-100" />
                </div>
                <div v-else-if="!productos || !productos.length" class="py-10 text-center text-sm text-gray-500">Sin productos en este rango.</div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2">Concepto</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                <th class="px-3 py-2 text-right">Monto</th>
                                <th class="px-3 py-2 text-right">Veces</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(it, i) in productos" :key="it.purchase_product_id ?? i" class="text-sm hover:bg-gray-50">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ it.concept }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ fmtQty(it.total_quantity) }} {{ it.unit }}</td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ fmt(it.total_amount) }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ it.times_bought }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PagoProveedorModal
            :open="paymentOpen"
            :provider="provider"
            :purchase="paymentPurchase"
            :routes="paymentRoutes"
            @close="paymentOpen = false"
            @created="onPaymentCreated"
        />
    </div>
</template>
