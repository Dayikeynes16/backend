<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import StatCard from '@/Components/Clientes/StatCard.vue';
import SaleDetailModal from '@/Components/Clientes/SaleDetailModal.vue';
import DateRangePicker from '@/Components/Clientes/DateRangePicker.vue';
import PriceEditor from '@/Components/Clientes/PriceEditor.vue';
import CustomerPaymentModal from '@/Components/Clientes/CustomerPaymentModal.vue';
import GlobalPaymentDetailModal from '@/Components/Clientes/GlobalPaymentDetailModal.vue';
import { useCustomerStats } from '@/composables/useCustomerStats';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
    customers: Array,
    products: Array,
    filters: Object,
    tenant: Object,
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    customersSummary: { type: Object, default: null },
});

// --- Filters ---
const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('sucursal.clientes.index', props.tenant.slug), {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};
watch(search, applyFilters);
watch(statusFilter, () => { clearTimeout(debounceTimer); applyFilters(); });

// --- Selection ---
const selectedId = ref(null);
const selected = computed(() => props.customers.find(c => c.id === selectedId.value) || null);
const selectCustomer = (c) => { selectedId.value = c.id; activeTab.value = 'resumen'; };

watch(() => props.customers, () => {
    if (selectedId.value && !props.customers.find(c => c.id === selectedId.value)) {
        selectedId.value = null;
    }
});

// --- Tabs + lazy stats ---
const activeTab = ref('resumen');
const {
    stats, history, topProducts, payments,
    loading: statsLoading,
    loadStats, loadHistory, loadTopProducts, loadPayments,
    registerGlobalPayment,
} = useCustomerStats(selected, props.tenant.slug);

watch(activeTab, (tab) => {
    if (!selected.value) return;
    if (tab === 'resumen' && !stats.value) loadStats();
    if (tab === 'compras' && !history.value) loadHistory({ per_page: historyPerPage.value });
    if (tab === 'productos' && !topProducts.value) loadTopProducts(10);
    if (tab === 'finanzas' && !payments.value) loadPayments();
});

// --- History filters ---
const historyFrom = ref('');
const historyTo = ref('');
const historyPerPage = ref(25);
const applyHistoryFilters = () => {
    loadHistory({ from: historyFrom.value || undefined, to: historyTo.value || undefined, per_page: historyPerPage.value });
};
const goToHistoryPage = (pageUrl) => {
    if (!pageUrl) return;
    const url = new URL(pageUrl, window.location.origin);
    url.searchParams.set('per_page', historyPerPage.value);
    if (historyFrom.value) url.searchParams.set('from', historyFrom.value);
    if (historyTo.value) url.searchParams.set('to', historyTo.value);
    fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { history.value = d; });
};

// --- Top products view toggle ---
const topProductsView = ref('quantity'); // 'quantity' | 'spent'
const topProductsSorted = computed(() => {
    if (!topProducts.value?.items) return [];
    const copy = [...topProducts.value.items];
    if (topProductsView.value === 'spent') copy.sort((a, b) => b.total_spent - a.total_spent);
    else copy.sort((a, b) => b.total_quantity - a.total_quantity);
    return copy;
});
const topProductsMax = computed(() => {
    if (!topProductsSorted.value.length) return 1;
    return topProductsView.value === 'spent'
        ? Math.max(...topProductsSorted.value.map(p => p.total_spent))
        : Math.max(...topProductsSorted.value.map(p => p.total_quantity));
});

// --- Create customer ---
const showCreateModal = ref(false);
const createForm = useForm({ name: '', phone: '', notes: '' });
const submitCreate = () => {
    createForm.post(route('sucursal.clientes.store', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => { showCreateModal.value = false; createForm.reset(); },
    });
};

