<script setup>
// Campos editables de un borrador de gasto dentro del chat. Muta el objeto
// `form` compartido (mismo reference que el wrapper) — las cifras salen de aquí,
// no del texto del modelo.
defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ branches: [], subcategories: [], payment_methods: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Concepto</label>
            <input
                v-model="form.concept"
                :disabled="disabled"
                type="text"
                maxlength="160"
                placeholder="p. ej. Recibo de luz CFE"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.concept" class="mt-1 text-xs text-red-600">{{ errors.concept[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Monto</label>
            <input
                v-model.number="form.amount"
                :disabled="disabled"
                type="number"
                step="0.01"
                min="0.01"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Fecha</label>
            <input
                v-model="form.expense_date"
                :disabled="disabled"
                type="date"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
            <p v-if="errors.expense_date" class="mt-1 text-xs text-red-600">{{ errors.expense_date[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Subcategoría</label>
            <select
                v-model.number="form.expense_subcategory_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="s in options.subcategories" :key="s.id" :value="s.id">
                    {{ s.category_name }} › {{ s.name }}
                </option>
            </select>
            <p v-if="errors.expense_subcategory_id" class="mt-1 text-xs text-red-600">{{ errors.expense_subcategory_id[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Sucursal</label>
            <select
                v-model.number="form.branch_id"
                :disabled="disabled || options.branches.length <= 1"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.nombre }}</option>
            </select>
            <p v-if="errors.branch_id" class="mt-1 text-xs text-red-600">{{ errors.branch_id[0] }}</p>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Método de pago</label>
            <select
                v-model="form.payment_method"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Sin especificar</option>
                <option v-for="m in options.payment_methods" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
        </div>

        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-gray-600">Descripción</label>
            <textarea
                v-model="form.description"
                :disabled="disabled"
                rows="2"
                maxlength="1000"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>
    </div>
</template>
