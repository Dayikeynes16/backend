<script setup>
import TicketPrinter from '@/Components/TicketPrinter.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import SaleContextMenu from '@/Components/SaleContextMenu.vue';
import EditPaymentForm from '@/Components/EditPaymentForm.vue';
import InputError from '@/Components/InputError.vue';
import WhatsappPhoneDialog from '@/Components/WhatsappPhoneDialog.vue';
import WhatsappSendConfirmDialog from '@/Components/WhatsappSendConfirmDialog.vue';
import SaleWhatsappPhoneChip from '@/Components/SaleWhatsappPhoneChip.vue';
import SaleItemAddModal from '@/Components/Sucursal/SaleItemAddModal.vue';
import SaleItemEditModal from '@/Components/Sucursal/SaleItemEditModal.vue';
import SaleItemDeleteDialog from '@/Components/Sucursal/SaleItemDeleteDialog.vue';
import SaleItemHistoryModal from '@/Components/Sucursal/SaleItemHistoryModal.vue';
import LinkOrderModal from '@/Components/Workbench/LinkOrderModal.vue';
import LinkSaleToOrderModal from '@/Components/Workbench/LinkSaleToOrderModal.vue';
import { useWhatsappSend } from '@/composables/useWhatsappSend';
import { displayName as itemDisplayName, displayQuantity as itemDisplayQuantity, realContentDisplay as itemRealContentDisplay } from '@/composables/useSaleItemDisplay';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    sale: { type: Object, required: true },
    tenantSlug: { type: String, required: true },
    tenant: { type: Object, required: true },
    branchInfo: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    customers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    canManageStatus: { type: Boolean, default: false },
    canEditPayments: { type: Boolean, default: false },
    canEditPrice: { type: Boolean, default: false },
    saleItemEditReasonMode: { type: String, default: 'optional' },
    isLockedByOther: { type: Boolean, default: false },
    lockedByName: { type: String, default: '' },
});

const emit = defineEmits(['paid', 'mutated', 'close', 'update:dirty', 'pause', 'reactivate', 'reopen', 'cancel', 'linked']);

const currentUserId = computed(() => usePage().props.auth?.user?.id);

const webOrders = computed(() => usePage().props.features?.webOrders ?? false);

const allMethodLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };
const enabledMethods = computed(() =>
    (props.paymentMethods || ['cash', 'card', 'transfer']).map(id => ({ id, label: allMethodLabels[id] }))
);
const defaultMethod = computed(() => enabledMethods.value[0]?.id || 'cash');