// --- Edit customer ---
const editing = ref(false);
const editForm = useForm({ name: '', phone: '', notes: '', status: '' });
const startEdit = () => {
    if (!selected.value) return;
    editForm.name = selected.value.name;
    editForm.phone = selected.value.phone;
    editForm.notes = selected.value.notes || '';
    editForm.status = selected.value.status;
    editing.value = true;
};
const submitEdit = () => {
    editForm.put(route('sucursal.clientes.update', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
};

// --- Delete customer ---
const confirmDelete = ref(false);
const deleteCustomer = () => {
    router.delete(route('sucursal.clientes.destroy', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { confirmDelete.value = false; selectedId.value = null; },
    });
};

// --- Prices ---
const showAddPrice = ref(false);
const priceForm = useForm({ product_id: '', price: '' });

const assignedProductIds = computed(() =>
    (selected.value?.prices || []).map(p => p.product_id)
);
const availableProducts = computed(() =>
    props.products.filter(p => !assignedProductIds.value.includes(p.id))
);

const submitPrice = () => {
    priceForm.post(route('sucursal.clientes.precios.store', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { showAddPrice.value = false; priceForm.reset(); },
    });
};

const editingPriceId = ref(null);
const editPriceForm = useForm({ price: '' });
const priceEditorRef = ref(null);
const startEditPrice = async (p) => {
    editingPriceId.value = p.id;
    editPriceForm.price = parseFloat(p.price);
    editPriceForm.clearErrors();
    await nextTick();
    priceEditorRef.value?.focus?.();
};
const submitEditPrice = (priceId, newPrice) => {
    editPriceForm.price = newPrice;
    editPriceForm.put(route('sucursal.clientes.precios.update', [props.tenant.slug, selected.value.id, priceId]), {
        preserveScroll: true,
        onSuccess: () => { editingPriceId.value = null; },
    });
};

const confirmDeletePriceId = ref(null);
const deletePrice = (priceId) => {
    router.delete(route('sucursal.clientes.precios.destroy', [props.tenant.slug, selected.value.id, priceId]), {
        preserveScroll: true,
        onSuccess: () => { confirmDeletePriceId.value = null; },
    });
};

// --- Sale detail modal ---
const saleModalOpen = ref(false);
const saleModalSaleId = ref(null);
const openSaleModal = (saleId) => {
    saleModalSaleId.value = saleId;
    saleModalOpen.value = true;
};
const closeSaleModal = () => {
    saleModalOpen.value = false;
    setTimeout(() => { saleModalSaleId.value = null; }, 250);
};

// --- Global payment (cobro global) ---
const customerPaymentModalOpen = ref(false);
const openCustomerPaymentModal = () => {
    if (!stats.value?.current_user_shift_open) return;
    if (!stats.value?.pending_sales_count) return;
    customerPaymentModalOpen.value = true;
};
const closeCustomerPaymentModal = () => { customerPaymentModalOpen.value = false; };

const onGlobalPaymentSuccess = async (data) => {
    const cp = data.customer_payment;
    const saldadas = data.applied.filter(a => a.completed).length;
    let msg = `Cobro ${cp.folio}: ${money(cp.amount_applied)} aplicado`;
    if (saldadas > 0) msg += ` · ${saldadas} venta${saldadas !== 1 ? 's' : ''} saldada${saldadas !== 1 ? 's' : ''}`;
    if (cp.change_given > 0) msg += ` · cambio ${money(cp.change_given)}`;
    flashMessage.value = msg;
    setTimeout(() => { flashMessage.value = null; }, 6000);

    // Refresh stats + payments to reflect the new state
    await Promise.all([loadStats(), loadPayments()]);
};

const flashMessage = ref(null);

// --- Global payment detail modal ---
const globalDetailModalOpen = ref(false);
const globalDetailModalId = ref(null);
const openGlobalDetailModal = (customerPaymentId) => {
    globalDetailModalId.value = customerPaymentId;
    globalDetailModalOpen.value = true;
};
const closeGlobalDetailModal = () => {
    globalDetailModalOpen.value = false;
    setTimeout(() => { globalDetailModalId.value = null; }, 250);
};
const openSaleFromDetail = (saleId) => {
    closeGlobalDetailModal();
    setTimeout(() => openSaleModal(saleId), 200);
};

const onGlobalPaymentCancelled = async (result) => {
    const affected = result?.affected_sale_ids?.length || 0;
    flashMessage.value = `Cobro cancelado · ${affected} venta${affected !== 1 ? 's' : ''} con saldo restaurado`;
    setTimeout(() => { flashMessage.value = null; }, 6000);
    await Promise.all([loadStats(), loadPayments()]);
};

// --- Formatters ---
const money = (v) => {
    const n = Number(v ?? 0);
    return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
const qty = (v, unit) => {
    const n = Number(v ?? 0);
    const str = unit === 'kg' ? n.toFixed(3) : n.toFixed(0);
    return `${str} ${unit || ''}`.trim();
};
const fmtDate = (v) => {
    if (!v) return '—';
    const d = new Date(v);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};
const fmtDateTime = (v) => {
    if (!v) return '—';
    const d = new Date(v);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) + ' ' +
        d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

const paymentBadge = (sale) => {
    if (sale.status === 'cancelled') return { text: 'Cancelada', cls: 'bg-gray-100 text-gray-500', ring: 'ring-gray-200' };
    const pending = Number(sale.amount_pending ?? 0);
    const paid = Number(sale.amount_paid ?? 0);
    if (pending <= 0 && paid > 0) return { text: 'Pagada', cls: 'bg-green-100 text-green-700', ring: 'ring-green-200' };
    if (pending > 0 && paid > 0) return { text: 'Parcial', cls: 'bg-amber-100 text-amber-700', ring: 'ring-amber-200' };
    return { text: 'Pendiente', cls: 'bg-red-100 text-red-700', ring: 'ring-red-200' };
};

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

// --- Precio preferencial: cálculo ahorro unitario ---
const priceSavings = (pp) => {
    if (!pp.product) return null;
    const std = parseFloat(pp.product.price);
    const pref = parseFloat(pp.price);
    const diff = std - pref;
    return {
        diff,
        pct: std > 0 ? (diff / std) * 100 : 0,
    };
};
</script>

<template>
    <Head title="Clientes" />
    <SucursalLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Clientes</h1></template>

        <!-- Resumen fijo de la cartera -->
        <section v-if="customersSummary" class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">Total</p>
                <p class="mt-1 font-mono text-xl font-bold tabular-nums text-gray-900">{{ customersSummary.total }}</p>
            </div>
            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">Activos</p>
                <p class="mt-1 font-mono text-xl font-bold tabular-nums text-emerald-600">{{ customersSummary.active }}</p>
            </div>
            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">Inactivos</p>
                <p class="mt-1 font-mono text-xl font-bold tabular-nums text-gray-400">{{ customersSummary.inactive }}</p>
            </div>
            <div :class="['rounded-2xl px-4 py-3 shadow-sm ring-1',
                customersSummary.total_debt > 0
                    ? 'bg-red-50/60 ring-red-100'
                    : 'bg-white ring-gray-100']">
                <p :class="['text-[10px] font-bold uppercase tracking-[0.12em]',
                    customersSummary.total_debt > 0 ? 'text-red-600' : 'text-gray-400']">Deuda total</p>
                <p :class="['mt-1 font-mono text-xl font-bold tabular-nums',
                    customersSummary.total_debt > 0 ? 'text-red-700' : 'text-gray-300']">{{ money(customersSummary.total_debt) }}</p>
            </div>
            <div class="col-span-2 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100 sm:col-span-3 lg:col-span-1">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">Con deuda</p>
                <p class="mt-1 font-mono text-xl font-bold tabular-nums text-gray-700">
                    {{ customersSummary.customers_with_debt }}
                    <span class="text-xs font-normal text-gray-400">de {{ customersSummary.total }}</span>
                </p>
            </div>
        </section>

        <div class="flex h-[calc(100vh-15rem)] gap-5">
            <!-- LEFT: Customer list -->
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="space-y-3 border-b border-gray-100 px-5 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold text-gray-900">Clientes</h2>
                        <button @click="showCreateModal = true" class="flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-red-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo
                        </button>
                    </div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar nombre o telefono..." class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'',l:'Activos'},{v:'inactive',l:'Inactivos'},{v:'all',l:'Todos'}]"
                            :key="f.v" @click="statusFilter = f.v === 'all' ? 'all' : f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                (statusFilter === f.v || (!statusFilter && f.v === '')) ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }}
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="c in customers" :key="c.id" @click="selectCustomer(c)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all',
                            selectedId === c.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-bold text-gray-900">{{ c.name }}</span>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <span v-if="parseFloat(c.total_owed) > 0"
                                    class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-700 ring-1 ring-inset ring-red-200"
                                    :title="'Saldo pendiente'">
                                    <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 8 8" aria-hidden="true"><circle cx="4" cy="4" r="3"/></svg>
                                    {{ money(c.total_owed) }}
                                </span>
                                <span v-if="c.status === 'inactive'" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">Inactivo</span>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ c.phone }}</p>
                        <p v-if="c.prices?.length" class="mt-1 text-xs text-red-500">{{ c.prices.length }} precio{{ c.prices.length > 1 ? 's' : '' }} preferencial{{ c.prices.length > 1 ? 'es' : '' }}</p>
                    </div>
                    <div v-if="customers.length === 0" class="flex flex-col items-center justify-center py-20 text-center">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay clientes.</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona un cliente para ver el detalle</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <template v-if="editing">
                            <form @submit.prevent="submitEdit" class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-gray-500">Nombre</label>
                                        <input v-model="editForm.name" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-gray-500">Telefono</label>
                                        <input v-model="editForm.phone" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                        <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Notas</label>
                                    <textarea v-model="editForm.notes" rows="2" class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Estado</label>
                                    <select v-model="editForm.status" class="rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="active">Activo</option>
                                        <option value="inactive">Inactivo</option>
                                    </select>
                                </div>
                                <div class="flex gap-3">
                                    <button type="submit" :disabled="editForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                                    <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                                </div>
                            </form>
                        </template>
                        <template v-else>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="flex items-center gap-3">
                                        <h2 class="text-lg font-bold text-gray-900">{{ selected.name }}</h2>
                                        <span v-if="selected.status === 'inactive'" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-500 ring-1 ring-inset ring-gray-200">Inactivo</span>
                                        <span v-if="stats?.total_owed > 0" class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200">
                                            Debe {{ money(stats.total_owed) }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-sm text-gray-500">{{ selected.phone }}</p>
                                    <p v-if="selected.notes" class="mt-1 text-xs text-gray-400">{{ selected.notes }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button @click="startEdit" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">Editar</button>
                                    <button @click="confirmDelete = true" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-100">Eliminar</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Tabs -->
                    <div class="flex items-center gap-1 border-b border-gray-100 px-4 pt-3">
                        <button v-for="t in [
                            {k:'resumen', l:'Resumen'},
                            {k:'compras', l:'Compras'},
                            {k:'productos', l:'Productos'},
                            {k:'finanzas', l:'Finanzas'},
                        ]" :key="t.k" @click="activeTab = t.k"
                            :class="['relative px-4 py-2.5 text-sm font-semibold transition',
                                activeTab === t.k ? 'text-red-600' : 'text-gray-500 hover:text-gray-700']">
                            {{ t.l }}
                            <span v-if="t.k === 'finanzas' && stats?.pending_sales_count > 0" class="ml-1.5 rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ stats.pending_sales_count }}</span>
                            <span v-if="activeTab === t.k" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-red-600"></span>
                        </button>
                    </div>

                    <!-- Tab content -->
                    <div class="flex-1 overflow-y-auto">

                        <!-- TAB: RESUMEN -->
                        <div v-if="activeTab === 'resumen'" class="space-y-6 p-6">
                            <!-- Stat cards -->
                            <div class="grid grid-cols-3 gap-3">
                                <StatCard label="Total gastado" :value="stats ? money(stats.total_spent) : '—'" :loading="statsLoading.stats" />
                                <StatCard label="Compras" :value="stats ? stats.sale_count : '—'" :hint="stats?.sales_per_month ? `~${stats.sales_per_month}/mes` : ''" :loading="statsLoading.stats" />
                                <StatCard label="Ticket promedio" :value="stats ? money(stats.avg_ticket) : '—'" :loading="statsLoading.stats" />
                                <StatCard
                                    label="Ahorro total"
                                    :value="stats ? money(stats.total_saved) : '—'"
                                    :hint="stats && stats.avg_discount_pct > 0 ? `${stats.avg_discount_pct}% descuento prom.` : ''"
                                    tone="accent"
                                    :loading="statsLoading.stats" />
                                <StatCard
                                    label="Última compra"
                                    :value="stats?.last_sale ? fmtDate(stats.last_sale.created_at) : '—'"
                                    :hint="stats?.last_sale ? money(stats.last_sale.total) : ''"
                                    :loading="statsLoading.stats" />
                                <StatCard
                                    label="Frecuencia"
                                    :value="stats?.avg_days_between != null ? `cada ${stats.avg_days_between} d` : '—'"
                                    :hint="stats?.sales_per_month ? `${stats.sales_per_month} compras/mes` : ''"
                                    :loading="statsLoading.stats" />
                            </div>

                            <!-- Precios preferenciales (tabla mejorada) -->
                            <div>
                                <div class="mb-3 flex items-center justify-between">
                                    <h3 class="text-sm font-bold text-gray-700">Precios preferenciales</h3>
                                    <button @click="showAddPrice = true; priceForm.reset();" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Agregar precio
                                    </button>
                                </div>

                                <div v-if="showAddPrice" class="mb-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-200">
                                    <form @submit.prevent="submitPrice" class="space-y-3">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-gray-500">Producto</label>
                                            <select v-model="priceForm.product_id" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                                <option value="">Seleccionar producto...</option>
                                                <option v-for="p in availableProducts" :key="p.id" :value="p.id">{{ p.name }} — {{ money(p.price) }}</option>
                                            </select>
                                            <p v-if="priceForm.errors.product_id" class="mt-1 text-xs text-red-600">{{ priceForm.errors.product_id }}</p>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold text-gray-500">Precio preferencial</label>
                                            <input v-model.number="priceForm.price" type="number" step="0.01" min="0" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                            <p v-if="priceForm.errors.price" class="mt-1 text-xs text-red-600">{{ priceForm.errors.price }}</p>
                                        </div>
                                        <div class="flex gap-3">
                                            <button type="submit" :disabled="priceForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Asignar</button>
                                            <button type="button" @click="showAddPrice = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                                        </div>
                                    </form>
                                </div>

                                <div v-if="selected.prices && selected.prices.length > 0" class="space-y-2">
                                    <template v-for="pp in selected.prices" :key="pp.id">
                                        <!-- Edit mode: full-width editor -->
                                        <PriceEditor
                                            v-if="editingPriceId === pp.id"
                                            ref="priceEditorRef"
                                            :current-price="editPriceForm.price"
                                            :standard-price="pp.product ? pp.product.price : 0"
                                            :product-name="pp.product?.name || 'Producto eliminado'"
                                            :processing="editPriceForm.processing"
                                            :error-message="editPriceForm.errors.price || ''"
                                            @save="(v) => submitEditPrice(pp.id, v)"
                                            @cancel="editingPriceId = null" />

                                        <!-- Read mode: clickeable card row -->
                                        <div v-else
                                            class="group flex items-center gap-4 rounded-xl bg-white px-4 py-3 ring-1 ring-gray-100 transition hover:ring-gray-200 hover:shadow-sm">
                                            <div class="flex-1 min-w-0">
                                                <p class="truncate text-sm font-bold text-gray-900">{{ pp.product?.name || 'Producto eliminado' }}</p>
                                                <p class="mt-0.5 text-xs text-gray-500">
                                                    Estándar <span class="font-semibold tabular-nums text-gray-700">{{ pp.product ? money(pp.product.price) : '—' }}</span>
                                                </p>
                                            </div>

                                            <div class="text-right">
                                                <p :class="['text-xl font-bold tabular-nums',
                                                    pp.product && parseFloat(pp.price) < parseFloat(pp.product.price) ? 'text-green-700' :
                                                    pp.product && parseFloat(pp.price) > parseFloat(pp.product.price) ? 'text-red-700' :
                                                    'text-gray-900']">{{ money(pp.price) }}</p>
                                                <p v-if="priceSavings(pp) && priceSavings(pp).diff > 0" class="text-xs font-semibold text-green-600">
                                                    Ahorra {{ money(priceSavings(pp).diff) }} ({{ priceSavings(pp).pct.toFixed(1) }}%)
                                                </p>
                                                <p v-else-if="priceSavings(pp) && priceSavings(pp).diff < 0" class="text-xs font-semibold text-red-500">
                                                    Sobre precio {{ priceSavings(pp).pct.toFixed(1) }}%
                                                </p>
                                                <p v-else class="text-xs text-gray-400">Mismo precio</p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-1.5">
                                                <button @click="startEditPrice(pp)"
                                                    class="flex h-10 items-center gap-1.5 rounded-lg bg-gray-100 px-3.5 text-xs font-bold text-gray-700 transition hover:bg-gray-200">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                                    Editar
                                                </button>
                                                <button @click="confirmDeletePriceId = pp.id"
                                                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50 text-red-600 transition hover:bg-red-100"
                                                    title="Eliminar precio preferencial">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div v-else-if="!showAddPrice" class="rounded-xl border border-dashed border-gray-200 px-6 py-12 text-center">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" /></svg>
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-gray-700">Sin precios preferenciales asignados</p>
                                    <p class="mt-1 text-xs text-gray-500">Configura precios especiales para este cliente.</p>
                                    <button @click="showAddPrice = true; priceForm.reset();" class="mt-4 h-10 rounded-lg bg-red-600 px-5 text-sm font-bold text-white transition hover:bg-red-700">
                                        Agregar el primero
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: COMPRAS -->
                        <div v-else-if="activeTab === 'compras'" class="p-6 space-y-5">
                            <DateRangePicker
                                v-model:from="historyFrom"
                                v-model:to="historyTo"
                                :loading="statsLoading.history"
                                @apply="applyHistoryFilters" />

                            <div v-if="statsLoading.history && !history" class="flex items-center justify-center gap-3 py-16 text-gray-400">
                                <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path></svg>
                                <span class="text-sm font-medium">Cargando compras...</span>
                            </div>

                            <template v-else-if="history">
                                <div v-if="history.data.length === 0" class="rounded-xl border border-dashed border-gray-200 px-6 py-16 text-center">
                                    <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                                    <p class="mt-3 text-sm font-medium text-gray-500">Sin compras en este periodo</p>
                                    <p class="mt-1 text-xs text-gray-400">Ajusta el rango de fechas para ver más.</p>
                                </div>
                                <div v-else class="overflow-hidden rounded-xl ring-1 ring-gray-100">
                                    <table class="min-w-full divide-y divide-gray-100">
                                        <thead class="bg-gray-50"><tr>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-gray-500">Fecha</th>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-gray-500">Folio</th>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-gray-500">Productos</th>
                                            <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-gray-500">Total</th>
                                            <th class="px-5 py-3 text-center text-[11px] font-bold uppercase tracking-wide text-gray-500">Pago</th>
                                            <th class="w-12"></th>
                                        </tr></thead>
                                        <tbody class="divide-y divide-gray-50 bg-white">
                                            <tr v-for="sale in history.data" :key="sale.id"
                                                class="group cursor-pointer transition hover:bg-red-50/30"
                                                @click="openSaleModal(sale.id)">
                                                <td class="px-5 py-4 text-sm text-gray-700">{{ fmtDateTime(sale.created_at) }}</td>
                                                <td class="px-5 py-4 text-sm font-bold text-gray-900">{{ sale.folio }}</td>
                                                <td class="px-5 py-4 text-sm text-gray-600">
                                                    <div class="line-clamp-1">{{ (sale.items || []).map(i => i.product_name).slice(0,3).join(', ') }}{{ (sale.items || []).length > 3 ? '…' : '' }}</div>
                                                    <div class="mt-0.5 text-xs text-gray-400">{{ (sale.items || []).length }} producto{{ (sale.items || []).length !== 1 ? 's' : '' }}</div>
                                                </td>
                                                <td class="px-5 py-4 text-right text-sm font-bold tabular-nums text-gray-900">{{ money(sale.total) }}</td>
                                                <td class="px-5 py-4 text-center">
                                                    <span :class="['inline-block rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset',
                                                        paymentBadge(sale).cls, paymentBadge(sale).ring]">{{ paymentBadge(sale).text }}</span>
                                                </td>
                                                <td class="pr-5 text-gray-300 transition group-hover:text-red-500">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <div v-if="history.last_page > 1" class="flex items-center justify-between">
                                    <p class="text-xs text-gray-500">Página <span class="font-semibold text-gray-700">{{ history.current_page }}</span> de {{ history.last_page }} · {{ history.total }} compras</p>
                                    <div class="flex gap-1.5">
                                        <button v-for="link in history.links" :key="link.label"
                                            @click="goToHistoryPage(link.url)"
                                            :disabled="!link.url || link.active"
                                            v-html="link.label"
                                            :class="['h-9 min-w-[36px] rounded-lg px-3 text-xs font-bold transition',
                                                link.active ? 'bg-red-600 text-white' :
                                                link.url ? 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50' :
                                                'bg-gray-50 text-gray-300 cursor-not-allowed']">
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- TAB: PRODUCTOS -->
                        <div v-else-if="activeTab === 'productos'" class="p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-bold text-gray-700">Top productos</h3>
                                <div class="inline-flex rounded-lg bg-gray-100 p-0.5">
                                    <button @click="topProductsView = 'quantity'" :class="['rounded-md px-3 py-1 text-xs font-semibold transition', topProductsView === 'quantity' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">Por cantidad</button>
                                    <button @click="topProductsView = 'spent'" :class="['rounded-md px-3 py-1 text-xs font-semibold transition', topProductsView === 'spent' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">Por gasto</button>
                                </div>
                            </div>

                            <div v-if="statsLoading.topProducts" class="py-10 text-center text-sm text-gray-400">Cargando...</div>

                            <template v-else-if="topProducts">
                                <div v-if="topProductsSorted.length === 0" class="rounded-xl border border-dashed border-gray-200 px-6 py-10 text-center text-sm text-gray-400">
                                    Sin productos registrados.
                                </div>
                                <div v-else class="space-y-2">
                                    <div v-for="(p, i) in topProductsSorted" :key="p.product_id" class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600">{{ i + 1 }}</span>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center justify-between gap-3">
                                                    <p class="truncate text-sm font-semibold text-gray-900">{{ p.product_name }}</p>
                                                    <p class="text-sm font-bold tabular-nums text-gray-900">
                                                        {{ topProductsView === 'spent' ? money(p.total_spent) : qty(p.total_quantity, p.unit_type) }}
                                                    </p>
                                                </div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                                    <div class="h-full rounded-full bg-red-500 transition-all"
                                                        :style="{ width: ((topProductsView === 'spent' ? p.total_spent : p.total_quantity) / topProductsMax * 100) + '%' }"></div>
                                                </div>
                                                <div class="mt-1 flex justify-between text-[11px] text-gray-500">
                                                    <span>{{ p.times_bought }} compra{{ p.times_bought !== 1 ? 's' : '' }}</span>
                                                    <span v-if="p.total_saved > 0" class="text-green-600 font-semibold">ahorró {{ money(p.total_saved) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- TAB: FINANZAS -->
                        <div v-else-if="activeTab === 'finanzas'" class="p-6 space-y-6">
                            <!-- Flash local toast -->
                            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0 -translate-y-1" leave-to-class="opacity-0">
                                <div v-if="flashMessage" class="flex items-center gap-3 rounded-xl bg-green-50 px-4 py-3 ring-1 ring-green-200">
                                    <svg class="h-5 w-5 shrink-0 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    <p class="text-sm font-semibold text-green-800">{{ flashMessage }}</p>
                                </div>
                            </Transition>

                            <div v-if="statsLoading.payments && !payments" class="flex items-center justify-center gap-3 py-16 text-gray-400">
                                <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path></svg>
                                <span class="text-sm font-medium">Cargando finanzas...</span>
                            </div>

                            <template v-else-if="payments">
                                <!-- Action row: Registrar pago button -->
                                <div v-if="stats?.pending_sales_count > 0" class="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-bold text-gray-900">Registrar pago del cliente</h3>
                                        <p class="mt-0.5 text-xs text-gray-500">Distribuye un abono entre las ventas más antiguas (FIFO).</p>
                                    </div>
                                    <button @click="openCustomerPaymentModal"
                                        :disabled="!stats?.current_user_shift_open"
                                        :title="!stats?.current_user_shift_open ? 'Necesitas abrir un turno para registrar pagos' : ''"
                                        class="flex h-11 shrink-0 items-center gap-2 rounded-lg bg-red-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:shadow-none">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Registrar pago
                                    </button>
                                </div>
                                <p v-if="stats?.pending_sales_count > 0 && !stats?.current_user_shift_open" class="-mt-3 text-xs font-semibold text-amber-700">
                                    ⚠ Abre un turno para poder registrar pagos
                                </p>

                                <div class="grid grid-cols-2 gap-3">
                                    <StatCard label="Adeudado" :value="money(payments.total_owed)" :tone="payments.total_owed > 0 ? 'negative' : 'positive'" :hint="payments.pending_sales.length ? `${payments.pending_sales.length} venta${payments.pending_sales.length !== 1 ? 's' : ''} con saldo` : 'Al corriente'" />
                                    <StatCard label="Total pagado histórico" :value="stats ? money(stats.total_paid) : money(0)" tone="positive" />
                                </div>

                                <!-- Ventas pendientes -->
                                <div>
                                    <div class="mb-3 flex items-center justify-between">
                                        <h3 class="text-sm font-bold text-gray-700">Ventas con saldo</h3>
                                        <span v-if="payments.pending_sales.length" class="text-xs text-gray-500">Click en una fila para ver detalle</span>
                                    </div>
                                    <div v-if="payments.pending_sales.length === 0" class="rounded-xl border border-dashed border-green-200 bg-green-50/30 px-6 py-10 text-center">
                                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600">
                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-gray-700">Este cliente está al corriente</p>
                                        <p class="mt-0.5 text-xs text-gray-500">Todas sus ventas están saldadas.</p>
                                    </div>
                                    <div v-else class="overflow-hidden rounded-xl ring-1 ring-gray-100">
                                        <table class="min-w-full divide-y divide-gray-100">
                                            <thead class="bg-gray-50"><tr>
                                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-gray-500">Fecha</th>
                                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wide text-gray-500">Folio</th>
                                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-gray-500">Total</th>
                                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-gray-500">Pagado</th>
                                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wide text-gray-500">Saldo</th>
                                                <th class="w-12"></th>
                                            </tr></thead>
                                            <tbody class="divide-y divide-gray-50 bg-white">
                                                <tr v-for="s in payments.pending_sales" :key="s.id"
                                                    class="group cursor-pointer transition hover:bg-red-50/40"
                                                    @click="openSaleModal(s.id)">
                                                    <td class="px-5 py-4 text-sm text-gray-700">{{ fmtDate(s.created_at) }}</td>
                                                    <td class="px-5 py-4 text-sm font-bold text-gray-900">{{ s.folio }}</td>
                                                    <td class="px-5 py-4 text-right text-sm tabular-nums text-gray-700">{{ money(s.total) }}</td>
                                                    <td class="px-5 py-4 text-right text-sm tabular-nums text-gray-600">{{ money(s.amount_paid) }}</td>
                                                    <td class="px-5 py-4 text-right text-sm font-bold tabular-nums text-red-700">{{ money(s.amount_pending) }}</td>
                                                    <td class="pr-5 text-gray-300 transition group-hover:text-red-500">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Historial de movimientos (mixto) -->
                                <div>
                                    <h3 class="mb-3 text-sm font-bold text-gray-700">Últimos movimientos</h3>
                                    <div v-if="!payments.recent_movements || payments.recent_movements.length === 0" class="rounded-xl border border-dashed border-gray-200 px-6 py-10 text-center text-sm text-gray-400">
                                        Sin pagos registrados.
                                    </div>
                                    <ul v-else class="space-y-2">
                                        <!-- Global cobro (parent) -->
                                        <li v-for="m in payments.recent_movements" :key="`${m.type}-${m.id}`">
                                            <div v-if="m.type === 'global'"
                                                class="group flex cursor-pointer items-center gap-4 rounded-xl bg-gradient-to-br from-amber-50/60 to-red-50/40 px-5 py-4 ring-1 ring-amber-100 transition hover:ring-amber-200 hover:shadow-sm"
                                                @click="openGlobalDetailModal(m.id)">
                                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" /></svg>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-sm font-bold text-gray-900">Cobro global {{ m.folio }}</p>
                                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-200">
                                                            {{ m.sales_affected_count }} venta{{ m.sales_affected_count !== 1 ? 's' : '' }}
                                                        </span>
                                                    </div>
                                                    <p class="mt-0.5 text-xs text-gray-500">
                                                        {{ methodLabel(m.method) }} · {{ fmtDateTime(m.created_at) }}<span v-if="m.cashier_name"> · {{ m.cashier_name }}</span>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <p class="text-base font-bold tabular-nums text-gray-900">{{ money(m.amount_applied) }}</p>
                                                    <p v-if="m.change_given > 0" class="text-[11px] font-semibold text-amber-700">cambio {{ money(m.change_given) }}</p>
                                                </div>
                                                <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                            </div>

                                            <!-- Single payment -->
                                            <div v-else
                                                class="group flex cursor-pointer items-center gap-4 rounded-xl bg-white px-5 py-3.5 ring-1 ring-gray-100 transition hover:bg-red-50/30 hover:ring-gray-200"
                                                @click="openSaleModal(m.sale_id)">
                                                <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                                                    m.method === 'cash' ? 'bg-green-50 text-green-700' :
                                                    m.method === 'card' ? 'bg-blue-50 text-blue-700' :
                                                    'bg-purple-50 text-purple-700']">
                                                    <svg v-if="m.method === 'cash'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                                    <svg v-else-if="m.method === 'card'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>
                                                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-bold text-gray-900">{{ methodLabel(m.method) }} · {{ money(m.amount) }}</p>
                                                    <p class="text-xs text-gray-500">
                                                        Venta {{ m.sale_folio }} · {{ fmtDateTime(m.created_at) }}<span v-if="m.cashier_name"> · {{ m.cashier_name }}</span>
                                                    </p>
                                                </div>
                                                <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Create customer modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showCreateModal = false">
                    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl" @click.stop>
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-base font-bold text-gray-900">Nuevo Cliente</h3>
                        </div>
                        <form @submit.prevent="submitCreate" class="px-6 py-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Nombre</label>
                                <input v-model="createForm.name" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-600">{{ createForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Telefono</label>
                                <input v-model="createForm.phone" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="createForm.errors.phone" class="mt-1 text-xs text-red-600">{{ createForm.errors.phone }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Notas (opcional)</label>
                                <textarea v-model="createForm.notes" rows="2" class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
                                <button type="button" @click="showCreateModal = false" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancelar</button>
                                <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Registrar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Delete confirm -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="confirmDelete = false">
                    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-center" @click.stop>
                        <p class="text-sm font-semibold text-gray-900">Eliminar cliente "{{ selected?.name }}"?</p>
                        <p class="mt-1 text-xs text-gray-500">Si tiene ventas asociadas, sera desactivado en vez de eliminado.</p>
                        <div class="mt-4 flex justify-center gap-3">
                            <button @click="confirmDelete = false" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancelar</button>
                            <button @click="deleteCustomer" class="rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700">Eliminar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Confirm delete price -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDeletePriceId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="confirmDeletePriceId = null">
                    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-center" @click.stop>
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126Z" /></svg>
                        </div>
                        <p class="mt-3 text-sm font-bold text-gray-900">¿Eliminar precio preferencial?</p>
                        <p class="mt-1 text-xs text-gray-500">Al eliminar, este cliente pagará el precio estándar del catálogo en futuras ventas.</p>
                        <div class="mt-5 flex gap-2">
                            <button @click="confirmDeletePriceId = null" class="h-10 flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cancelar</button>
                            <button @click="deletePrice(confirmDeletePriceId)" class="h-10 flex-1 rounded-lg bg-red-600 px-4 text-sm font-bold text-white hover:bg-red-700">Eliminar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Sale detail modal -->
        <SaleDetailModal
            :show="saleModalOpen"
            :tenant-slug="tenant.slug"
            :customer-id="selected?.id"
            :sale-id="saleModalSaleId"
            @close="closeSaleModal" />

        <!-- Customer payment (cobro global) modal -->
        <CustomerPaymentModal
            :show="customerPaymentModalOpen"
            :tenant-slug="tenant.slug"
            :customer="selected"
            :pending-sales="payments?.pending_sales || []"
            :allowed-methods="allowedPaymentMethods"
            :shift-open="stats?.current_user_shift_open ?? false"
            @close="closeCustomerPaymentModal"
            @success="onGlobalPaymentSuccess" />

        <!-- Global payment detail modal -->
        <GlobalPaymentDetailModal
            :show="globalDetailModalOpen"
            :tenant-slug="tenant.slug"
            :customer-id="selected?.id"
            :customer-payment-id="globalDetailModalId"
            @close="closeGlobalDetailModal"
            @open-sale="openSaleFromDetail"
            @cancelled="onGlobalPaymentCancelled" />

        <FlashToast />
    </SucursalLayout>
</template>
