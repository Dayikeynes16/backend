<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import SaleItemAddModal from '@/Components/Sucursal/SaleItemAddModal.vue';
import SaleItemEditModal from '@/Components/Sucursal/SaleItemEditModal.vue';
import SaleItemDeleteDialog from '@/Components/Sucursal/SaleItemDeleteDialog.vue';
import SaleItemHistoryModal from '@/Components/Sucursal/SaleItemHistoryModal.vue';

/**
 * Detalle de una venta del cliente, estilo slide-over derecho.
 *
 * Reutiliza los sub-componentes del rediseño de Mesa de Trabajo
 * (SaleItem{Add,Edit,Delete,History}Modal) para permitir edición inline
 * cuando la venta está Active/Pending y el admin tiene los permisos.
 *
 * Al abrir toma el lock vía /ventas/{sale}/lock (mismo endpoint que
 * Workbench) y al cerrar lo libera. Sin heartbeat: 5 min son suficientes
 * para una edición puntual.
 */
const props = defineProps({
    show: Boolean,
    tenantSlug: { type: String, required: true },
    customerId: { type: [Number, String], default: null },
    saleId: { type: [Number, String], default: null },
    products: { type: Array, default: () => [] },
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    saleItemEditReasonMode: { type: String, default: 'optional' },
});

const emit = defineEmits(['close', 'sale-changed']);

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);

const sale = ref(null);
const loading = ref(false);
const errorMsg = ref('');
const lockError = ref('');
let abortCtl = null;

const load = async () => {
    if (!props.customerId || !props.saleId) return;
    if (abortCtl) abortCtl.abort();
    abortCtl = new AbortController();
    loading.value = true;
    errorMsg.value = '';
    try {
        const res = await fetch(
            route('sucursal.clientes.venta-detalle', [props.tenantSlug, props.customerId, props.saleId]),
            { signal: abortCtl.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' },
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        sale.value = await res.json();
    } catch (e) {
        if (e.name !== 'AbortError') errorMsg.value = e.message || 'No se pudo cargar la venta.';
    } finally {
        loading.value = false;
    }
};

const isEditable = computed(() => sale.value && ['active', 'pending'].includes(sale.value.status));

const acquireLock = async () => {
    if (!sale.value || !isEditable.value) return;
    lockError.value = '';
    try {
        await axios.post(route('sucursal.sale.lock', [props.tenantSlug, sale.value.id]));
    } catch (e) {
        if (e.response?.status === 409) {
            lockError.value = `Esta venta está siendo operada por ${e.response.data?.locked_by_name || 'otro usuario'}.`;
        }
    }
};

const releaseLock = async () => {
    if (!sale.value || !isEditable.value) return;
    try {
        await axios.post(route('sucursal.sale.unlock', [props.tenantSlug, sale.value.id]));
    } catch (e) { /* silencioso */ }
};

watch(() => [props.show, props.saleId], async ([show]) => {
    if (show) {
        await load();
        await acquireLock();
    } else {
        await releaseLock();
        if (abortCtl) abortCtl.abort();
        sale.value = null;
        errorMsg.value = '';
        lockError.value = '';
        showAddItem.value = false;
        editingItem.value = null;
        deletingItem.value = null;
        showHistory.value = false;
    }
});

const canEditItems = computed(() => {
    if (!sale.value || !isEditable.value) return false;
    if (sale.value.locked_by && sale.value.locked_by !== currentUserId.value) return false;

    return true;
});

const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));
const formatDateTime = (iso) => iso ? new Date(iso).toLocaleString('es-MX', {
    day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true,
}) : '—';
const formatTime = (iso) => iso ? new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true }) : '';