// Helpers
const methodLabel = (m) => allMethodLabels[m] || m;
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m] || 'text-gray-600');
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;
const formattedDate = computed(() => {
    if (!props.sale?.created_at) {
        return '';
    }
    return new Date(props.sale.created_at).toLocaleString('es-MX', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
});

// --- Payment form ---
const paymentForm = useForm({ method: defaultMethod.value, amount: '' });
const pendingAmount = computed(() => parseFloat(props.sale.amount_pending) || 0);
const enteredAmount = computed(() => parseFloat(paymentForm.amount) || 0);
const changeAmount = computed(() => Math.max(enteredAmount.value - pendingAmount.value, 0));
const hasPending = computed(() => pendingAmount.value > 0);

// --- Comprobante de transferencia (flags por sucursal) ---
const receiptsEnabled = computed(() => !!props.branchInfo?.payment_receipts_enabled);
const receiptsRequired = computed(() => !!props.branchInfo?.payment_receipts_required);
const receiptFiles = ref([]);
const onReceiptChange = (e) => { receiptFiles.value = Array.from(e.target.files ?? []).slice(0, 3); };
const needsReceipt = computed(() => receiptsRequired.value && paymentForm.method === 'transfer' && receiptFiles.value.length === 0);

watch(() => paymentForm.amount, (value) => {
    emit('update:dirty', String(value ?? '').trim().length > 0);
}, { immediate: true });

const submitPayment = () => {
    if (!hasPending.value || needsReceipt.value) {
        return;
    }
    paymentForm
        .transform((data) => ({ ...data, receipts: receiptFiles.value }))
        .post(route('sucursal.workbench.payment', [props.tenantSlug, props.sale.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                paymentForm.reset('amount');
                paymentForm.method = defaultMethod.value;
                receiptFiles.value = [];
                emit('paid');
            },
        });
};

// --- Edit / delete payment ---
const editingPaymentId = ref(null);
const startEditPayment = (p) => { editingPaymentId.value = p.id; };
const editPaymentRoute = (paymentId) => route('sucursal.workbench.payment.update', [props.tenantSlug, props.sale.id, paymentId]);
const confirmDeletePayment = ref(null);
const doDeletePayment = () => {
    if (!confirmDeletePayment.value) {
        return;
    }
    router.delete(route('sucursal.workbench.payment.destroy', [props.tenantSlug, props.sale.id, confirmDeletePayment.value]), {
        preserveScroll: true,
        onSuccess: () => { confirmDeletePayment.value = null; emit('mutated'); },
    });
};

// --- Edición de items (admin-sucursal+) — solo Active/Pending con lock propio ---
const showAddItem = ref(false);
const editingItem = ref(null);
const deletingItem = ref(null);
const showHistory = ref(false);
const canEditItems = computed(() => {
    if (!props.canEditPrice) {
        return false;
    }
    if (!['active', 'pending'].includes(props.sale.status)) {
        return false;
    }
    if (props.sale.locked_by && props.sale.locked_by !== currentUserId.value) {
        return false;
    }
    return true;
});
const openAddItem = () => { showAddItem.value = true; };
const openEditItem = (item) => { editingItem.value = item; };
const openDeleteItem = (item) => { deletingItem.value = item; };
const refreshAfterItemChange = () => emit('mutated');
const itemWasEdited = (item) => !!(item.updated_by && (item.updated_at !== item.created_at));
const hasItemHistory = computed(() => {
    const items = props.sale.items || [];
    return items.some(i => i.deleted_at || i.updated_by);
});

// --- Customer assignment (admin) ---
const showCustomerSearch = ref(false);
const customerQuery = ref('');
const filteredCustomers = computed(() => {
    if (!customerQuery.value) {
        return (props.customers || []).slice(0, 5);
    }
    const q = customerQuery.value.toLowerCase();
    return (props.customers || []).filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q)).slice(0, 5);
});
const assignCustomerForm = useForm({ customer_id: null });
const assignCustomer = (customerId) => {
    assignCustomerForm.customer_id = customerId;
    assignCustomerForm.patch(route('sucursal.workbench.assign-customer', [props.tenantSlug, props.sale.id]), {
        preserveScroll: true,
        onSuccess: () => { showCustomerSearch.value = false; customerQuery.value = ''; emit('mutated'); },
    });
};
const removeCustomer = () => {
    assignCustomerForm.customer_id = null;
    assignCustomerForm.patch(route('sucursal.workbench.assign-customer', [props.tenantSlug, props.sale.id]), {
        preserveScroll: true,
        onSuccess: () => emit('mutated'),
    });
};
const customerInitials = computed(() => {
    const name = props.sale?.customer?.name || '';
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase() || '').join('') || '?';
});

// --- WhatsApp ---
const {
    loading: whatsappLoading,
    savingPhone: whatsappSavingPhone,
    removingPhone: whatsappRemovingPhone,
    error: whatsappError,
    phoneInfo: whatsappPhoneInfo,
    confirmDialog: whatsappConfirmDialog,
    captureDialog: whatsappCaptureDialog,
    removeDialog: whatsappRemoveDialog,
    handleSendClick: clickWhatsappSend,
    confirmSend: confirmWhatsappSend,
    switchToEditFromConfirm: editFromWhatsappConfirm,
    handleChipEdit: chipEditPhone,
    handleChipAdd: chipAddPhone,
    handleChipRemove: chipRemovePhone,
    submitPhone: submitWhatsappPhone,
    confirmRemove: confirmRemovePhone,
    closeAll: closeWhatsappDialogs,
} = useWhatsappSend({
    sale: () => props.sale,
    linkUrl: () => route('sucursal.workbench.whatsapp-link', [props.tenantSlug, props.sale.id]),
    savePhoneUrl: () => route('sucursal.workbench.whatsapp-phone', [props.tenantSlug, props.sale.id]),
    deletePhoneUrl: () => route('sucursal.workbench.whatsapp-phone.destroy', [props.tenantSlug, props.sale.id]),
    onMutate: () => emit('mutated'),
});

// --- Ticket ---
const showTicket = ref(false);

// --- Emparejamiento pedido web ↔ venta de báscula ---
const showLinkOrderModal = ref(false);
const showLinkSaleModal = ref(false);
const showUnlinkConfirm = ref(false);
const unlinkingOrder = ref(false);

