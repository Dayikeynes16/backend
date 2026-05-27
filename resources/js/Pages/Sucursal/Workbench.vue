<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CancelSaleDialog from '@/Components/CancelSaleDialog.vue';
import SaleContextMenu from '@/Components/SaleContextMenu.vue';
import SaleDetail from '@/Components/Sucursal/SaleDetail.vue';
import SaleDetailModalShell from '@/Components/SaleDetailModalShell.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { useSaleActions } from '@/composables/useSaleActions';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    sales: Array, products: Array, categories: Array,
    tenant: Object, branchId: Number, branchInfo: Object, paymentMethods: Array,
    canCreate: Boolean, canCancel: Boolean, canManageStatus: Boolean, canEditPayments: Boolean, canEditPrice: Boolean,
    customers: Array,
    /** disabled | optional | required — gobierna el campo "motivo" en los modales de items. */
    saleItemEditReasonMode: { type: String, default: 'optional' },
});

const statusFilter = ref('active');
const filteredSales = computed(() => {
    if (statusFilter.value === 'all') return props.sales;
    return props.sales.filter(s => s.status === statusFilter.value);
});
const counts = computed(() => ({
    active: props.sales.filter(s => s.status === 'active').length,
    pending: props.sales.filter(s => s.status === 'pending').length,
    all: props.sales.length,
}));

// Venta seleccionada → su detalle se abre en un modal grande.
const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value) || null);
const showDetail = computed(() => !!selected.value);
const detailDirty = ref(false); // monto capturado sin cobrar → confirmar antes de cerrar el modal

// Real-time
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
const { lockSale, unlockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    usePage().props.auth.user.id,
    route('sucursal.sale.lock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

// Status actions
const { pauseSale, reactivateSale, cancelSale: cancelViaSaleActions, reopenSale } = useSaleActions(
    route('sucursal.workbench.update-status', [props.tenant.slug, '__SALE__']),
);

const contextMenuSaleId = ref(null);

// Seleccionar una venta: la bloquea y abre el modal de detalle.
const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
    }
};
// Cerrar el modal: libera el bloqueo y limpia la selección.
const closeDetail = () => {
    unlockSale();
    selectedId.value = null;
};

// Acciones rápidas desde la lista: bloquean la venta pero no abren el modal.
const handlePause = async (saleId) => {
    const ok = await lockSale(saleId);
    if (!ok) return;
    pauseSale(saleId, { onSuccess: () => { statusFilter.value = 'pending'; } });
};
const handleReactivate = async (saleId) => {
    const ok = await lockSale(saleId);
    if (!ok) return;
    reactivateSale(saleId, { onSuccess: () => { statusFilter.value = 'active'; } });
};
const handleReopen = (saleId) => reopenSale(saleId);
const handleCancelFromMenu = (saleId) => { contextMenuSaleId.value = saleId; showCancelDialog.value = true; };

// Recargar ventas tras una mutación dentro del modal (cobro, items, pagos, cliente…).
const reloadSales = () => router.reload({ only: ['sales'], preserveScroll: true });
// Al vincular pedido↔venta: el pedido web pasa a Fulfilled y deja la lista activa.
const onLinked = () => { unlockSale(); selectedId.value = null; reloadSales(); };

// Helpers (lista)
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const timeAgo = (date) => {
    const diff = Math.floor((Date.now() - new Date(date)) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return new Date(date).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

// New sale modal
const showNewSale = ref(false);
const cart = ref([]);
const cartCategory = ref('');
const filteredProducts = computed(() => {
    let p = props.products;
    if (cartCategory.value) p = p.filter(pr => pr.category_id == cartCategory.value);
    return p;
});
const addToCart = (product, presentation = null) => {
    const key = presentation ? `${product.id}-${presentation.id}` : `${product.id}`;
    const existing = cart.value.find(c => c.key === key);
    if (existing) { existing.quantity += 1; return; }
    const price = presentation ? parseFloat(presentation.price) : parseFloat(product.price);
    const name = presentation ? `${product.name} - ${presentation.name}` : product.name;
    const unitType = (product.sale_mode === 'weight' || (product.sale_mode === 'both' && !presentation)) ? 'kg' : product.unit_type;
    cart.value.push({ key, product_id: product.id, presentation_id: presentation?.id || null, name, price, originalPrice: price, unit_type: unitType, sale_mode: product.sale_mode, quantity: product.sale_mode === 'weight' ? 0 : 1, image_path: product.image_path });
};
const removeFromCart = (idx) => cart.value.splice(idx, 1);
const cartTotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.quantity, 0));
const newSaleForm = useForm({ items: [] });
const submitNewSale = () => {
    newSaleForm.items = cart.value.map(c => ({ product_id: c.product_id, quantity: c.quantity, presentation_id: c.presentation_id, custom_price: c.price }));
    newSaleForm.post(route('sucursal.workbench.store', props.tenant.slug), { onSuccess: () => { cart.value = []; showNewSale.value = false; } });
};