const statusBadge = computed(() => {
    if (!sale.value) return null;
    const map = {
        active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
        pending: { label: 'Pendiente de pago', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
        completed: { label: 'Cobrada', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
        cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
    };

    return map[sale.value.status] || { label: sale.value.status, cls: 'bg-gray-100 text-gray-600' };
});

const methodMeta = {
    cash: { label: 'Efectivo', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' },
    card: { label: 'Tarjeta', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20', dot: 'bg-blue-500' },
    transfer: { label: 'Transferencia', cls: 'bg-violet-50 text-violet-700 ring-violet-600/20', dot: 'bg-violet-500' },
};

const paidPct = computed(() => {
    if (!sale.value || !sale.value.total) return 0;

    return Math.min(100, (Number(sale.value.amount_paid) / Number(sale.value.total)) * 100);
});

const hasItemHistory = computed(() => {
    if (!sale.value?.items) return false;

    return sale.value.items.some(i => i.deleted_at || i.updated_by);
});

const showAddItem = ref(false);
const editingItem = ref(null);
const deletingItem = ref(null);
const showHistory = ref(false);

const openAddItem = () => { showAddItem.value = true; };
const openEditItem = (item) => { editingItem.value = item; };
const openDeleteItem = (item) => { deletingItem.value = item; };

const refreshAfterItemChange = async () => {
    await load();
    emit('sale-changed');
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex justify-end bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
                <Transition enter-active-class="transition duration-200 ease-out" leave-active-class="transition duration-150 ease-in"
                    enter-from-class="translate-x-full" leave-to-class="translate-x-full">
                    <div v-if="show" class="flex h-full w-full max-w-2xl flex-col bg-gray-50 shadow-2xl" @click.stop>
                        <!-- Header -->
                        <header class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-white px-6 py-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-lg font-bold text-gray-900">{{ sale?.folio ? `Venta ${sale.folio}` : 'Cargando…' }}</h2>
                                    <span v-if="statusBadge"
                                        :class="[statusBadge.cls, 'rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset']">
                                        {{ statusBadge.label }}
                                    </span>
                                </div>
                                <p v-if="sale?.created_at" class="mt-0.5 text-xs text-gray-500">{{ formatDateTime(sale.created_at) }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a v-if="sale?.whatsapp_url" :href="sale.whatsapp_url" target="_blank" rel="noopener"
                                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700">
                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.2c5.46 0 9.91-4.44 9.91-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 1.65a8.25 8.25 0 0 1 5.85 14.1 8.25 8.25 0 0 1-5.85 2.41 8.21 8.21 0 0 1-4.21-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.25 8.25 0 0 1 7-12.65zm-3.27 4.6c-.16 0-.41.06-.62.31-.21.24-.83.81-.83 1.97 0 1.17.85 2.29.97 2.45.12.16 1.65 2.62 4.06 3.57 1.99.79 2.39.63 2.83.59.43-.04 1.4-.57 1.6-1.13.2-.55.2-1.03.14-1.13-.06-.1-.21-.16-.45-.28-.23-.12-1.39-.69-1.61-.77-.21-.08-.37-.12-.52.12-.16.24-.6.77-.74.93-.14.16-.27.18-.5.06-.23-.12-.97-.36-1.85-1.14-.69-.61-1.15-1.37-1.28-1.61-.14-.24-.01-.36.1-.48.11-.11.24-.27.35-.41.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.5-1.31-.7-1.79-.18-.46-.37-.4-.5-.41h-.41z" /></svg>
                                    WhatsApp
                                </a>
                                <button type="button" @click="$emit('close')"
                                    class="flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" aria-label="Cerrar">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </header>

                        <!-- Body -->
                        <div class="flex-1 overflow-y-auto px-6 py-5">
                            <div v-if="loading && !sale" class="flex items-center justify-center py-16 text-sm text-gray-400">
                                <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                Cargando…
                            </div>
                            <div v-else-if="errorMsg" class="rounded-2xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ errorMsg }}</div>

                            <div v-else-if="sale" class="space-y-5">
                                <!-- Banners -->
                                <div v-if="sale.status === 'completed'" class="rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                                    <div class="flex items-start gap-2.5">
                                        <svg class="h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                        <p class="text-xs font-medium text-amber-800">
                                            Esta venta ya está cobrada. Para modificar los items, primero elimina el pago desde la sección de Pagos.
                                        </p>
                                    </div>
                                </div>
                                <div v-else-if="sale.status === 'cancelled'" class="rounded-xl bg-red-50 px-4 py-3 ring-1 ring-red-200">
                                    <p class="text-xs font-medium text-red-800">Venta cancelada. No se puede modificar.</p>
                                </div>
                                <div v-else-if="lockError" class="rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                                    <p class="text-xs font-medium text-amber-800">{{ lockError }}</p>
                                </div>

                                <!-- Productos -->
                                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-sm font-bold text-gray-900">Productos</h3>
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">
                                                {{ (sale.items || []).filter(i => !i.deleted_at).length }}
                                            </span>
                                        </div>
                                        <button v-if="canEditItems" type="button" @click="openAddItem"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                            Agregar producto
                                        </button>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-50">
                                            <thead class="bg-gray-50/50">
                                                <tr>
                                                    <th class="px-5 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Producto</th>
                                                    <th class="px-5 py-2 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Cant.</th>
                                                    <th class="px-5 py-2 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Precio</th>
                                                    <th class="px-5 py-2 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Subtotal</th>
                                                    <th v-if="canEditItems" class="px-2 py-2"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50">
                                                <tr v-for="item in (sale.items || []).filter(i => !i.deleted_at)" :key="item.id" class="group">
                                                    <td class="px-5 py-2.5">
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="text-sm font-medium text-gray-900">{{ item.product_name }}</span>
                                                            <span v-if="item.updated_by"
                                                                class="rounded-full bg-orange-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-orange-700 ring-1 ring-inset ring-orange-600/20">
                                                                Editado
                                                            </span>
                                                        </div>
                                                        <p v-if="item.notes" class="mt-0.5 text-xs italic text-orange-700">💬 {{ item.notes }}</p>
                                                    </td>
                                                    <td class="whitespace-nowrap px-5 py-2.5 text-right text-sm tabular-nums text-gray-600">
                                                        {{ Number(item.quantity).toFixed(item.unit_type === 'kg' ? 3 : 0) }} {{ item.unit_type }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-5 py-2.5 text-right text-sm tabular-nums text-gray-600">{{ money(item.unit_price) }}</td>
                                                    <td class="whitespace-nowrap px-5 py-2.5 text-right text-sm font-semibold tabular-nums text-gray-900">{{ money(item.subtotal) }}</td>
                                                    <td v-if="canEditItems" class="whitespace-nowrap px-2 py-2.5">
                                                        <div class="flex items-center justify-end gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                                            <button type="button" @click="openEditItem(item)" title="Editar"
                                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-orange-50 hover:text-orange-600">
                                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                                            </button>
                                                            <button type="button" @click="openDeleteItem(item)" title="Eliminar"
                                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79" /></svg>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-if="hasItemHistory" class="border-t border-gray-100 bg-gray-50/40 px-5 py-2 text-right">
                                        <button type="button" @click="showHistory = true"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                            Ver historial de cambios
                                        </button>
                                    </div>
                                </section>

                                <!-- Pagos -->
                                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-sm font-bold text-gray-900">Pagos</h3>
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">{{ sale.payments?.length || 0 }}</span>
                                        </div>
                                    </div>
                                    <div v-if="!sale.payments?.length" class="px-5 py-6 text-center">
                                        <p class="text-sm text-gray-500">Aún no hay pagos registrados para esta venta.</p>
                                    </div>
                                    <ul v-else class="divide-y divide-gray-50">
                                        <li v-for="p in sale.payments" :key="p.id" class="flex items-center gap-3 px-5 py-3">
                                            <div :class="[methodMeta[p.method]?.cls || 'bg-gray-50 text-gray-600 ring-gray-300/50', 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-1 ring-inset']">
                                                <span :class="[methodMeta[p.method]?.dot || 'bg-gray-400', 'h-2 w-2 rounded-full']" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-semibold text-gray-900">{{ methodMeta[p.method]?.label || p.method }}</p>
                                                <p class="text-xs text-gray-500">
                                                    <span v-if="p.user">{{ p.user.name }} · </span>{{ formatTime(p.created_at) }}
                                                </p>
                                            </div>
                                            <span class="shrink-0 font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(p.amount) }}</span>
                                        </li>
                                    </ul>
                                </section>

                                <!-- Resumen financiero -->
                                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                                    <div class="grid grid-cols-3 divide-x divide-gray-100 border-b border-gray-100 text-center">
                                        <div class="px-3 py-3">
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Total</p>
                                            <p class="mt-1 font-mono text-base font-bold tabular-nums text-gray-900">{{ money(sale.total) }}</p>
                                        </div>
                                        <div class="px-3 py-3">
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Pagado</p>
                                            <p class="mt-1 font-mono text-base font-bold tabular-nums text-emerald-700">{{ money(sale.amount_paid) }}</p>
                                        </div>
                                        <div class="px-3 py-3" :class="Number(sale.amount_pending) > 0 ? 'bg-red-50/40' : ''">
                                            <p :class="['text-[10px] font-bold uppercase tracking-wider', Number(sale.amount_pending) > 0 ? 'text-red-700' : 'text-gray-500']">Pendiente</p>
                                            <p :class="['mt-1 font-mono text-base font-bold tabular-nums', Number(sale.amount_pending) > 0 ? 'text-red-700' : 'text-gray-400']">{{ money(sale.amount_pending) }}</p>
                                        </div>
                                    </div>
                                    <div class="px-5 py-3">
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <span class="font-semibold">Progreso de pago</span>
                                            <span class="font-bold text-gray-700">{{ Math.round(paidPct) }}%</span>
                                        </div>
                                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                                            <div class="h-full rounded-full transition-all"
                                                :class="paidPct >= 100 ? 'bg-emerald-500' : 'bg-red-500'"
                                                :style="{ width: Math.max(paidPct, 2) + '%' }" />
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>

        <!-- Modales hijos para edición de items -->
        <SaleItemAddModal v-if="sale"
            :show="showAddItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :products="products"
            :reason-mode="saleItemEditReasonMode"
            @close="showAddItem = false"
            @success="refreshAfterItemChange" />
        <SaleItemEditModal v-if="sale"
            :show="!!editingItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :item="editingItem"
            :reason-mode="saleItemEditReasonMode"
            @close="editingItem = null"
            @success="refreshAfterItemChange" />
        <SaleItemDeleteDialog v-if="sale"
            :show="!!deletingItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :item="deletingItem"
            @close="deletingItem = null"
            @success="refreshAfterItemChange" />
        <SaleItemHistoryModal v-if="sale"
            :show="showHistory"
            :tenant-slug="tenantSlug"
            :sale="sale"
            @close="showHistory = false" />
    </Teleport>
</template>
