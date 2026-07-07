<script setup>
import { computed } from 'vue';

// Editor de un borrador de cobro global a cliente (FIFO). Muta el `form`
// compartido. El desglose mostrado es el snapshot calculado por el backend al
// preparar el borrador; si el usuario cambia cliente/monto/método, el desglose
// final lo re-calcula el servidor al confirmar.
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ customers: [], payment_methods: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    preview: { type: Object, default: () => ({}) },
});

const money = (n) => '$' + (Number(n) || 0).toFixed(2);

const selected = computed(() => (props.options.customers || []).find((c) => c.id === props.form.customer_id) || null);

const customerLabel = (c) => `${c.name} · debe ${money(c.total_owed)}`;

const distribution = computed(() => props.preview?.distribution || null);

// El snapshot solo es exacto si el form sigue igual que cuando se calculó.
const snapshotMatches = computed(() =>
    distribution.value &&
    props.form.customer_id === props.preview.customer_id &&
    Number(props.form.amount_received) === Number(props.preview.amount_received) &&
    props.form.method === props.preview.method,
);
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Cliente que paga</label>
            <select
                v-model.number="form.customer_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="c in options.customers" :key="c.id" :value="c.id">{{ customerLabel(c) }}</option>
            </select>
            <p v-if="errors.customer_id" class="mt-1 text-xs text-red-600">{{ errors.customer_id[0] }}</p>
            <p v-else-if="selected" class="mt-1 text-xs text-gray-500">
                Deuda actual: <span class="font-semibold text-gray-700">{{ money(selected.total_owed) }}</span>
            </p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Monto recibido</label>
                <input
                    v-model.number="form.amount_received"
                    :disabled="disabled"
                    type="number"
                    step="0.01"
                    min="0.01"
                    inputmode="decimal"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.amount_received" class="mt-1 text-xs text-red-600">{{ errors.amount_received[0] }}</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Método de pago</label>
                <select
                    v-model="form.method"
                    :disabled="disabled"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                >
                    <option :value="null">Selecciona…</option>
                    <option v-for="m in options.payment_methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <p v-if="errors.method" class="mt-1 text-xs text-red-600">{{ errors.method[0] }}</p>
            </div>
        </div>

        <!-- Desglose FIFO calculado por el backend -->
        <div v-if="distribution && distribution.sales.length" class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="mb-2 text-xs font-semibold text-gray-600">Se aplicará a las ventas más antiguas:</p>
            <ul class="space-y-1.5">
                <li v-for="row in distribution.sales" :key="row.sale_id" class="flex items-center justify-between gap-2 text-xs">
                    <span class="min-w-0 truncate text-gray-600">
                        {{ row.folio }} <span v-if="row.date" class="text-gray-400">· {{ row.date }}</span>
                    </span>
                    <span class="shrink-0 font-semibold text-gray-800">
                        {{ money(row.amount_to_apply) }}
                        <span v-if="row.remaining_after > 0" class="font-normal text-amber-700">(queda {{ money(row.remaining_after) }})</span>
                        <span v-else class="font-normal text-emerald-700">(saldada)</span>
                    </span>
                </li>
            </ul>
            <div class="mt-2 space-y-0.5 border-t border-gray-100 pt-2 text-xs">
                <p class="flex justify-between text-gray-600">
                    <span>Total aplicado</span><span class="font-bold text-gray-900">{{ money(distribution.amount_to_apply) }}</span>
                </p>
                <p v-if="distribution.change_given > 0" class="flex justify-between text-gray-600">
                    <span>Cambio a entregar</span><span class="font-bold text-orange-700">{{ money(distribution.change_given) }}</span>
                </p>
                <p class="flex justify-between text-gray-600">
                    <span>Deuda restante</span><span class="font-bold text-gray-900">{{ money(distribution.remaining_debt) }}</span>
                </p>
            </div>
            <p v-if="!snapshotMatches" class="mt-2 text-[11px] italic text-amber-700">
                Cambiaste los datos: el desglose definitivo se calculará al confirmar.
            </p>
        </div>
        <p v-else-if="form.customer_id" class="text-xs italic text-gray-500">
            El desglose entre ventas se calculará al confirmar.
        </p>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Notas</label>
            <textarea
                v-model="form.notes"
                :disabled="disabled"
                rows="2"
                maxlength="500"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>
    </div>
</template>
