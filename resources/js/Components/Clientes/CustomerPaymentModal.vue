<script setup>
import Modal from '@/Components/Modal.vue';
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
    show: Boolean,
    tenantSlug: { type: String, required: true },
    customer: { type: Object, default: null },
    pendingSales: { type: Array, default: () => [] },
    allowedMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    shiftOpen: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'success']);

const method = ref('cash');
const amountReceived = ref(null);
const amountInputRef = ref(null);
const excluded = ref(new Set());
const submitting = ref(false);
const serverError = ref(null);

watch(() => props.show, async (v) => {
    if (v) {
        method.value = props.allowedMethods.includes('cash') ? 'cash' : props.allowedMethods[0];
        amountReceived.value = null;
        excluded.value = new Set();
        serverError.value = null;
        await nextTick();
        amountInputRef.value?.focus();
    }
});

const totalOwed = computed(() =>
    props.pendingSales.reduce((acc, s) => acc + Number(s.amount_pending), 0)
);

const selectedSales = computed(() =>
    props.pendingSales.filter(s => !excluded.value.has(s.id))
);

const totalSelected = computed(() =>
    selectedSales.value.reduce((acc, s) => acc + Number(s.amount_pending), 0)
);

const amountToApply = computed(() => {
    const received = Number(amountReceived.value) || 0;
    return Math.min(received, totalSelected.value);
});

const changeGiven = computed(() => {
    const received = Number(amountReceived.value) || 0;
    return Math.max(received - amountToApply.value, 0);
});

// Preview FIFO distribution over selected sales
const distribution = computed(() => {
    const sortedSelected = [...selectedSales.value].sort((a, b) =>
        new Date(a.created_at) - new Date(b.created_at)
    );
    let remaining = amountToApply.value;
    const byId = {};
    for (const s of sortedSelected) {
        if (remaining <= 0) { byId[s.id] = 0; continue; }
        const portion = Math.min(remaining, Number(s.amount_pending));
        byId[s.id] = portion;
        remaining -= portion;
    }
    return byId;
});

const newPendingById = computed(() => {
    const map = {};
    for (const s of props.pendingSales) {
        if (excluded.value.has(s.id)) {
            map[s.id] = Number(s.amount_pending);
        } else {
            const applied = distribution.value[s.id] || 0;
            map[s.id] = Math.max(Number(s.amount_pending) - applied, 0);
        }
    }
    return map;
});

const cardExcessInvalid = computed(() => {
    if (method.value === 'cash') return false;
    const received = Number(amountReceived.value) || 0;
    return received > totalSelected.value + 0.001;
});

const canSubmit = computed(() => {
    if (submitting.value) return false;
    if (!props.shiftOpen) return false;
    const received = Number(amountReceived.value) || 0;
    if (received <= 0) return false;
    if (selectedSales.value.length === 0) return false;
    if (cardExcessInvalid.value) return false;
    return true;
});

const toggleExclude = (id) => {
    const s = new Set(excluded.value);
    if (s.has(id)) s.delete(id); else s.add(id);
    excluded.value = s;
};

const setAmountQuick = (n) => { amountReceived.value = Number(n).toFixed(2); };

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (v) => {
    if (!v) return '—';
    return new Date(v).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
};
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const submitLabel = computed(() => {
    if (!canSubmit.value && cardExcessInvalid.value) return 'Monto excede el adeudado';
    if (!canSubmit.value) return 'Cobrar';
    if (changeGiven.value > 0) return `Cobrar ${money(amountToApply.value)} · cambio ${money(changeGiven.value)}`;
    if (amountToApply.value < totalSelected.value) return `Cobrar ${money(amountToApply.value)} (parcial)`;
    return `Cobrar ${money(amountToApply.value)}`;
});

const submit = async () => {
    if (!canSubmit.value) return;
    submitting.value = true;
    serverError.value = null;
    try {
        const res = await fetch(
            route('sucursal.clientes.cobro-global', [props.tenantSlug, props.customer.id]),
            {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    amount_received: Number(amountReceived.value),
                    method: method.value,
                    excluded_sale_ids: Array.from(excluded.value),
                }),
            }
        );
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            serverError.value = err.message || err.errors?.amount_received?.[0] || err.errors?.method?.[0] || 'Error al registrar el pago';
            return;
        }
        const data = await res.json();
        emit('success', data);
        emit('close');
    } catch (e) {
        serverError.value = e.message || 'Error de red';
    } finally {
        submitting.value = false;
    }
};

