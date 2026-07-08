<script setup>
// Editor de un borrador de retiro de caja. Muta el `form` compartido.
defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    preview: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Monto a retirar</label>
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
                <label class="mb-1 block text-xs font-semibold text-gray-600">Motivo</label>
                <input
                    v-model="form.reason"
                    :disabled="disabled"
                    type="text"
                    maxlength="255"
                    placeholder="Gasolina, cambio, pago…"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.reason" class="mt-1 text-xs text-red-600">{{ errors.reason[0] }}</p>
            </div>
        </div>
        <p class="text-xs text-gray-500">
            El retiro se registrará en <span class="font-semibold text-gray-700">tu turno abierto</span> y aparecerá en el corte.
        </p>
    </div>
</template>
