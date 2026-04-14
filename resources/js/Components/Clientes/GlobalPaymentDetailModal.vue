<script setup>
import Modal from '@/Components/Modal.vue';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    show: Boolean,
    tenantSlug: { type: String, required: true },
    customerId: { type: [Number, String], default: null },
    customerPaymentId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close', 'open-sale', 'delete']);

const data = ref(null);
const loading = ref(false);
const error = ref(null);
let controller = null;

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

        <div v-else-if="data">
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

            <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/60 px-7 py-4">
                <button @click="emit('close')" class="h-10 rounded-lg bg-white px-5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Cerrar</button>
            </div>
        </div>
    </Modal>
</template>
