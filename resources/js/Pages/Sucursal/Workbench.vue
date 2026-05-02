<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CancelSaleDialog from '@/Components/CancelSaleDialog.vue';
import SaleContextMenu from '@/Components/SaleContextMenu.vue';
import EditPaymentForm from '@/Components/EditPaymentForm.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { useSaleActions } from '@/composables/useSaleActions';
import { displayName as itemDisplayName, displayQuantity as itemDisplayQuantity, realContentDisplay as itemRealContentDisplay } from '@/composables/useSaleItemDisplay';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    sales: Array, products: Array, categories: Array,
    tenant: Object, branchId: Number, branchInfo: Object, paymentMethods: Array,
    canCreate: Boolean, canCancel: Boolean, canManageStatus: Boolean, canEditPayments: Boolean, canEditPrice: Boolean,
    customers: Array,
});

const allMethodLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };
const enabledMethods = computed(() =>
    (props.paymentMethods || ['cash', 'card', 'transfer']).map(id => ({ id, label: allMethodLabels[id] }))
);
const defaultMethod = computed(() => enabledMethods.value[0]?.id || 'cash');

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

const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value));
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
const { lockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    usePage().props.auth.user.id,
    route('sucursal.sale.lock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

// Status actions
const { processing: statusProcessing, pauseSale, reactivateSale, cancelSale: cancelViaSaleActions, reopenSale } = useSaleActions(
    route('sucursal.workbench.update-status', [props.tenant.slug, '__SALE__']),
);

const contextMenuSaleId = ref(null);

const handlePause = async (saleId) => {
    await selectSale(saleId);
    pauseSale(saleId, { onSuccess: () => { statusFilter.value = 'pending'; } });
};
const handleReactivate = async (saleId) => {
    await selectSale(saleId);
    reactivateSale(saleId, { onSuccess: () => { statusFilter.value = 'active'; } });
};
const handleReopen = (saleId) => reopenSale(saleId);
const handleCancelFromMenu = (saleId) => { contextMenuSaleId.value = saleId; showCancelDialog.value = true; };

const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
        paymentForm.reset();
        paymentForm.method = defaultMethod.value;
    }
};

const showNewSale = ref(false);
const showTicket = ref(false);
const editingPaymentId = ref(null);

// Helpers
const methodLabel = (m) => allMethodLabels[m] || m;
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m] || 'text-gray-600');
const unitLabel = (t) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[t] || t);
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const timeAgo = (date) => {
    const diff = Math.floor((Date.now() - new Date(date)) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return new Date(date).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};
const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;

// --- Payment form ---
const paymentForm = useForm({ method: 'cash', amount: '' });
const pendingAmount = computed(() => selected.value ? parseFloat(selected.value.amount_pending) : 0);
const enteredAmount = computed(() => parseFloat(paymentForm.amount) || 0);
const changeAmount = computed(() => Math.max(enteredAmount.value - pendingAmount.value, 0));
const hasPending = computed(() => pendingAmount.value > 0);

const submitPayment = () => {
    if (!selected.value || !hasPending.value) return;
    paymentForm.post(route('sucursal.workbench.payment', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { paymentForm.reset('amount'); paymentForm.method = defaultMethod.value; },
    });
};

// Edit payment
const startEditPayment = (p) => { editingPaymentId.value = p.id; };
const editPaymentRoute = (paymentId) => route('sucursal.workbench.payment.update', [props.tenant.slug, selected.value.id, paymentId]);

// Delete payment (with ConfirmDialog)
const confirmDeletePayment = ref(null);
const doDeletePayment = () => {
    if (!confirmDeletePayment.value) return;
    router.delete(route('sucursal.workbench.payment.destroy', [props.tenant.slug, selected.value.id, confirmDeletePayment.value]), {
        preserveScroll: true, onSuccess: () => { confirmDeletePayment.value = null; },
    });
};

// Cancel sale
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

// New sale modal
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

// --- Customer assignment ---
const showCustomerSearch = ref(false);
const customerQuery = ref('');
const filteredCustomers = computed(() => {
    if (!customerQuery.value) return (props.customers || []).slice(0, 5);
    const q = customerQuery.value.toLowerCase();
    return (props.customers || []).filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q)).slice(0, 5);
});
const assignCustomerForm = useForm({ customer_id: null });
const assignCustomer = (customerId) => {
    assignCustomerForm.customer_id = customerId;
    assignCustomerForm.patch(route('sucursal.workbench.assign-customer', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { showCustomerSearch.value = false; customerQuery.value = ''; },
    });
};
const removeCustomer = () => {
    assignCustomerForm.customer_id = null;
    assignCustomerForm.patch(route('sucursal.workbench.assign-customer', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
    });
};

