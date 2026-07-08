<script setup>
import { computed } from 'vue';

// Editor de un borrador de cambio de precio base. Muta el `form` compartido.
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ products: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    preview: { type: Object, default: () => ({}) },
});

const money = (n) => '$' + (Number(n) || 0).toFixed(2);

const selected = computed(() => (props.options.products || []).find((p) => p.id === props.form.product_id) || null);

const productLabel = (p) => `${p.name} · ${money(p.current_price)}${p.unit_type ? ' / ' + p.unit_type : ''}`;

const belowCost = computed(() =>
    selected.value && selected.value.cost_price !== null && Number(props.form.new_price) > 0
        && Number(props.form.new_price) < Number(selected.value.cost_price),
);
</script>

<template>
    <div class="space-y-3">
        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Producto</label>
            <select
                v-model.number="form.product_id"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            >
                <option :value="null">Selecciona…</option>
                <option v-for="p in options.products" :key="p.id" :value="p.id">{{ productLabel(p) }}</option>
            </select>
            <p v-if="errors.product_id" class="mt-1 text-xs text-red-600">{{ errors.product_id[0] }}</p>
        </div>

        <div class="flex items-end gap-3">
            <div v-if="selected" class="pb-2 text-sm text-gray-500">
                <span class="block text-xs font-semibold text-gray-600">Precio actual</span>
                <span class="text-base font-bold text-gray-400 line-through">{{ money(selected.current_price) }}</span>
            </div>
            <svg v-if="selected" class="mb-3 h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
            <div class="flex-1">
                <label class="mb-1 block text-xs font-semibold text-gray-600">Nuevo precio</label>
                <input
                    v-model.number="form.new_price"
                    :disabled="disabled"
                    type="number"
                    step="0.01"
                    min="0.01"
                    inputmode="decimal"
                    class="w-full rounded-lg border-gray-300 text-base font-semibold focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.new_price" class="mt-1 text-xs text-red-600">{{ errors.new_price[0] }}</p>
                <p v-else-if="belowCost" class="mt-1 text-xs text-amber-700">⚠ Queda por debajo del costo ({{ money(selected.cost_price) }}).</p>
            </div>
        </div>

        <p class="text-xs text-gray-500">Solo cambia el precio base; las presentaciones no se modifican.</p>
    </div>
</template>