// Cancel sale (compartido entre lista y detalle)
const showCancelDialog = ref(false);
const cancelProcessing = ref(false);
const cancelSale = (reason) => {
    const saleId = contextMenuSaleId.value || selected.value?.id;
    if (!saleId) return;
    cancelProcessing.value = true;
    cancelViaSaleActions(saleId, reason, {
        onSuccess: () => { showCancelDialog.value = false; contextMenuSaleId.value = null; },
        onFinish: () => { cancelProcessing.value = false; },
    });
};
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <SucursalLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1></template>

        <div class="h-[calc(100vh-8rem)]">
            <!-- Lista de ventas (ancho completo) -->
            <div class="flex h-full flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-sm font-bold text-gray-900">Ventas</h2>
                        <div class="flex gap-1.5">
                            <button v-for="f in [{v:'active',l:'Activas'},{v:'pending',l:'Pendientes'},{v:'all',l:'Todas'}]"
                                :key="f.v" @click="statusFilter = f.v"
                                :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', statusFilter === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                                {{ f.l }} ({{ counts[f.v] }})
                            </button>
                        </div>
                    </div>
                    <button v-if="canCreate" @click="showNewSale = true" class="flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-red-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva Venta
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        <div v-for="sale in filteredSales" :key="sale.id" @click="selectSale(sale.id)"
                            :class="['cursor-pointer rounded-xl p-4 transition-all',
                                selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' :
                                isLockedByOther(sale.id) ? 'ring-1 ring-amber-200 bg-amber-50/30 opacity-75' :
                                sale.status === 'pending' ? 'ring-1 ring-amber-200 bg-amber-50/20' :
                                'ring-1 ring-gray-100 hover:ring-gray-200 hover:shadow-sm']">
                            <div class="flex items-center justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                                    <span v-if="sale.status === 'pending' && sale.origin === 'web'" class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800 ring-1 ring-inset ring-orange-600/30">🛒 Pedido web</span>
                                    <span v-else-if="sale.status === 'pending'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pendiente</span>
                                    <span v-if="sale.linked_order_id && sale.linked_order"
                                        class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/30"
                                        :title="`Vinculada al pedido web ${sale.linked_order.folio}`">
                                        🔗 {{ sale.linked_order.folio }}
                                    </span>
                                    <span v-else-if="sale.origin === 'web' && sale.status === 'fulfilled'"
                                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/30"
                                        :title="sale.fulfilled_by ? `Cumplido por venta ${sale.fulfilled_by.folio}` : 'Pedido cumplido'">
                                        ✓ Cumplido<span v-if="sale.fulfilled_by"> · {{ sale.fulfilled_by.folio }}</span>
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span v-if="isLockedByOther(sale.id)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                        {{ lockedByName(sale.id) || 'En uso' }}
                                    </span>
                                    <span v-else-if="sale.locked_by_user" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">
                                        {{ sale.locked_by_user.name }}
                                    </span>
                                    <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                                    <SaleContextMenu
                                        :sale="sale"
                                        :allowed-actions="['pause', 'reactivate', 'reopen', 'cancel']"
                                        :is-locked-by-other="isLockedByOther(sale.id)"
                                        :locked-by-name="lockedByName(sale.id)"
                                        @pause="handlePause(sale.id)"
                                        @reactivate="handleReactivate(sale.id)"
                                        @reopen="handleReopen(sale.id)"
                                        @cancel="handleCancelFromMenu(sale.id)"
                                        @request-cancel="handleCancelFromMenu(sale.id)"
                                    />
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
                    </div>
                    <div v-if="filteredSales.length === 0" class="flex flex-col items-center justify-center py-24 text-center">
                        <svg class="h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay ventas {{ statusFilter === 'pending' ? 'pendientes' : statusFilter === 'active' ? 'activas' : '' }}</p>
                        <p class="mt-1 text-xs text-gray-400">Las ventas nuevas aparecerán aquí automáticamente.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle de venta en modal grande -->
        <SaleDetailModalShell :show="showDetail" :dirty="detailDirty" @close="closeDetail">
            <template #default="{ requestClose }">
                <SaleDetail v-if="selected"
                    :key="selected.id"
                    :sale="selected"
                    :tenant-slug="tenant.slug"
                    :tenant="tenant"
                    :branch-info="branchInfo"
                    :payment-methods="paymentMethods"
                    :customers="customers"
                    :products="products"
                    :can-create="canCreate"
                    :can-cancel="canCancel"
                    :can-manage-status="canManageStatus"
                    :can-edit-payments="canEditPayments"
                    :can-edit-price="canEditPrice"
                    :sale-item-edit-reason-mode="saleItemEditReasonMode"
                    :is-locked-by-other="isLockedByOther(selected.id)"
                    :locked-by-name="lockedByName(selected.id) || ''"
                    @update:dirty="detailDirty = $event"
                    @close="requestClose"
                    @paid="reloadSales"
                    @mutated="reloadSales"
                    @pause="handlePause"
                    @reactivate="handleReactivate"
                    @reopen="handleReopen"
                    @cancel="handleCancelFromMenu"
                    @linked="onLinked" />
            </template>
        </SaleDetailModalShell>

        <!-- New Sale Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="showNewSale" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showNewSale = false">
                    <div class="flex h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl" @click.stop>
                        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                            <h2 class="text-base font-bold text-gray-900">Nueva Venta</h2>
                            <button @click="showNewSale = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
                        </div>
                        <div class="flex flex-1 overflow-hidden">
                            <div class="flex w-1/2 flex-col border-r border-gray-100">
                                <div class="flex gap-1.5 overflow-x-auto border-b border-gray-100 px-4 py-3">
                                    <button @click="cartCategory = ''" :class="['shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition', !cartCategory ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">Todos</button>
                                    <button v-for="cat in categories" :key="cat.id" @click="cartCategory = cat.id" :class="['shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition', cartCategory == cat.id ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">{{ cat.name }}</button>
                                </div>
                                <div class="flex-1 overflow-y-auto p-4">
                                    <div class="grid grid-cols-2 gap-3">
                                        <template v-for="p in filteredProducts" :key="p.id">
                                            <button v-if="p.sale_mode === 'weight'" @click="addToCart(p)" class="flex items-center gap-3 rounded-xl p-3 text-left ring-1 ring-gray-100 transition hover:bg-gray-50 hover:ring-gray-200">
                                                <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100"><img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" /><svg v-else class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5" /></svg></div>
                                                <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-gray-900">{{ p.name }}</p><p class="text-xs text-gray-500">${{ parseFloat(p.price).toFixed(2) }}/kg</p></div>
                                            </button>
                                            <template v-else>
                                                <button v-for="pres in p.presentations" :key="pres.id" @click="addToCart(p, pres)" class="flex items-center gap-3 rounded-xl p-3 text-left ring-1 ring-orange-100 transition hover:bg-orange-50/50 hover:ring-orange-200">
                                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-orange-50"><img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" /><svg v-else class="h-6 w-6 text-orange-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4" /></svg></div>
                                                    <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-gray-900">{{ p.name }}</p><p class="text-xs text-orange-600">{{ pres.name }} · ${{ parseFloat(pres.price).toFixed(2) }}</p></div>
                                                </button>
                                            </template>
                                        </template>
                                    </div>
                                    <div v-if="filteredProducts.length === 0" class="py-10 text-center text-sm text-gray-400">No hay productos.</div>
                                </div>
                            </div>
                            <div class="flex w-1/2 flex-col">
                                <div class="flex-1 overflow-y-auto p-4">
                                    <p class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Carrito</p>
                                    <div v-if="cart.length === 0" class="py-10 text-center text-sm text-gray-400">Agrega productos.</div>
                                    <div v-else class="space-y-2">
                                        <div v-for="(item, idx) in cart" :key="idx"
                                            class="group/card relative rounded-2xl bg-white px-4 py-3.5 shadow-[0_1px_3px_rgba(0,0,0,0.04)] ring-1 ring-gray-100 transition-shadow hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)]">
                                            <div class="flex items-center gap-3">
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-semibold text-gray-900">{{ item.name }}</p>
                                                    <div v-if="canEditPrice" class="mt-1.5 flex items-center gap-2">
                                                        <div class="inline-flex items-center rounded-full bg-gray-50 py-1 pl-2.5 pr-1.5 ring-1 ring-gray-200/80 transition-all focus-within:bg-red-50/50 focus-within:ring-red-300 focus-within:shadow-[0_0_0_3px_rgba(239,68,68,0.08)]">
                                                            <span class="mr-0.5 text-xs font-medium text-gray-400">$</span>
                                                            <input v-model.number="item.price" type="number" min="0" step="0.01"
                                                                class="w-16 border-0 bg-transparent p-0 text-xs font-semibold tabular-nums text-gray-900 focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                                            <svg class="h-3 w-3 shrink-0 text-gray-300 transition-colors group-focus-within/card:text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                                            </svg>
                                                        </div>
                                                        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-x-1" enter-to-class="opacity-100 translate-x-0" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 translate-x-0" leave-to-class="opacity-0 -translate-x-1">
                                                            <span v-if="item.price !== item.originalPrice" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-amber-600 ring-1 ring-amber-200/60">
                                                                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25" /></svg>
                                                                <span class="line-through">${{ item.originalPrice.toFixed(2) }}</span>
                                                            </span>
                                                        </Transition>
                                                    </div>
                                                    <p v-else class="mt-0.5 text-xs text-gray-400">${{ item.price.toFixed(2) }}</p>
                                                </div>
                                                <input v-model.number="item.quantity" type="number" min="0.01" step="0.01"
                                                    class="w-[4.5rem] rounded-xl border-0 bg-gray-50 py-1.5 text-center text-sm font-medium tabular-nums text-gray-700 ring-1 ring-gray-200/80 transition-all focus:bg-white focus:ring-red-300 focus:shadow-[0_0_0_3px_rgba(239,68,68,0.08)] [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                                <span class="w-20 text-right text-sm font-bold tabular-nums text-gray-900">${{ (item.price * item.quantity).toFixed(2) }}</span>
                                                <button @click="removeFromCart(idx)"
                                                    class="rounded-full p-1.5 text-gray-300 transition-colors hover:bg-red-50 hover:text-red-500 active:bg-red-100">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="border-t border-gray-100 px-6 py-4">
                                    <div class="flex items-center justify-between mb-4"><span class="text-sm font-medium text-gray-500">Total</span><span class="text-2xl font-bold text-gray-900">${{ cartTotal.toFixed(2) }}</span></div>
                                    <button @click="submitNewSale" :disabled="cart.length === 0 || newSaleForm.processing" class="w-full rounded-lg bg-red-600 py-3 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Crear Venta</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Cancelación (compartida lista + detalle) -->
        <CancelSaleDialog v-if="showCancelDialog"
            :folio="(contextMenuSaleId ? sales.find(s => s.id === contextMenuSaleId)?.folio : selected?.folio) || ''"
            :mode="canCancel ? 'direct' : 'request'"
            :processing="cancelProcessing"
            :is-completed="(contextMenuSaleId ? sales.find(s => s.id === contextMenuSaleId)?.status : selected?.status) === 'completed'"
            @confirm="cancelSale"
            @cancel="showCancelDialog = false; contextMenuSaleId = null" />

        <FlashToast />
    </SucursalLayout>
</template>