// --- WhatsApp al cliente ---
// Link on-demand: fetch dentro del click del usuario para que window.open
// entre en el gesto y el navegador no bloquee la apertura.
const whatsappLoading = ref(false);
const whatsappError = ref(null);
const sendCustomerWhatsapp = async () => {
    if (!selected.value?.id) return;
    whatsappError.value = null;
    whatsappLoading.value = true;
    // Abrimos una pestaña en blanco INMEDIATAMENTE dentro del gesto del usuario
    // y luego le asignamos el href cuando llegue la respuesta. Si esperamos al
    // await, el navegador bloquea el popup por no ser click-originated.
    const popup = window.open('about:blank', '_blank', 'noopener,noreferrer');
    try {
        const res = await fetch(
            route('sucursal.workbench.whatsapp-link', [props.tenant.slug, selected.value.id]),
            { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.available || !data.url) {
            popup?.close();
            whatsappError.value = data.reason === 'no_phone' ? 'El cliente no tiene teléfono.' : 'No se pudo generar el link.';
            return;
        }
        if (popup) popup.location.href = data.url;
        else window.open(data.url, '_blank', 'noopener,noreferrer');
    } catch (e) {
        popup?.close();
        whatsappError.value = e.message || 'Error al generar el link';
    } finally {
        whatsappLoading.value = false;
    }
};

const customerInitials = computed(() => {
    const name = selected.value?.customer?.name || '';
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase() || '').join('') || '?';
});
const customerHasPhone = computed(() => !!selected.value?.customer?.phone);
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <SucursalLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1></template>

        <div class="flex h-[calc(100vh-8rem)] gap-5">
            <!-- LEFT: Sales queue -->
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold text-gray-900">Ventas</h2>
                        <button v-if="canCreate" @click="showNewSale = true" class="flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-red-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nueva Venta
                        </button>
                    </div>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'active',l:'Activas'},{v:'pending',l:'Pendientes'},{v:'all',l:'Todas'}]"
                            :key="f.v" @click="statusFilter = f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', statusFilter === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }} ({{ counts[f.v] }})
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in filteredSales" :key="sale.id" @click="selectSale(sale.id)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all',
                            selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' :
                            isLockedByOther(sale.id) ? 'ring-1 ring-amber-200 bg-amber-50/30 opacity-75' :
                            sale.status === 'pending' ? 'ring-1 ring-amber-200 bg-amber-50/20' :
                            'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                                <span v-if="sale.status === 'pending' && sale.origin === 'web'" class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800 ring-1 ring-inset ring-orange-600/30">🛒 Pedido web</span>
                                <span v-else-if="sale.status === 'pending'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pendiente</span>
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
                    <div v-if="filteredSales.length === 0" class="flex flex-col items-center justify-center py-20 text-center">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay ventas {{ statusFilter === 'pending' ? 'pendientes' : statusFilter === 'active' ? 'activas' : '' }}</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Sale detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <p class="text-sm text-gray-400">Selecciona una venta</p>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                                <span :class="[originBadge(selected.origin), 'rounded-full px-2.5 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                    Ticket
                                </button>
                                <SaleContextMenu
                                    :sale="selected"
                                    :allowed-actions="['pause', 'reactivate', 'reopen', 'cancel']"
                                    :is-locked-by-other="isLockedByOther(selected.id)"
                                    :locked-by-name="lockedByName(selected.id)"
                                    @pause="handlePause(selected.id)"
                                    @reactivate="handleReactivate(selected.id)"
                                    @reopen="handleReopen(selected.id)"
                                    @cancel="handleCancelFromMenu(selected.id)"
                                    @request-cancel="handleCancelFromMenu(selected.id)"
                                />
                                <span class="text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX', { weekday: 'long', day: '2-digit', month: 'long', hour: '2-digit', minute: '2-digit', hour12: true }) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Customer assignment (admin only) -->
                    <div v-if="canCreate" class="border-b border-gray-100 px-6 py-3">
                        <!-- With customer -->
                        <div v-if="selected.customer">
                            <div class="group flex items-center gap-3 rounded-xl bg-gradient-to-r from-red-50/60 via-white to-white px-3 py-2.5 ring-1 ring-red-100/70 transition hover:ring-red-200">
                                <!-- Avatar with initials -->
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-red-500 to-red-600 text-sm font-bold text-white shadow-sm ring-2 ring-white">
                                    {{ customerInitials }}
                                </div>
                                <!-- Info -->
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-bold text-gray-900">{{ selected.customer.name }}</p>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-green-700 ring-1 ring-inset ring-green-600/20">
                                            <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" clip-rule="evenodd" /></svg>
                                            Preferencial
                                        </span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500">
                                        <svg class="h-3 w-3 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                        <span class="tabular-nums">{{ selected.customer.phone }}</span>
                                    </div>
                                </div>
                                <!-- Actions -->
                                <div class="flex shrink-0 items-center gap-1">
                                    <!-- WhatsApp -->
                                    <button v-if="customerHasPhone"
                                        type="button"
                                        @click="sendCustomerWhatsapp"
                                        :disabled="whatsappLoading"
                                        title="Enviar detalle por WhatsApp"
                                        aria-label="Enviar detalle por WhatsApp"
                                        class="group/wa relative flex h-9 w-9 items-center justify-center rounded-full bg-[#25D366] text-white shadow-sm transition hover:bg-[#1ebe5b] hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[#25D366]/40 disabled:cursor-wait disabled:opacity-60">
                                        <svg v-if="!whatsappLoading" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                                        </svg>
                                        <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z"></path>
                                        </svg>
                                    </button>
                                    <!-- Remove -->
                                    <button @click="removeCustomer" :disabled="assignCustomerForm.processing"
                                        title="Quitar cliente"
                                        aria-label="Quitar cliente"
                                        class="flex h-9 w-9 items-center justify-center rounded-full text-gray-300 transition hover:bg-red-50 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-200">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>
                            <!-- Error feedback (subtle) -->
                            <p v-if="whatsappError" class="mt-2 flex items-center gap-1.5 text-xs text-red-600">
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                {{ whatsappError }}
                            </p>
                        </div>
                        <!-- Without customer -->
                        <div v-else class="relative">
                            <button v-if="!showCustomerSearch" @click="showCustomerSearch = true"
                                class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-50 hover:text-gray-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg>
                                Asignar cliente
                            </button>
                            <div v-if="showCustomerSearch" class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <input v-model="customerQuery" type="text" placeholder="Buscar por nombre o telefono..."
                                        class="flex-1 rounded-lg border-gray-200 py-1.5 text-sm placeholder-gray-400 focus:border-red-400 focus:ring-red-300" autofocus />
                                    <button @click="showCustomerSearch = false; customerQuery = '';" class="rounded-full p-1.5 text-gray-400 hover:text-gray-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <div v-if="filteredCustomers.length > 0" class="rounded-lg ring-1 ring-gray-100 divide-y divide-gray-50">
                                    <button v-for="c in filteredCustomers" :key="c.id" type="button"
                                        @click="assignCustomer(c.id)"
                                        :disabled="assignCustomerForm.processing"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition hover:bg-gray-50 disabled:opacity-50">
                                        <span class="font-medium text-gray-900">{{ c.name }}</span>
                                        <span class="text-xs text-gray-400">{{ c.phone }}</span>
                                    </button>
                                </div>
                                <p v-else class="px-1 text-xs text-gray-400">No se encontraron clientes.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Web order banner (action + contact + delivery info) -->
                    <div v-if="selected.origin === 'web' && selected.status === 'pending'" class="border-b border-orange-200 bg-orange-50 px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold uppercase tracking-wider text-orange-700">Pedido web — pendiente de aceptar</p>
                                <div class="mt-2 space-y-1 text-sm">
                                    <p class="text-gray-800">
                                        <span class="font-semibold">Cliente:</span> {{ selected.contact_name || '—' }}
                                        <a v-if="selected.contact_phone" :href="`tel:${selected.contact_phone}`" class="ml-2 font-semibold text-orange-700 hover:underline">{{ selected.contact_phone }}</a>
                                    </p>
                                    <p v-if="selected.delivery_type === 'delivery'" class="text-gray-800">
                                        <span class="font-semibold">Envío:</span> {{ selected.delivery_address }}
                                        <span v-if="selected.delivery_distance_km" class="ml-1 text-xs text-gray-500">({{ parseFloat(selected.delivery_distance_km).toFixed(1) }} km · ${{ parseFloat(selected.delivery_fee || 0).toFixed(2) }})</span>
                                    </p>
                                    <p v-else class="text-gray-800"><span class="font-semibold">Modo:</span> Pasará por su pedido</p>
                                    <p class="text-gray-800"><span class="font-semibold">Pago:</span> {{ methodLabel(selected.payment_method) }}</p>
                                    <p v-if="selected.cart_note" class="mt-2 rounded-lg bg-white/80 px-3 py-2 text-sm italic text-gray-700 ring-1 ring-orange-200">
                                        💬 {{ selected.cart_note }}
                                    </p>
                                </div>
                            </div>
                            <div v-if="canManageStatus" class="flex shrink-0 flex-col gap-2">
                                <button @click="handleReactivate(selected.id)" :disabled="statusProcessing"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700 disabled:opacity-50">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    Aceptar
                                </button>
                                <button @click="handleCancelFromMenu(selected.id)" :disabled="statusProcessing"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-bold text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-50 disabled:opacity-50">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    Rechazar
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Scrollable content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-5">
                        <!-- Products table -->
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
                                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900">
                                            {{ itemDisplayName(item) }}
                                            <p v-if="item.notes" class="mt-0.5 text-xs italic text-orange-700">💬 {{ item.notes }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">
                                            <div>{{ itemDisplayQuantity(item) }}</div>
                                            <div v-if="itemRealContentDisplay(item)" class="text-[11px] font-normal text-gray-400">≈ {{ itemRealContentDisplay(item) }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Payments list -->
                        <div v-if="selected.payments && selected.payments.length > 0">
                            <h3 class="mb-2 text-sm font-bold text-gray-700">Pagos</h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id"
                                    :class="editingPaymentId === p.id ? 'rounded-xl bg-white p-4 ring-2 ring-red-100 shadow-sm' : 'rounded-lg bg-gray-50 px-4 py-3'">
                                    <EditPaymentForm v-if="editingPaymentId === p.id"
                                        :payment="p"
                                        :update-route="editPaymentRoute(p.id)"
                                        :payment-methods="paymentMethods"
                                        @saved="editingPaymentId = null"
                                        @cancel="editingPaymentId = null" />
                                    <div v-else class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span :class="methodColor(p.method)" class="text-sm font-semibold">{{ methodLabel(p.method) }}</span>
                                            <span class="text-xs text-gray-400">{{ new Date(p.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) }}</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                            <template v-if="canEditPayments">
                                                <button @click="startEditPayment(p)" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-orange-600 transition hover:bg-orange-50 hover:text-orange-700 active:bg-orange-100">Editar</button>
                                                <button @click="confirmDeletePayment = p.id" class="rounded-lg px-3 py-1.5 text-xs text-gray-400 transition hover:bg-red-50 hover:text-red-600 active:bg-red-100">Eliminar</button>
                                            </template>
                                        </div>
                                    </div>
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
                    </div>

                    <!-- STICKY FOOTER: Cobro (touch-optimized POS layout) -->
                    <div v-if="hasPending" class="border-t-2 border-gray-200 bg-gray-50/80">
                        <!-- Summary row -->
                        <div class="grid grid-cols-3 divide-x divide-gray-200 border-b border-gray-200">
                            <div class="px-4 py-3 text-center">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Pendiente</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums text-amber-600">${{ pendingAmount.toFixed(2) }}</p>
                            </div>
                            <div class="px-4 py-3 text-center">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Recibido</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums" :class="enteredAmount > 0 ? 'text-gray-900' : 'text-gray-300'">
                                    ${{ enteredAmount > 0 ? enteredAmount.toFixed(2) : '0.00' }}
                                </p>
                            </div>
                            <div class="px-4 py-3 text-center">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Cambio</p>
                                <p class="mt-0.5 font-mono text-2xl font-extrabold tabular-nums" :class="changeAmount > 0 ? 'text-green-600' : 'text-gray-300'">
                                    ${{ changeAmount.toFixed(2) }}
                                </p>
                            </div>
                        </div>

                        <form @submit.prevent="submitPayment" class="space-y-3 px-5 py-4">
                            <!-- Payment method: segmented control -->
                            <div class="flex gap-2">
                                <button v-for="m in enabledMethods" :key="m.id" type="button"
                                    @click="paymentForm.method = m.id"
                                    :class="['flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold transition-all',
                                        paymentForm.method === m.id
                                            ? 'bg-red-600 text-white shadow-sm'
                                            : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50 active:bg-gray-100']">
                                    <svg v-if="m.id === 'cash'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                                    <svg v-else-if="m.id === 'card'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>
                                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                                    {{ m.label }}
                                </button>
                            </div>

                            <!-- Amount input: large, touch-friendly -->
                            <div class="relative">
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-lg font-semibold text-gray-400">$</span>
                                <input v-model="paymentForm.amount" type="number" inputmode="decimal" step="0.01" min="0.01" required
                                    :placeholder="pendingAmount.toFixed(2)"
                                    class="block w-full rounded-xl border-gray-200 py-4 pl-10 pr-24 text-xl font-bold tabular-nums placeholder:text-gray-300 focus:border-red-400 focus:ring-red-400" />
                                <button type="button" @click="paymentForm.amount = pendingAmount.toFixed(2)"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-200 active:bg-gray-300">
                                    Exacto
                                </button>
                            </div>

                            <!-- Cobrar button: full width, prominent -->
                            <button type="submit" :disabled="paymentForm.processing"
                                class="w-full rounded-xl bg-red-600 py-4 text-base font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[0.98] disabled:opacity-50">
                                Cobrar
                            </button>
                        </form>
                        <p v-if="paymentForm.errors.method" class="px-5 pb-3 text-xs text-red-600">{{ paymentForm.errors.method }}</p>
                        <p v-if="paymentForm.errors.amount" class="px-5 pb-3 text-xs text-red-600">{{ paymentForm.errors.amount }}</p>
                    </div>
                </template>
            </div>
        </div>

        <!-- New Sale Modal (unchanged) -->
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
                                                <!-- Product info + price -->
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-semibold text-gray-900">{{ item.name }}</p>
                                                    <!-- Admin: editable iOS-style price pill -->
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
                                                    <!-- Cajero: static price -->
                                                    <p v-else class="mt-0.5 text-xs text-gray-400">${{ item.price.toFixed(2) }}</p>
                                                </div>
                                                <!-- Quantity -->
                                                <input v-model.number="item.quantity" type="number" min="0.01" step="0.01"
                                                    class="w-[4.5rem] rounded-xl border-0 bg-gray-50 py-1.5 text-center text-sm font-medium tabular-nums text-gray-700 ring-1 ring-gray-200/80 transition-all focus:bg-white focus:ring-red-300 focus:shadow-[0_0_0_3px_rgba(239,68,68,0.08)] [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                                                <!-- Subtotal -->
                                                <span class="w-20 text-right text-sm font-bold tabular-nums text-gray-900">${{ (item.price * item.quantity).toFixed(2) }}</span>
                                                <!-- Remove -->
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

        <!-- Dialogs -->
        <ConfirmDialog v-if="confirmDeletePayment" title="Eliminar pago" message="El saldo de la venta se recalculara automaticamente." confirm-label="Eliminar" variant="danger" @confirm="doDeletePayment" @cancel="confirmDeletePayment = null" />
        <CancelSaleDialog v-if="showCancelDialog"
            :folio="(contextMenuSaleId ? sales.find(s => s.id === contextMenuSaleId)?.folio : selected?.folio) || ''"
            :mode="canCancel ? 'direct' : 'request'"
            :processing="cancelProcessing"
            :is-completed="(contextMenuSaleId ? sales.find(s => s.id === contextMenuSaleId)?.status : selected?.status) === 'completed'"
            @confirm="cancelSale"
            @cancel="showCancelDialog = false; contextMenuSaleId = null" />
        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name"
            :branch-name="branchInfo?.name" :branch-address="branchInfo?.address" :branch-phone="branchInfo?.phone"
            :ticket-config="branchInfo?.ticket_config" @close="showTicket = false" />
        <FlashToast />
    </SucursalLayout>
</template>
