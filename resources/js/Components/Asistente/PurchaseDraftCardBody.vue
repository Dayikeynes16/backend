<script setup>
import { computed } from 'vue';

// Editor de un borrador de compra: proveedor, sucursal, líneas y total. Muta el
// objeto `form` compartido (mismo reference que el wrapper).
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({ providers: [], branches: [], units: [] }) },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const total = computed(() =>
    (props.form.items || []).reduce(
        (sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0),
        0,
    ),
);

const money = (n) => '$' + (Number(n) || 0).toFixed(2);

function addRow() {
    props.form.items.push({ concept: '', quantity: 1, unit: 'kg', unit_price: 0, purchase_product_id: null });
}

function removeRow(i) {
    props.form.items.splice(i, 1);
}
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Proveedor</label>
                <select
                    v-model.number="form.provider_id"
                    :disabled="disabled"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                >
                    <option :value="null">Selecciona…</option>
                    <option v-for="p in options.providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <p v-if="errors.provider_id" class="mt-1 text-xs text-red-600">{{ errors.provider_id[0] }}</p>
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
                <label class="mb-1 block text-xs font-semibold text-gray-600">Fecha</label>
                <input
                    v-model="form.purchased_at"
                    :disabled="disabled"
                    type="date"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
                <p v-if="errors.purchased_at" class="mt-1 text-xs text-red-600">{{ errors.purchased_at[0] }}</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold text-gray-600">Factura (folio proveedor)</label>
                <input
                    v-model="form.invoice_number"
                    :disabled="disabled"
                    type="text"
                    maxlength="60"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                />
            </div>
        </div>

        <!-- Líneas -->
        <div>
            <div class="mb-1 flex items-center justify-between">
                <label class="text-xs font-semibold text-gray-600">Conceptos</label>
                <button type="button" :disabled="disabled" @click="addRow" class="text-xs font-semibold text-orange-700 hover:text-orange-900 disabled:opacity-50">+ Agregar línea</button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-left text-gray-500">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Concepto</th>
                            <th class="px-2 py-1.5 font-medium">Cant.</th>
                            <th class="px-2 py-1.5 font-medium">Unidad</th>
                            <th class="px-2 py-1.5 font-medium">Precio</th>
                            <th class="px-2 py-1.5 text-right font-medium">Subtotal</th>
                            <th class="px-1 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in form.items" :key="i" class="border-t border-gray-100">
                            <td class="px-2 py-1">
                                <input v-model="l.concept" :disabled="disabled" type="text" maxlength="160" class="w-40 rounded border-gray-300 text-xs focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
                            </td>
                            <td class="px-2 py-1">
                                <input v-model.number="l.quantity" :disabled="disabled" type="number" step="0.001" min="0.001" class="w-20 rounded border-gray-300 text-xs focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
                            </td>
                            <td class="px-2 py-1">
                                <input v-model="l.unit" :disabled="disabled" list="purchase-units" type="text" maxlength="10" class="w-20 rounded border-gray-300 text-xs focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
                            </td>
                            <td class="px-2 py-1">
                                <input v-model.number="l.unit_price" :disabled="disabled" type="number" step="0.01" min="0" class="w-24 rounded border-gray-300 text-xs focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50" />
                            </td>
                            <td class="px-2 py-1 text-right font-medium text-gray-700">
                                {{ money((Number(l.quantity) || 0) * (Number(l.unit_price) || 0)) }}
                            </td>
                            <td class="px-1 py-1 text-right">
                                <button type="button" :disabled="disabled" @click="removeRow(i)" class="text-gray-400 hover:text-red-600 disabled:opacity-50" title="Quitar">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!form.items.length">
                            <td colspan="6" class="px-2 py-3 text-center italic text-gray-400">Sin conceptos. Agrega al menos uno.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <datalist id="purchase-units">
                <option v-for="u in options.units" :key="u" :value="u" />
            </datalist>

            <div class="mt-2 flex justify-end text-sm font-semibold text-gray-800">
                Total: {{ money(total) }}
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold text-gray-600">Notas</label>
            <textarea
                v-model="form.notes"
                :disabled="disabled"
                rows="2"
                maxlength="2000"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
            />
        </div>
    </div>
</template>
