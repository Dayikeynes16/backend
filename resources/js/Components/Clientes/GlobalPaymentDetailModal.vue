<script setup>
import Modal from '@/Components/Modal.vue';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    show: Boolean,
    tenantSlug: { type: String, required: true },
    customerId: { type: [Number, String], default: null },
    customerPaymentId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close', 'open-sale', 'cancelled']);

const data = ref(null);
const loading = ref(false);
const error = ref(null);
let controller = null;

// --- Cancel flow ---
const showCancelConfirm = ref(false);
const cancelReason = ref('');
const cancelling = ref(false);
const cancelError = ref(null);

const openCancelConfirm = () => {
    cancelReason.value = '';
    cancelError.value = null;
    showCancelConfirm.value = true;
};

const submitCancel = async () => {
    if (cancelling.value) return;
    if (!cancelReason.value.trim()) {
        cancelError.value = 'Indica el motivo de cancelación';
        return;
    }
    cancelling.value = true;
    cancelError.value = null;
    try {
        const res = await fetch(
            route('sucursal.clientes.cobro-global.cancel', [props.tenantSlug, props.customerId, props.customerPaymentId]),
            {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ cancel_reason: cancelReason.value.trim() }),
            }
        );
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            cancelError.value = err.message || 'Error al cancelar';
            return;
        }
        const result = await res.json();
        showCancelConfirm.value = false;
        emit('cancelled', result);
        emit('close');
    } catch (e) {
        cancelError.value = e.message || 'Error de red';
    } finally {
        cancelling.value = false;
    }
};

