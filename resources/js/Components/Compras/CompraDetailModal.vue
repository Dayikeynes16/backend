<script setup>
import PagoProveedorModal from './PagoProveedorModal.vue';
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    purchase: { type: Object, default: null },
    canManage: { type: Boolean, default: true },
    routes: {
        type: Object,
        required: true,
        validator: (v) => !!v.cancel,
    },
});
const emit = defineEmits(['close', 'edit', 'refresh']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtQty = (n) => Number(n || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const paymentBadge = (status) => ({
    paid: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-amber-100 text-amber-800',
    pending: 'bg-gray-100 text-gray-700',
    cancelled: 'bg-red-100 text-red-800',
})[status] || 'bg-gray-100 text-gray-700';

const paymentLabel = (status) => ({
    paid: 'Pagada', partial: 'Abonada', pending: 'Pendiente', cancelled: 'Cancelada',
})[status] || status;

// Cancelar
const cancelOpen = ref(false);
const cancelForm = useForm({ reason: '' });
const askCancel = () => { cancelOpen.value = true; };
const confirmCancel = () => {
    cancelForm.patch(route(props.routes.cancel, { tenant: slug.value, compra: props.purchase.id }), {
        preserveScroll: true,
        onSuccess: () => { cancelOpen.value = false; emit('close'); },
    });
};

// Pagos
const payOpen = ref(false);
const askPay = () => { payOpen.value = true; };
const onPaymentCreated = () => {
    payOpen.value = false;
    // Recarga la página para refrescar saldo. preserveScroll mantiene posición.
    router.reload({ preserveScroll: true });
    emit('refresh');
};

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' })[m] || m;

const deletePayment = (pago) => {
    const reason = prompt(`Motivo para cancelar este pago de ${fmt(pago.amount)}:`);
    if (!reason || !reason.trim()) return;
    useForm({ reason: reason.trim() }).delete(route(props.routes.pagoDestroy, {
        tenant: slug.value, compra: props.purchase.id, pago: pago.id,
    }), { preserveScroll: true });
};

const payRoutes = computed(() => ({
    storePurchase: props.routes.pagoStore,
}));

const livePayments = computed(() => (props.purchase?.payments || []).filter((p) => !p.cancelled_at));

// Adjuntos
const downloadUrl = (att) => route(props.routes.adjuntoDownload, { tenant: slug.value, compra: props.purchase.id, attachment: att.id });
const previewUrl = (att) => route(props.routes.adjuntoPreview, { tenant: slug.value, compra: props.purchase.id, attachment: att.id });
const isImage = (att) => (att.mime_type || '').startsWith('image/');

// Visor de adjunto (lightbox)
const viewer = ref(null);
const openViewer = (att) => { viewer.value = att; };
const closeViewer = () => { viewer.value = null; };

const deleteAttachment = (att) => {
    if (!confirm(`¿Eliminar adjunto "${att.original_name}"?`)) return;
    useForm({}).delete(route(props.routes.adjuntoDestroy, { tenant: slug.value, compra: props.purchase.id, attachment: att.id }), {
        preserveScroll: true,
    });
};

const isCancelled = computed(() => props.purchase?.status === 'cancelled');
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open && purchase" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-3xl overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">{{ purchase.folio }}</h2>
                            <p v-if="purchase.invoice_number" class="text-xs text-gray-500">Factura: {{ purchase.invoice_number }}</p>
                        </div>
                        <button @click="emit('close')" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <div class="max-h-[80vh] space-y-5 overflow-y-auto px-5 py-5">
                        <!-- Badge de estado de pago -->
                        <div class="flex items-center gap-3">
                            <span :class="['rounded-full px-3 py-1 text-xs font-semibold', paymentBadge(purchase.payment_status)]">
                                {{ paymentLabel(purchase.payment_status) }}
                            </span>
                            <span v-if="isCancelled" class="text-xs text-red-700">Cancelada el {{ fmtDate(purchase.cancelled_at) }}</span>
                        </div>

                        <!-- Cabecera -->
                        <div class="grid grid-cols-1 gap-4 rounded-xl bg-gray-50 p-4 sm:grid-cols-3">
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Proveedor</div>
                                <div class="text-base font-semibold text-gray-900">{{ purchase.provider?.name || '—' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Sucursal</div>
                                <div class="text-base font-semibold text-gray-900">{{ purchase.branch?.name || '—' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Fecha</div>
                                <div class="text-base font-semibold text-gray-900">{{ fmtDate(purchase.purchased_at) }}</div>
                            </div>
                        </div>

                        <!-- Razón de cancelación -->
                        <div v-if="isCancelled && purchase.cancel_reason" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <div class="font-semibold">Motivo de cancelación</div>
                            <div>{{ purchase.cancel_reason }}</div>
                        </div>

                        <!-- Líneas -->
                        <div>
                            <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-700">Líneas</h3>
                            <div class="overflow-x-auto rounded-xl border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-gray-600">Concepto</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Cantidad</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Precio</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="line in purchase.items" :key="line.id">
                                            <td class="px-3 py-2 text-sm">
                                                <div class="font-medium text-gray-900">{{ line.concept }}</div>
                                                <div v-if="line.notes" class="text-xs text-gray-500">{{ line.notes }}</div>
                                            </td>
                                            <td class="px-3 py-2 text-right text-sm text-gray-700">{{ fmtQty(line.quantity) }} {{ line.unit }}</td>
                                            <td class="px-3 py-2 text-right text-sm text-gray-700">{{ fmt(line.unit_price) }}</td>
                                            <td class="px-3 py-2 text-right text-sm font-semibold text-gray-900">{{ fmt(line.subtotal) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="bg-gray-50">
                                        <tr>
                                            <td colspan="3" class="px-3 py-2 text-right text-sm font-semibold text-gray-700">Total</td>
                                            <td class="px-3 py-2 text-right text-base font-bold text-gray-900">{{ fmt(purchase.total) }}</td>
                                        </tr>
                                        <tr v-if="purchase.amount_paid > 0">
                                            <td colspan="3" class="px-3 py-2 text-right text-xs text-gray-600">Pagado</td>
                                            <td class="px-3 py-2 text-right text-sm font-semibold text-emerald-700">{{ fmt(purchase.amount_paid) }}</td>
                                        </tr>
                                        <tr v-if="purchase.amount_pending > 0">
                                            <td colspan="3" class="px-3 py-2 text-right text-xs text-gray-600">Pendiente</td>
                                            <td class="px-3 py-2 text-right text-sm font-semibold text-amber-700">{{ fmt(purchase.amount_pending) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Pagos -->
                        <div v-if="!isCancelled || livePayments.length">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-sm font-bold uppercase tracking-wide text-gray-700">Pagos</h3>
                                <button v-if="!isCancelled && canManage && purchase.amount_pending > 0 && routes.pagoStore"
                                    type="button" @click="askPay"
                                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700">
                                    + Registrar pago
                                </button>
                            </div>
                            <p v-if="!livePayments.length" class="rounded-lg bg-gray-50 px-3 py-3 text-sm italic text-gray-500">
                                Sin pagos registrados.
                            </p>
                            <ul v-else class="space-y-1">
                                <li v-for="pago in livePayments" :key="pago.id"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                    <span class="flex flex-1 flex-col">
                                        <span class="font-medium text-gray-900">{{ fmt(pago.amount) }} · {{ methodLabel(pago.payment_method) }}</span>
                                        <span class="text-xs text-gray-500">
                                            {{ fmtDate(pago.paid_at) }}<span v-if="pago.reference"> · ref: {{ pago.reference }}</span>
                                        </span>
                                        <span v-if="pago.notes" class="text-xs italic text-gray-500">{{ pago.notes }}</span>
                                    </span>
                                    <button v-if="!isCancelled && canManage && routes.pagoDestroy" @click="deletePayment(pago)"
                                        class="text-xs font-medium text-red-600 hover:text-red-800">Cancelar</button>
                                </li>
                            </ul>
                        </div>

                        <!-- Adjuntos -->
                        <div v-if="purchase.attachments?.length && routes.adjuntoPreview">
                            <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-700">Adjuntos</h3>
                            <ul class="space-y-2">
                                <li v-for="att in purchase.attachments" :key="att.id" class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                    <button type="button" @click="openViewer(att)" class="shrink-0" :title="`Ver ${att.original_name}`">
                                        <img v-if="isImage(att)" :src="previewUrl(att)" :alt="att.original_name" loading="lazy"
                                            class="h-12 w-12 rounded-lg border border-gray-200 object-cover transition hover:opacity-80" />
                                        <span v-else class="flex h-12 w-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-400 transition hover:bg-gray-100">
                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                        </span>
                                    </button>
                                    <button type="button" @click="openViewer(att)" class="flex-1 truncate text-left text-orange-700 hover:underline">{{ att.original_name }}</button>
                                    <span class="text-xs text-gray-500">{{ Math.ceil(att.size_bytes / 1024) }} KB</span>
                                    <a :href="downloadUrl(att)" class="text-xs font-medium text-gray-600 hover:text-gray-900">Descargar</a>
                                    <button v-if="!isCancelled" @click="deleteAttachment(att)" class="text-xs font-medium text-red-600 hover:text-red-800">Eliminar</button>
                                </li>
                            </ul>
                        </div>

                        <!-- Notas -->
                        <div v-if="purchase.notes" class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Notas</div>
                            {{ purchase.notes }}
                        </div>

                        <!-- Historial -->
                        <HistorialTimeline :history="purchase.history || []" />
                    </div>

                    <footer class="flex justify-between gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button v-if="!isCancelled && canManage" @click="askCancel"
                            class="rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                            Cancelar compra
                        </button>
                        <div class="ml-auto flex gap-2">
                            <button @click="emit('close')" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cerrar</button>
                            <button v-if="!isCancelled && canManage" @click="emit('edit')"
                                class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">
                                Editar
                            </button>
                        </div>
                    </footer>
                </div>

                <!-- Sub-modal: razón de cancelación -->
                <div v-if="cancelOpen" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
                    <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                        <h3 class="mb-3 text-lg font-bold text-gray-900">¿Cancelar esta compra?</h3>
                        <p class="mb-3 text-sm text-gray-600">Esta acción no se puede deshacer. Si tiene pagos asociados, no podrás aplicar nuevos.</p>
                        <textarea v-model="cancelForm.reason" rows="3" placeholder="Motivo (obligatorio)…"
                            class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"></textarea>
                        <p v-if="cancelForm.errors.reason" class="mt-1 text-xs text-red-600">{{ cancelForm.errors.reason }}</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <button @click="cancelOpen = false" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Volver</button>
                            <button @click="confirmCancel" :disabled="cancelForm.processing || !cancelForm.reason.trim()"
                                class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">
                                Cancelar compra
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Visor de adjunto (lightbox) -->
                <div v-if="viewer" class="fixed inset-0 z-[70] flex flex-col bg-black/80" @click.self="closeViewer">
                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-white">
                        <span class="truncate text-sm font-medium">{{ viewer.original_name }}</span>
                        <div class="flex shrink-0 items-center gap-4">
                            <a :href="downloadUrl(viewer)" class="text-sm hover:underline">Descargar</a>
                            <button @click="closeViewer" class="rounded-full bg-white/10 px-3 py-1 text-sm hover:bg-white/20">✕</button>
                        </div>
                    </div>
                    <div class="flex flex-1 items-center justify-center overflow-auto p-4" @click.self="closeViewer">
                        <img v-if="isImage(viewer)" :src="previewUrl(viewer)" :alt="viewer.original_name" class="max-h-full max-w-full object-contain" />
                        <iframe v-else :src="previewUrl(viewer)" :title="viewer.original_name" class="h-full w-full rounded bg-white"></iframe>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <PagoProveedorModal
        v-if="purchase"
        :open="payOpen"
        :purchase="purchase"
        :routes="payRoutes"
        @close="payOpen = false"
        @created="onPaymentCreated"
    />
</template>
