<script setup>
import { computed } from 'vue';

// Editor de un borrador de abono a una compra. Muta el objeto `form` compartido.
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ purchases: [], payment_methods: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const money = (n) => '$' + (Number(n) || 0).toFixed(2);

const selected = computed(() => (props.options.purchases || []).find((p) => p.id === props.form.purchase_id) || null);

const exceedsBalance = computed(() =>
    selected.value && Number(props.form.amount) > Number(selected.value.amount_pending) + 0.001,
);

const purchaseLabel = (p) => `${p.folio} · ${p.provider_name || 'Proveedor'} · saldo ${money(p.amount_pending)}`;
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Compra a pagar</label>
            <select
                v-model.number="form.purchase_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="p in options.purchases" :key="p.id" :value="p.id">{{ purchaseLabel(p) }}</option>
            </select>
            <p v-if="errors.purchase_id" class="mt-1 text-xs text-red-600">{{ errors.purchase_id[0] }}</p>
            <p v-else-if="selected" class="mt-1 text-xs text-gray-500">
                Saldo pendiente: <span class="font-semibold text-gray-700">{{ money(selected.amount_pending) }}</span>
            </p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Monto del abono</label>
                <input
                    v-model.number="form.amount"
                    :disabled="disabled"
                    type="number"
                    step="0.01"
                    min="0.01"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount[0] }}</p>
                <p v-else-if="exceedsBalance" class="mt-1 text-xs text-amber-700">⚠ El monto excede el saldo pendiente.</p>
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