const canLinkOrder = (sale) =>
    !!sale
    && sale.origin !== 'web'
    && sale.status === 'active'
    && !sale.linked_order_id;

const canUnlinkOrder = (sale) =>
    !!sale
    && !!sale.linked_order_id
    && sale.status === 'active'
    && (sale.payments?.length ?? 0) === 0;

const openLinkOrderModal = () => { showLinkOrderModal.value = true; };
const openLinkSaleModal = () => { showLinkSaleModal.value = true; };
// Al vincular, el pedido web pasa a Fulfilled y deja la lista: el padre cierra + recarga.
const onOrderLinked = () => emit('linked');
const confirmUnlink = () => { showUnlinkConfirm.value = true; };
const submitUnlink = () => {
    unlinkingOrder.value = true;
    router.delete(
        route('sucursal.workbench.unlink-order', [props.tenantSlug, props.sale.id]),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('mutated'),
            onFinish: () => {
                unlinkingOrder.value = false;
                showUnlinkConfirm.value = false;
            },
        }
    );
};
</script>

<template>
    <div class="flex h-full flex-col bg-white">
        <!-- Header -->
        <div class="shrink-0 border-b border-gray-100 px-6 py-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900">{{ sale.folio }}</h2>
                        <span :class="[originBadge(sale.origin), 'rounded-full px-2.5 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                    </div>
                    <p class="mt-1 flex items-center gap-1.5 text-xs text-gray-400">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        {{ formattedDate }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" @click="clickWhatsappSend" :disabled="whatsappLoading"
                        title="Enviar nota por WhatsApp"
                        class="flex items-center gap-1.5 rounded-lg bg-[#25D366]/10 px-3 py-1.5 text-xs font-bold text-[#128C7E] transition hover:bg-[#25D366]/20 disabled:cursor-wait disabled:opacity-60">
                        <svg v-if="!whatsappLoading" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                        </svg>
                        <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                        </svg>
                        WhatsApp
                    </button>
                    <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                        Ticket
                    </button>
                    <button v-if="webOrders && canLinkOrder(sale)" @click="openLinkOrderModal"
                        class="flex items-center gap-1.5 rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-orange-700"
                        title="Vincular esta venta con un pedido web pendiente">
                        🔗 Vincular pedido web
                    </button>
                    <button v-if="webOrders && canUnlinkOrder(sale)" @click="confirmUnlink"
                        class="flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-200 transition hover:bg-gray-50"
                        title="Quitar el vínculo con el pedido web">
                        Desvincular pedido
                    </button>
                    <SaleContextMenu
                        :sale="sale"
                        :allowed-actions="['pause', 'reactivate', 'reopen', 'cancel']"
                        :is-locked-by-other="isLockedByOther"
                        :locked-by-name="lockedByName"
                        @pause="$emit('pause', sale.id)"
                        @reactivate="$emit('reactivate', sale.id)"
                        @reopen="$emit('reopen', sale.id)"
                        @cancel="$emit('cancel', sale.id)"
                        @request-cancel="$emit('cancel', sale.id)"
                    />
                    <button type="button" @click="$emit('close')"
                        title="Cerrar"
                        aria-label="Cerrar"
                        class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            <!-- Phone chip: siempre visible -->
            <div class="mt-3 flex items-center gap-2">
                <SaleWhatsappPhoneChip
                    :phone="whatsappPhoneInfo.phone"
                    :source="whatsappPhoneInfo.source"
                    :customer-name="whatsappPhoneInfo.customerName"
                    @edit="chipEditPhone"
                    @remove="chipRemovePhone"
                    @add="chipAddPhone" />
            </div>
        </div>

        <!-- WhatsApp error banner -->
        <div v-if="whatsappError && !whatsappConfirmDialog.show && !whatsappCaptureDialog.show && !whatsappRemoveDialog.show"
            class="shrink-0 border-b border-red-100 bg-red-50 px-6 py-2">
            <p class="flex items-center gap-1.5 text-xs font-medium text-red-700">
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                {{ whatsappError }}
            </p>
        </div>

        <!-- Customer assignment (admin only) -->
        <div v-if="canCreate" class="shrink-0 border-b border-gray-100 px-6 py-3">
            <div v-if="sale.customer">
                <div class="group flex items-center gap-3 rounded-xl bg-gradient-to-r from-red-50/60 via-white to-white px-3 py-2.5 ring-1 ring-red-100/70 transition hover:ring-red-200">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-red-500 to-red-600 text-sm font-bold text-white shadow-sm ring-2 ring-white">
                        {{ customerInitials }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-sm font-bold text-gray-900">{{ sale.customer.name }}</p>
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-green-700 ring-1 ring-inset ring-green-600/20">
                                <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" clip-rule="evenodd" /></svg>
                                Preferencial
                            </span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500">
                            <svg class="h-3 w-3 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            <span class="tabular-nums">{{ sale.customer.phone }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button @click="removeCustomer" :disabled="assignCustomerForm.processing"
                            title="Quitar cliente"
                            aria-label="Quitar cliente"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-gray-300 transition hover:bg-red-50 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-200">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div v-else class="relative">
                <button v-if="!showCustomerSearch" @click="showCustomerSearch = true"
                    class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-50 hover:text-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg>
                    Asignar cliente
                </button>
                <div v-if="showCustomerSearch" class="space-y-2">
                    <div class="flex items-center gap-2">
                        <input v-model="customerQuery" type="text" placeholder="Buscar por nombre o teléfono..."
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

        <!-- Web order banner -->
        <div v-if="sale.origin === 'web' && sale.status === 'pending'" class="shrink-0 border-b border-orange-200 bg-orange-50 px-6 py-4">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wider text-orange-700">Pedido web — pendiente de vincular</p>
                    <div class="mt-2 space-y-1 text-sm">
                        <p class="text-gray-800">
                            <span class="font-semibold">Cliente:</span> {{ sale.contact_name || '—' }}
                            <a v-if="sale.contact_phone" :href="`tel:${sale.contact_phone}`" class="ml-2 font-semibold text-orange-700 hover:underline">{{ sale.contact_phone }}</a>
                        </p>
                        <p v-if="sale.delivery_type === 'delivery'" class="text-gray-800">
                            <span class="font-semibold">Envío:</span> {{ sale.delivery_address }}
                            <span v-if="sale.delivery_distance_km" class="ml-1 text-xs text-gray-500">({{ parseFloat(sale.delivery_distance_km).toFixed(1) }} km · ${{ parseFloat(sale.delivery_fee || 0).toFixed(2) }})</span>
                        </p>
                        <p v-else class="text-gray-800"><span class="font-semibold">Modo:</span> Pasará por su pedido</p>
                        <p class="text-gray-800"><span class="font-semibold">Pago:</span> {{ methodLabel(sale.payment_method) }}</p>
                        <p v-if="sale.cart_note" class="mt-2 rounded-lg bg-white/80 px-3 py-2 text-sm italic text-gray-700 ring-1 ring-orange-200">
                            💬 {{ sale.cart_note }}
                        </p>
                    </div>
                </div>
                <div v-if="canManageStatus" class="flex shrink-0 flex-col gap-2">
                    <button v-if="webOrders" @click="openLinkSaleModal"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700">
                        🔗 Vincular con venta
                    </button>
                    <button @click="$emit('cancel', sale.id)"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-bold text-red-600 shadow-sm ring-1 ring-red-200 transition hover:bg-red-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        Rechazar
                    </button>
                </div>
            </div>
        </div>

        <!-- Scrollable content -->
        <div class="flex-1 overflow-y-auto p-6 space-y-5">
            <!-- Banner cuando la venta está Completed -->
            <div v-if="sale.status === 'completed'" class="rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                <div class="flex items-start gap-2.5">
                    <svg class="h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" /></svg>
                    <p class="text-xs font-medium text-amber-800">
                        Para modificar los items de esta venta, primero elimina el pago desde la sección de Pagos.
                    </p>
                </div>
            </div>

            <!-- Toolbar: contador + botón agregar (solo admin) -->
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-gray-700">
                    Productos
                    <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">
                        {{ (sale.items || []).filter(i => !i.deleted_at).length }}
                    </span>
                </h3>
                <button v-if="canEditItems" type="button" @click="openAddItem"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[.98]">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Agregar producto
                </button>
            </div>

            <!-- Products table -->
            <div class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                <table class="min-w-full divide-y divide-gray-50">
                    <thead><tr class="bg-gray-50">
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Cant.</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Precio</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                        <th v-if="canEditItems" class="px-3 py-2 text-right text-xs font-semibold text-gray-500">Acciones</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="item in (sale.items || []).filter(i => !i.deleted_at)" :key="item.id" class="group">
                            <td class="px-4 py-2.5 text-sm font-medium text-gray-900">
                                <div class="flex items-center gap-1.5">
                                    <span class="truncate">{{ itemDisplayName(item) }}</span>
                                    <span v-if="itemWasEdited(item)"
                                        class="rounded-full bg-orange-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-orange-700 ring-1 ring-inset ring-orange-600/20"
                                        title="Este item fue editado">Editado</span>
                                </div>
                                <p v-if="item.notes" class="mt-0.5 text-xs italic text-orange-700">💬 {{ item.notes }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">
                                <div>{{ itemDisplayQuantity(item) }}</div>
                                <div v-if="itemRealContentDisplay(item)" class="text-[11px] font-normal text-gray-400">≈ {{ itemRealContentDisplay(item) }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                            <td v-if="canEditItems" class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" @click="openEditItem(item)" title="Editar item"
                                        class="inline-flex items-center gap-1 rounded-lg bg-orange-50 px-2.5 py-1.5 text-xs font-semibold text-orange-700 ring-1 ring-inset ring-orange-200/70 transition hover:bg-orange-100 active:scale-95">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                        <span class="hidden sm:inline">Editar</span>
                                    </button>
                                    <button type="button" @click="openDeleteItem(item)" title="Eliminar item"
                                        class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200/70 transition hover:bg-red-100 active:scale-95">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        <span class="hidden sm:inline">Eliminar</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Link a historial — solo admin -->
            <div v-if="canEditPrice && hasItemHistory" class="flex justify-end">
                <button type="button" @click="showHistory = true"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Ver historial de cambios
                </button>
            </div>

            <!-- Payments list -->
            <div v-if="sale.payments && sale.payments.length > 0">
                <h3 class="mb-2 text-sm font-bold text-gray-700">Pagos</h3>
                <div class="space-y-1.5">
                    <div v-for="p in sale.payments" :key="p.id"
                        :class="editingPaymentId === p.id ? 'rounded-xl bg-white p-4 ring-2 ring-red-100 shadow-sm' : 'rounded-lg bg-gray-50 px-4 py-3'">
                        <EditPaymentForm v-if="editingPaymentId === p.id"
                            :payment="p"
                            :update-route="editPaymentRoute(p.id)"
                            :payment-methods="paymentMethods"
                            @saved="editingPaymentId = null; $emit('mutated')"
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
                    <span class="font-bold text-gray-900">{{ Math.round(paidPct(sale)) }}%</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                    <div class="h-full rounded-full transition-all duration-500" :class="paidPct(sale) >= 100 ? 'bg-green-500' : 'bg-red-500'" :style="{ width: Math.max(paidPct(sale), 2) + '%' }" />
                </div>
            </div>
        </div>

        <!-- STICKY FOOTER: Cobro (touch-optimized POS layout) -->
        <div v-if="hasPending" class="shrink-0 border-t-2 border-gray-200 bg-gray-50/80">
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

                <div v-if="(receiptsEnabled || receiptsRequired) && paymentForm.method === 'transfer'" class="mt-1">
                    <label class="mb-1 block text-xs font-semibold text-gray-600">
                        Comprobante de la transferencia <span v-if="receiptsRequired" class="text-red-600">*</span>
                    </label>
                    <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple
                        class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200"
                        @change="onReceiptChange" />
                    <p v-if="receiptFiles.length" class="mt-1 text-xs text-gray-500">{{ receiptFiles.map(f => f.name).join(', ') }}</p>
                    <p v-else-if="receiptsRequired" class="mt-1 text-xs text-amber-600">Adjunta el comprobante para poder cobrar.</p>
                    <InputError :message="paymentForm.errors.receipts" class="mt-1" />
                </div>

                <button type="submit" :disabled="paymentForm.processing || needsReceipt"
                    class="w-full rounded-xl bg-red-600 py-4 text-base font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[0.98] disabled:opacity-50">
                    Cobrar
                </button>
            </form>
            <p v-if="paymentForm.errors.method" class="px-5 pb-3 text-xs text-red-600">{{ paymentForm.errors.method }}</p>
            <p v-if="paymentForm.errors.amount" class="px-5 pb-3 text-xs text-red-600">{{ paymentForm.errors.amount }}</p>
        </div>
        <!-- Fully paid state -->
        <div v-else class="shrink-0 border-t-2 border-green-200 bg-green-50/70 px-6 py-4 text-center">
            <p class="flex items-center justify-center gap-2 text-sm font-bold text-green-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                Venta pagada · Total ${{ parseFloat(sale.total).toFixed(2) }}
            </p>
        </div>

        <!-- Sub-dialogs (teleport a body, sobre el modal) -->
        <TicketPrinter v-if="showTicket" :sale="sale" :business-name="tenant.name"
            :branch-name="branchInfo?.name" :branch-address="branchInfo?.address" :branch-phone="branchInfo?.phone"
            :ticket-config="branchInfo?.ticket_config" @close="showTicket = false" />
        <WhatsappSendConfirmDialog
            :show="whatsappConfirmDialog.show"
            :sending="whatsappLoading"
            :phone="whatsappPhoneInfo.phone"
            :source="whatsappPhoneInfo.source"
            :customer-name="whatsappPhoneInfo.customerName"
            :server-error="whatsappError"
            @send="confirmWhatsappSend"
            @edit="editFromWhatsappConfirm"
            @close="closeWhatsappDialogs" />
        <WhatsappPhoneDialog
            :show="whatsappCaptureDialog.show"
            :saving="whatsappSavingPhone"
            :server-error="whatsappError"
            :initial-phone="whatsappCaptureDialog.initialPhone"
            :title="whatsappCaptureDialog.title"
            :subtitle="whatsappCaptureDialog.subtitle"
            :action-label="whatsappCaptureDialog.actionLabel"
            @submit="submitWhatsappPhone"
            @close="closeWhatsappDialogs" />
        <ConfirmDialog v-if="whatsappRemoveDialog.show"
            title="Quitar teléfono"
            message="Se eliminará el teléfono manual asociado a esta venta. No envía nada a WhatsApp."
            confirm-label="Quitar"
            variant="danger"
            :processing="whatsappRemovingPhone"
            @confirm="confirmRemovePhone"
            @cancel="closeWhatsappDialogs" />

        <!-- Modales de edición de items (admin-sucursal+) -->
        <SaleItemAddModal
            :show="showAddItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :products="products"
            :reason-mode="saleItemEditReasonMode"
            @close="showAddItem = false"
            @success="refreshAfterItemChange" />
        <SaleItemEditModal
            :show="!!editingItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :item="editingItem"
            :reason-mode="saleItemEditReasonMode"
            @close="editingItem = null"
            @success="refreshAfterItemChange" />
        <SaleItemDeleteDialog
            :show="!!deletingItem"
            :tenant-slug="tenantSlug"
            :sale="sale"
            :item="deletingItem"
            @close="deletingItem = null"
            @success="refreshAfterItemChange" />
        <SaleItemHistoryModal
            :show="showHistory"
            :tenant-slug="tenantSlug"
            :sale="sale"
            @close="showHistory = false" />

        <!-- Delete payment confirm -->
        <ConfirmDialog v-if="confirmDeletePayment"
            title="Eliminar pago"
            message="El saldo de la venta se recalculara automaticamente."
            confirm-label="Eliminar"
            variant="danger"
            @confirm="doDeletePayment"
            @cancel="confirmDeletePayment = null" />

        <!-- Emparejamiento pedido web ↔ venta de báscula -->
        <LinkOrderModal v-if="webOrders"
            :show="showLinkOrderModal"
            :tenant-slug="tenantSlug"
            :scale-sale="sale"
            route-prefix="sucursal"
            @close="showLinkOrderModal = false"
            @linked="onOrderLinked" />
        <LinkSaleToOrderModal v-if="webOrders && sale.origin === 'web'"
            :show="showLinkSaleModal"
            :tenant-slug="tenantSlug"
            :web-order="sale"
            route-prefix="sucursal"
            @close="showLinkSaleModal = false"
            @linked="onOrderLinked" />
        <ConfirmDialog v-if="webOrders && showUnlinkConfirm"
            title="Desvincular pedido"
            message="Esta venta dejará de cumplir el pedido web. Se quitará el costo de envío del total."
            confirm-label="Desvincular"
            variant="warning"
            :processing="unlinkingOrder"
            @confirm="submitUnlink"
            @cancel="showUnlinkConfirm = false" />
    </div>
</template>
