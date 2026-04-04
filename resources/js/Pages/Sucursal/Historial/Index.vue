<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CancelSaleDialog from '@/Components/CancelSaleDialog.vue';
import SaleContextMenu from '@/Components/SaleContextMenu.vue';
import EditPaymentForm from '@/Components/EditPaymentForm.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { useSaleActions } from '@/composables/useSaleActions';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    sales: Object, filters: Object, tenant: Object,
    paymentMethods: Array, canEditPayments: Boolean, canCancel: Boolean, canManageStatus: Boolean,
});

const methodMeta = {
    cash:     { label: 'Efectivo',       color: 'text-emerald-600', iconBg: 'bg-emerald-100 text-emerald-600',
                icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    card:     { label: 'Tarjeta',        color: 'text-blue-600',    iconBg: 'bg-blue-100 text-blue-600',
                icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    transfer: { label: 'Transferencia',  color: 'text-violet-600',  iconBg: 'bg-violet-100 text-violet-600',
                icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
};
const enabledMethods = computed(() =>
    (props.paymentMethods || ['cash', 'card', 'transfer']).map(id => ({ id, label: methodMeta[id]?.label }))
);

// --- Filters ---
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const date = ref(props.filters?.date || '');

// --- Accumulated sales list ---
const allSales = ref([...props.sales.data]);
const nextCursor = ref(props.sales.next_cursor || null);
const loadingMore = ref(false);
const hasMore = computed(() => nextCursor.value !== null);

watch(() => props.sales, (newSales) => {
    if (loadingMore.value) return; // Don't reset during infinite scroll append
    allSales.value = [...newSales.data];
    nextCursor.value = newSales.next_cursor || null;
    if (selectedId.value && !allSales.value.find(s => s.id === selectedId.value)) {
        selectedId.value = null;
        selected.value = null;
    }
});

// --- Filter application ---
let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        selectedId.value = null;
        selected.value = null;
        router.get(route('sucursal.historial.index', props.tenant.slug), {
            search: search.value || undefined,
            status: status.value || undefined,
            date: date.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};

watch(search, applyFilters);
watch(status, () => { clearTimeout(debounceTimer); applyFilters(); });
watch(date, () => { clearTimeout(debounceTimer); applyFilters(); });

// --- Infinite scroll ---
const loadMore = () => {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    router.get(route('sucursal.historial.index', props.tenant.slug), {
        cursor: nextCursor.value,
        search: search.value || undefined,
        status: status.value || undefined,
        date: date.value || undefined,
    }, {
        preserveState: true, preserveScroll: true, only: ['sales'],
        onSuccess: () => {
            const newSales = props.sales;
            if (newSales?.data) {
                const existingIds = new Set(allSales.value.map(s => s.id));
                const unique = newSales.data.filter(s => !existingIds.has(s.id));
                allSales.value.push(...unique);
                nextCursor.value = newSales.next_cursor || null;
            }
            loadingMore.value = false;
        },
        onError: () => { loadingMore.value = false; },
    });
};

const listRef = ref(null);
const onScroll = () => {
    const el = listRef.value;
    if (!el || loadingMore.value || !hasMore.value) return;
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 100) loadMore();
};

// --- Formatters ---
const formatTime = (d) => new Date(d).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
const formatFullDate = (d) => {
    const dt = new Date(d);
    const day = dt.toLocaleDateString('es-MX', { weekday: 'long' });
    const rest = dt.toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' });
    const time = dt.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
    return `${day.charAt(0).toUpperCase() + day.slice(1)} ${rest}, ${time}`;
};

const statusBadge = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada', cls: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });

const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';

const paidPct = computed(() => {
    if (!selected.value) return 0;
    return selected.value.total > 0 ? Math.min((parseFloat(selected.value.amount_paid) / parseFloat(selected.value.total)) * 100, 100) : 0;
});

// --- Selection ---
const selectedId = ref(null);
const selected = ref(null);
const selectSale = (sale) => { selectedId.value = sale.id; selected.value = sale; editingPaymentId.value = null; };

// --- Payment editing ---
const editingPaymentId = ref(null);
const startEditPayment = (p) => { editingPaymentId.value = p.id; };
const editPaymentRoute = (paymentId) => route('sucursal.workbench.payment.update', [props.tenant.slug, selected.value.id, paymentId]);

const reloadSelected = () => {
    router.reload({ only: ['sales'], preserveScroll: true, onSuccess: () => {
        const updated = allSales.value.find(s => s.id === selectedId.value);
        if (updated) selected.value = updated;
    }});
};

const onPaymentSaved = () => { editingPaymentId.value = null; reloadSelected(); };

// --- Payment deletion ---
const confirmDeletePaymentId = ref(null);
const doDeletePayment = () => {
    if (!confirmDeletePaymentId.value) return;
    router.delete(route('sucursal.workbench.payment.destroy', [props.tenant.slug, selected.value.id, confirmDeletePaymentId.value]), {
        preserveScroll: true,
        onSuccess: () => { confirmDeletePaymentId.value = null; reloadSelected(); },
    });
};

// --- Status actions via unified endpoint ---
const { processing: statusProcessing, pauseSale, reactivateSale, cancelSale: cancelViaSaleActions, reopenSale: reopenViaSaleActions } = useSaleActions(
    route('sucursal.workbench.update-status', [props.tenant.slug, '__SALE__']),
);

const contextMenuSaleId = ref(null);

const handlePause = (saleId) => pauseSale(saleId, { onSuccess: reloadSelected });
const handleReactivate = (saleId) => reactivateSale(saleId, { onSuccess: reloadSelected });
const handleReopen = (saleId) => reopenViaSaleActions(saleId, { onSuccess: reloadSelected });
const handleCancelFromMenu = (saleId) => { contextMenuSaleId.value = saleId; showCancelDialog.value = true; };

// --- Cancel sale ---
const showCancelDialog = ref(false);
const cancelProcessing = ref(false);
const cancelSale = (reason) => {
    const saleId = contextMenuSaleId.value || selected.value?.id;
    if (!saleId) return;
    cancelProcessing.value = true;
    cancelViaSaleActions(saleId, reason, {
        onSuccess: () => { showCancelDialog.value = false; contextMenuSaleId.value = null; reloadSelected(); },
        onFinish: () => { cancelProcessing.value = false; },
    });
};

// --- Reopen sale (legacy, kept for compatibility) ---
const reopenSale = () => {
    reopenViaSaleActions(selected.value.id, { onSuccess: reloadSelected });
};
</script>

<template>
    <Head title="Historial de Ventas" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Historial de Ventas</h1>
        </template>

        <div class="flex h-[calc(100vh-8rem)] gap-5">
            <!-- LEFT: Sales list -->
            <div class="flex w-[420px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="space-y-3 border-b border-gray-100 px-5 py-4">
                    <div class="flex gap-3">
                        <div class="relative flex-1">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <input v-model="search" type="text" placeholder="Buscar folio..." class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <DatePicker v-model="date" />
                    </div>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'',l:'Todas'},{v:'active',l:'Activas'},{v:'pending',l:'Pendientes'},{v:'completed',l:'Cobradas'},{v:'cancelled',l:'Canceladas'}]"
                            :key="f.v" @click="status = f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', status === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }}
                        </button>
                    </div>
                </div>

                <div ref="listRef" @scroll="onScroll" class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in allSales" :key="sale.id" @click="selectSale(sale)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all', selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                                <span :class="[statusBadge(sale.status).cls, 'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(sale.status).label }}</span>
                            </div>
                            <SaleContextMenu
                                :sale="sale"
                                :allowed-actions="canManageStatus ? ['pause', 'reactivate', 'reopen', 'cancel'] : []"
                                @pause="handlePause(sale.id)"
                                @reactivate="handleReactivate(sale.id)"
                                @reopen="handleReopen(sale.id)"
                                @cancel="handleCancelFromMenu(sale.id)"
                            />
                        </div>
                        <div class="mt-2 flex items-end justify-between">
                            <div>
                                <p class="text-lg font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                                <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                            </div>
                            <span class="text-xs text-gray-400">{{ formatTime(sale.created_at) }}</span>
                        </div>
                    </div>

                    <div v-if="loadingMore" class="flex justify-center py-4">
                        <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" /></svg>
                    </div>
                    <p v-if="!hasMore && allSales.length > 0" class="py-3 text-center text-xs text-gray-300">No hay mas ventas.</p>
                    <div v-if="allSales.length === 0 && !loadingMore" class="py-16 text-center text-sm text-gray-400">No se encontraron ventas.</div>
                </div>
            </div>

            <!-- RIGHT: Detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona una venta para ver el detalle</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                            <span :class="[statusBadge(selected.status).cls, 'rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(selected.status).label }}</span>
                            <span :class="[originBadge(selected.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ formatFullDate(selected.created_at) }}</p>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Items -->
                        <div>
                            <h3 class="mb-3 text-sm font-bold text-gray-700">Productos</h3>
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
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="rounded-xl ring-1 ring-gray-200/50 overflow-hidden">
                            <div class="px-5 py-4">
                                <div class="grid grid-cols-3 gap-4 mb-4">
                                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Total</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-emerald-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-500">Pagado</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-emerald-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg px-3 py-2.5 text-center" :class="parseFloat(selected.amount_pending) > 0 ? 'bg-amber-50' : 'bg-gray-50'">
                                        <p class="text-[10px] font-medium uppercase tracking-wider" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-500' : 'text-gray-400'">Pendiente</p>
                                        <p class="font-mono text-lg font-bold tabular-nums" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p>
                                    </div>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div class="h-full rounded-full transition-all duration-500" :class="paidPct >= 100 ? 'bg-emerald-500' : 'bg-amber-500'" :style="{ width: Math.max(paidPct, 2) + '%' }" />
                                </div>
                            </div>
                        </div>

                        <!-- Payments -->
                        <div v-if="selected.payments && selected.payments.length > 0">
                            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">
                                Pagos
                                <span class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-500">{{ selected.payments.length }}</span>
                            </h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id"
                                    :class="editingPaymentId === p.id ? 'rounded-xl bg-white p-4 ring-2 ring-red-100 shadow-sm' : 'rounded-lg bg-gray-50 px-4 py-3'">
                                    <!-- Edit mode -->
                                    <EditPaymentForm v-if="editingPaymentId === p.id"
                                        :payment="p"
                                        :update-route="editPaymentRoute(p.id)"
                                        :payment-methods="paymentMethods"
                                        @saved="onPaymentSaved"
                                        @cancel="editingPaymentId = null" />

                                    <!-- Display mode -->
                                    <div v-else class="flex items-center gap-3">
                                        <div :class="[methodMeta[p.method]?.iconBg, 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg']">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[p.method]?.icon" /></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center gap-2">
                                                    <span :class="[methodMeta[p.method]?.color, 'text-sm font-semibold']">{{ methodMeta[p.method]?.label }}</span>
                                                    <span v-if="p.user" class="text-xs text-gray-400">{{ p.user.name }}</span>
                                                    <span v-if="p.updated_by" class="rounded-full bg-orange-50 px-1.5 py-0.5 text-[10px] font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20">Editado{{ p.updated_by_user ? ': ' + p.updated_by_user.name : '' }}</span>
                                                </div>
                                                <div class="flex items-center gap-3">
                                                    <span class="font-mono text-sm font-bold tabular-nums text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                                    <template v-if="canEditPayments">
                                                        <button @click="startEditPayment(p)" class="text-xs font-semibold text-orange-600 hover:text-orange-700">Editar</button>
                                                        <button @click="confirmDeletePaymentId = p.id" class="text-xs font-semibold text-red-500 hover:text-red-700">Eliminar</button>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cancelled info -->
                        <div v-if="selected.status === 'cancelled' && selected.cancelled_at" class="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
                            <p class="text-sm font-semibold text-red-900">Venta cancelada</p>
                            <p v-if="selected.cancel_reason" class="mt-0.5 text-xs text-red-600">Motivo: {{ selected.cancel_reason }}</p>
                            <p class="mt-0.5 text-xs text-red-600/70">{{ new Date(selected.cancelled_at).toLocaleString('es-MX') }}</p>
                        </div>
                    </div>

                    <!-- Admin actions footer -->
                    <div v-if="canManageStatus && selected.status !== 'cancelled'" class="border-t-2 border-gray-200 bg-gray-50 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p v-if="selected.status === 'completed'" class="text-sm font-bold text-green-700">Venta cobrada</p>
                                <p v-else-if="selected.status === 'pending'" class="text-sm font-bold text-amber-700">Venta pendiente</p>
                                <p v-else class="text-sm font-bold text-blue-700">Venta activa</p>
                            </div>
                            <SaleContextMenu
                                :sale="selected"
                                :allowed-actions="canManageStatus ? ['pause', 'reactivate', 'reopen', 'cancel'] : []"
                                @pause="handlePause(selected.id)"
                                @reactivate="handleReactivate(selected.id)"
                                @reopen="handleReopen(selected.id)"
                                @cancel="handleCancelFromMenu(selected.id)"
                            />
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Dialogs -->
        <ConfirmDialog v-if="confirmDeletePaymentId"
            title="Eliminar pago"
            message="El pago se eliminara y los montos de la venta se recalcularan automaticamente."
            confirm-label="Eliminar"
            variant="danger"
            @confirm="doDeletePayment"
            @cancel="confirmDeletePaymentId = null" />

        <CancelSaleDialog v-if="showCancelDialog"
            :folio="(contextMenuSaleId ? allSales.find(s => s.id === contextMenuSaleId)?.folio : selected?.folio) || ''"
            :mode="canCancel ? 'direct' : 'request'"
            :processing="cancelProcessing"
            :is-completed="(contextMenuSaleId ? allSales.find(s => s.id === contextMenuSaleId)?.status : selected?.status) === 'completed'"
            @confirm="cancelSale"
            @cancel="showCancelDialog = false; contextMenuSaleId = null" />

        <FlashToast />
    </SucursalLayout>
</template>