const load = async () => {
    if (!props.customerId || !props.customerPaymentId) return;
    if (controller) controller.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    data.value = null;
    try {
        const res = await fetch(
            route('sucursal.clientes.cobro-global.show', [props.tenantSlug, props.customerId, props.customerPaymentId]),
            {
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        data.value = await res.json();
    } catch (e) {
        if (e.name !== 'AbortError') error.value = e.message || 'Error al cargar';
    } finally {
        loading.value = false;
    }
};

watch(() => [props.show, props.customerPaymentId], ([show]) => {
    if (show) load();
    else if (controller) controller.abort();
});

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const fmtDateTime = (v) => {
    if (!v) return '—';
    return new Date(v).toLocaleString('es-MX', {
        day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};
const fmtDate = (v) => {
    if (!v) return '—';
    return new Date(v).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
};

const methodIcon = computed(() => {
    if (!data.value) return null;
    return {
        cash: { path: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z', bg: 'bg-green-100 text-green-700' },
        card: { path: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z', bg: 'bg-blue-100 text-blue-700' },
        transfer: { path: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5', bg: 'bg-purple-100 text-purple-700' },
    }[data.value.method];
});

const statusBadge = (status) => {
    if (status === 'completed') return { text: 'Saldada', cls: 'bg-green-100 text-green-700 ring-green-200' };
    if (status === 'cancelled') return { text: 'Cancelada', cls: 'bg-gray-100 text-gray-500 ring-gray-200' };
    return { text: 'Con saldo', cls: 'bg-amber-100 text-amber-700 ring-amber-200' };
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div v-if="loading" class="flex items-center justify-center gap-3 px-8 py-16 text-gray-500">
            <svg class="h-5 w-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path></svg>
            <span class="text-sm font-medium">Cargando cobro...</span>
        </div>

        <div v-else-if="error" class="px-8 py-12 text-center">
            <p class="text-sm font-medium text-red-600">{{ error }}</p>
            <button @click="emit('close')" class="mt-4 h-10 rounded-lg bg-gray-100 px-5 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cerrar</button>
        </div>

        <div v-else-if="data" class="relative">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-7 py-5">
                <div class="flex items-center gap-3">
                    <div v-if="methodIcon" :class="['flex h-11 w-11 items-center justify-center rounded-xl', methodIcon.bg]">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="methodIcon.path" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Cobro {{ data.folio }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ methodLabel(data.method) }} · {{ fmtDateTime(data.created_at) }}</p>
                        <p v-if="data.cashier" class="text-xs text-gray-400">Cajero: {{ data.cashier.name }}</p>
                    </div>
                </div>
                <button @click="emit('close')" class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Totals -->
            <div class="grid grid-cols-3 gap-0 border-b border-gray-100">
                <div class="px-5 py-4 text-center">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Recibido</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-gray-900">{{ money(data.amount_received) }}</p>
                </div>
                <div class="border-x border-gray-100 bg-green-50/40 px-5 py-4 text-center">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-green-700">Aplicado</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-green-700">{{ money(data.amount_applied) }}</p>
                </div>
                <div :class="['px-5 py-4 text-center', data.change_given > 0 ? 'bg-amber-50/40' : '']">
                    <p :class="['text-[11px] font-bold uppercase tracking-wide', data.change_given > 0 ? 'text-amber-700' : 'text-gray-500']">Cambio</p>
                    <p :class="['mt-1 text-lg font-bold tabular-nums', data.change_given > 0 ? 'text-amber-700' : 'text-gray-400']">{{ money(data.change_given) }}</p>
                </div>
            </div>

            <!-- Applications -->
            <div class="max-h-[50vh] overflow-y-auto px-7 py-5">
                <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">
                    Aplicado a {{ data.sales_affected_count }} venta{{ data.sales_affected_count !== 1 ? 's' : '' }}
                </h4>
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl ring-1 ring-gray-100">
                    <li v-for="app in data.applications" :key="app.payment_id"
                        class="group flex cursor-pointer items-center gap-4 px-5 py-3.5 transition hover:bg-red-50/30"
                        @click="emit('open-sale', app.sale_id)">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-gray-900">{{ app.sale_folio }}</p>
                                <span :class="['rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset', statusBadge(app.sale_status_after).cls]">
                                    {{ statusBadge(app.sale_status_after).text }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ fmtDate(app.sale_date) }} · total {{ money(app.sale_total) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold tabular-nums text-gray-900">{{ money(app.amount) }}</p>
                            <p v-if="app.sale_amount_pending_after > 0" class="text-[11px] font-semibold text-amber-600">
                                queda {{ money(app.sale_amount_pending_after) }}
                            </p>
                            <p v-else class="text-[11px] font-semibold text-green-600">saldo $0</p>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </li>
                </ul>

                <div v-if="data.notes" class="mt-4 rounded-lg bg-gray-50 px-4 py-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Notas</p>
                    <p class="mt-1 text-sm text-gray-700">{{ data.notes }}</p>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-7 py-4">
                <button @click="openCancelConfirm"
                    class="flex h-10 items-center gap-2 rounded-lg bg-red-50 px-4 text-sm font-semibold text-red-700 ring-1 ring-red-200 transition hover:bg-red-100">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                    Cancelar cobro
                </button>
                <button @click="emit('close')" class="h-10 rounded-lg bg-white px-5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Cerrar</button>
            </div>

            <!-- Inline confirm (overlay sobre el contenido del modal) -->
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="showCancelConfirm" class="absolute inset-0 z-10 flex items-center justify-center bg-white/95 backdrop-blur-sm">
                <div class="w-full max-w-sm p-6">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126Z" /></svg>
                    </div>
                    <p class="mt-3 text-center text-base font-bold text-gray-900">¿Cancelar cobro {{ data?.folio }}?</p>
                    <p class="mt-1 text-center text-xs text-gray-500">
                        Se revertirán <span class="font-semibold">{{ money(data?.amount_applied) }}</span>
                        en <span class="font-semibold">{{ data?.sales_affected_count }} venta{{ data?.sales_affected_count !== 1 ? 's' : '' }}</span>.
                        Las ventas afectadas volverán a tener saldo pendiente y se recalcularán los cortes de caja afectados.
                    </p>
                    <div class="mt-4">
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Motivo</label>
                        <textarea v-model="cancelReason" rows="3" maxlength="500"
                            placeholder="Ej. El cliente exigió devolución, cobro registrado al cliente equivocado..."
                            class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300"></textarea>
                    </div>
                    <p v-if="cancelError" class="mt-2 text-xs font-semibold text-red-600">{{ cancelError }}</p>
                    <div class="mt-4 flex gap-2">
                        <button @click="showCancelConfirm = false" :disabled="cancelling"
                            class="h-11 flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-200 disabled:opacity-50">
                            No cancelar
                        </button>
                        <button @click="submitCancel" :disabled="cancelling"
                            class="flex h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">
                            <svg v-if="cancelling" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path></svg>
                            Sí, cancelar
                        </button>
                    </div>
                </div>
                </div>
            </Transition>
        </div>
    </Modal>
</template>