const methodIcons = {
    cash: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    card: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
    transfer: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div v-if="customer">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-7 py-5">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Registrar pago</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ customer.name }} · {{ customer.phone }}</p>
                </div>
                <button @click="emit('close')" class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Total owed banner -->
            <div class="flex items-baseline justify-between gap-4 bg-red-50/60 px-7 py-4 ring-1 ring-inset ring-red-100">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-red-700">Adeudado total</p>
                    <p class="text-2xl font-bold tabular-nums text-red-700">{{ money(totalOwed) }}</p>
                </div>
                <p class="text-xs font-semibold text-red-700">{{ pendingSales.length }} venta{{ pendingSales.length !== 1 ? 's' : '' }} con saldo</p>
            </div>

            <div class="max-h-[60vh] overflow-y-auto px-7 py-5 space-y-5">
                <!-- Método -->
                <div>
                    <label class="mb-2 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Método de pago</label>
                    <div class="inline-flex rounded-xl bg-gray-100 p-1">
                        <button v-for="m in allowedMethods" :key="m" type="button" @click="method = m"
                            :class="['flex h-11 items-center gap-2 rounded-lg px-4 text-sm font-bold transition',
                                method === m ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="methodIcons[m]" />
                            </svg>
                            {{ methodLabel(m) }}
                        </button>
                    </div>
                </div>

                <!-- Monto recibido -->
                <div>
                    <label class="mb-2 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Monto recibido</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-2xl font-bold text-gray-400">$</span>
                        <input
                            ref="amountInputRef"
                            v-model="amountReceived"
                            type="number"
                            step="0.01"
                            min="0.01"
                            inputmode="decimal"
                            placeholder="0.00"
                            :class="['h-14 w-full rounded-xl border-gray-200 pl-10 pr-4 text-2xl font-bold tabular-nums focus:border-red-400 focus:ring-red-300',
                                cardExcessInvalid ? 'border-red-400 ring-1 ring-red-300' : '']" />
                    </div>
                    <p v-if="cardExcessInvalid" class="mt-1.5 text-xs font-semibold text-red-600">
                        Con {{ methodLabel(method) }} no hay cambio — monto máximo {{ money(totalSelected) }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button type="button" @click="setAmountQuick(totalSelected)"
                            class="h-8 rounded-lg bg-gray-100 px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-200">
                            Saldar todo ({{ money(totalSelected) }})
                        </button>
                    </div>
                </div>

                <!-- Distribución preview -->
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Distribución · más antiguas primero</label>
                        <span v-if="excluded.size > 0" class="text-xs font-semibold text-gray-500">{{ excluded.size }} excluida{{ excluded.size !== 1 ? 's' : '' }}</span>
                    </div>
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl ring-1 ring-gray-100">
                        <li v-for="s in [...pendingSales].sort((a,b) => new Date(a.created_at) - new Date(b.created_at))"
                            :key="s.id"
                            :class="['flex items-center gap-3 px-4 py-3 transition',
                                excluded.has(s.id) ? 'bg-gray-50' : 'bg-white']">
                            <label class="flex shrink-0 cursor-pointer items-center">
                                <input type="checkbox"
                                    :checked="!excluded.has(s.id)"
                                    @change="toggleExclude(s.id)"
                                    class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-400" />
                            </label>
                            <div class="flex-1 min-w-0">
                                <p :class="['text-sm font-bold', excluded.has(s.id) ? 'text-gray-400 line-through' : 'text-gray-900']">
                                    {{ s.folio }}
                                </p>
                                <p class="text-xs text-gray-500">{{ fmtDate(s.created_at) }} · saldo {{ money(s.amount_pending) }}</p>
                            </div>
                            <div class="text-right">
                                <template v-if="excluded.has(s.id)">
                                    <p class="text-xs font-semibold text-gray-400">excluida</p>
                                </template>
                                <template v-else-if="distribution[s.id] > 0">
                                    <p class="text-sm font-bold tabular-nums text-gray-900">{{ money(distribution[s.id]) }}</p>
                                    <p :class="['text-xs font-semibold tabular-nums', newPendingById[s.id] <= 0 ? 'text-green-600' : 'text-amber-600']">
                                        {{ newPendingById[s.id] <= 0 ? '✓ saldo $0' : `queda ${money(newPendingById[s.id])}` }}
                                    </p>
                                </template>
                                <template v-else>
                                    <p class="text-xs text-gray-400">sin cambio</p>
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Cambio banner -->
                <div v-if="changeGiven > 0 && method === 'cash'"
                    class="flex items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.25 0-2.25-2.25-2.25-2.25" /></svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-amber-900">Cambio a entregar al cliente</p>
                        <p class="text-xs text-amber-700">Entrega efectivo físico por esta cantidad</p>
                    </div>
                    <p class="text-xl font-bold tabular-nums text-amber-700">{{ money(changeGiven) }}</p>
                </div>

                <!-- Server error -->
                <div v-if="serverError" class="rounded-lg bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 ring-1 ring-red-200">
                    {{ serverError }}
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-7 py-4">
                <button @click="emit('close')" class="h-11 rounded-lg bg-white px-5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Cancelar</button>
                <button @click="submit" :disabled="!canSubmit"
                    class="flex h-11 items-center gap-2 rounded-lg bg-red-600 px-6 text-sm font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg v-if="submitting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path></svg>
                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    {{ submitLabel }}
                </button>
            </div>
        </div>
    </Modal>
</template>
