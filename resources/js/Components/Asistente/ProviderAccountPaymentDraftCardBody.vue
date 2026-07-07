<script setup>
import { computed } from 'vue';

// Editor de un borrador de pago "a cuenta" a proveedor (FIFO). Muta el `form`
// compartido. El desglose es el snapshot calculado por el backend al preparar
// el borrador; el definitivo lo re-calcula el servidor al confirmar.
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ providers: [], payment_methods: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    preview: { type: Object, default: () => ({}) },
});

const money = (n) => '$' + (Number(n) || 0).toFixed(2);

const selected = computed(() => (props.options.providers || []).find((p) => p.id === props.form.provider_id) || null);

const providerLabel = (p) => `${p.name} · saldo ${money(p.total_pending)}`;

const distribution = computed(() => props.preview?.distribution || null);

const snapshotMatches = computed(() =>
    distribution.value &&
    props.form.provider_id === props.preview.provider_id &&
    Number(props.form.amount) === Number(props.preview.amount),
);
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Proveedor a pagar</label>
            <select
                v-model.number="form.provider_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="p in options.providers" :key="p.id" :value="p.id">{{ providerLabel(p) }}</option>
            </select>
            <p v-if="errors.provider_id" class="mt-1 text-xs text-red-600">{{ errors.provider_id[0] }}</p>
            <p v-else-if="selected" class="mt-1 text-xs text-gray-500">
                Saldo pendiente: <span class="font-semibold text-gray-700">{{ money(selected.total_pending) }}</span>
            </p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Monto del pago</label>
                <input
                    v-model.number="form.amount"
                    :disabled="disabled"
                    type="number"
                    step="0.01"
                    min="0.01"
                    inputmode="decimal"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount[0] }}</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Método de pago</label>
                <select
                    v-model="form.payment_method"
                    :disabled="disabled"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                >
                    <option :value="null">Selecciona…</option>
                    <option v-for="m in options.payment_methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <p v-if="errors.payment_method" class="mt-1 text-xs text-red-600">{{ errors.payment_method[0] }}</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Fecha</label>
                <input
                    v-model="form.paid_at"
                    :disabled="disabled"
                    type="date"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Referencia</label>
                <input
                    v-model="form.reference"
                    :disabled="disabled"
                    type="text"
                    maxlength="120"
                    placeholder="Folio de transferencia, etc."
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
            </div>
        </div>

        <!-- Desglose FIFO calculado por el backend -->
        <div v-if="distribution && distribution.purchases.length" class="rounded-lg border border-gray-200 bg-white p-3">
            <p class="mb-2 text-xs font-semibold text-gray-600">Se aplicará a las compras más antiguas:</p>
            <ul class="space-y-1.5">
                <li v-for="row in distribution.purchases" :key="row.purchase_id" class="flex items-center justify-between gap-2 text-xs">
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
                <p v-if="distribution.surplus > 0" class="flex justify-between text-gray-600">
                    <span>Excedente a favor del proveedor</span><span class="font-bold text-orange-700">{{ money(distribution.surplus) }}</span>
                </p>
                <p class="flex justify-between text-gray-600">
                    <span>Saldo restante con el proveedor</span>
                    <span class="font-bold text-gray-900">{{ money(distribution.total_pending - distribution.amount_to_apply) }}</span>
                </p>
            </div>
            <p v-if="!snapshotMatches" class="mt-2 text-[11px] italic text-amber-700">
                Cambiaste los datos: el desglose definitivo se calculará al confirmar.
            </p>
        </div>
        <p v-else-if="form.provider_id" class="text-xs italic text-gray-500">
            El desglose entre compras se calculará al confirmar.
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
