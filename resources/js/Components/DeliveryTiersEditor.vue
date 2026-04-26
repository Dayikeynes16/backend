<script setup>
// DeliveryTiersEditor — editor de rangos de envío.
// Cada rango: { max_km: number, fee: number }.
// Convención: el cliente está cubierto por el primer rango cuyo max_km
// sea >= la distancia. Lo ordenamos siempre ascendente para evitar
// ambigüedad. El backend además los ordena al guardar.
//
// Uso: <DeliveryTiersEditor v-model="form.delivery_tiers" />

import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const tiers = computed({
    get: () => Array.isArray(props.modelValue) ? props.modelValue : [],
    set: (v) => emit('update:modelValue', v),
});

const sorted = computed(() => {
    return [...tiers.value].map((t, i) => ({ ...t, _idx: i }))
        .sort((a, b) => Number(a.max_km ?? 0) - Number(b.max_km ?? 0));
});

const addTier = () => {
    const list = [...tiers.value];
    const lastKm = list.length > 0 ? Math.max(...list.map(t => Number(t.max_km) || 0)) : 0;
    list.push({ max_km: lastKm + 2, fee: 0 });
    emit('update:modelValue', list);
};

const removeTier = (idx) => {
    const list = [...tiers.value];
    list.splice(idx, 1);
    emit('update:modelValue', list);
};

const updateField = (idx, field, value) => {
    const list = [...tiers.value];
    list[idx] = { ...list[idx], [field]: value };
    emit('update:modelValue', list);
};

// Detecta duplicados de max_km (overlapping coverage).
const duplicateKms = computed(() => {
    const counts = {};
    for (const t of tiers.value) {
        const k = Number(t.max_km);
        if (!isNaN(k) && k > 0) counts[k] = (counts[k] || 0) + 1;
    }
    return Object.entries(counts).filter(([, c]) => c > 1).map(([k]) => Number(k));
});

const hasDuplicates = computed(() => duplicateKms.value.length > 0);

const formatKm = (km) => {
    const n = Number(km);
    if (isNaN(n)) return '—';
    return Number.isInteger(n) ? `${n}` : n.toFixed(1);
};

const formatFee = (fee) => {
    const n = Number(fee);
    if (isNaN(n)) return '—';
    return `$${n.toFixed(2)}`;
};
</script>

<template>
    <div class="space-y-3">
        <div v-if="tiers.length === 0" class="rounded-2xl border border-dashed border-gray-200 px-6 py-8 text-center">
            <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0M19.5 18.75a1.5 1.5 0 0 1-3 0M2.25 6h15.75a3 3 0 0 1 3 3v8.25H2.25Z" /></svg>
            <p class="mt-2 text-sm font-medium text-gray-500">Sin rangos configurados</p>
            <p class="mt-0.5 text-xs text-gray-400">Define al menos un rango para habilitar envío a domicilio.</p>
            <button type="button" @click="addTier"
                class="mt-4 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Agregar primer rango
            </button>
        </div>

        <div v-else class="overflow-hidden rounded-2xl ring-1 ring-gray-100">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/60">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Hasta (km)</th>
                        <th class="px-4 py-2.5 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Tarifa</th>
                        <th class="px-2 py-2.5 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 bg-white">
                    <tr v-for="t in sorted" :key="t._idx"
                        :class="duplicateKms.includes(Number(t.max_km)) ? 'bg-red-50/40' : ''">
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" min="0.1"
                                    :value="t.max_km"
                                    @input="updateField(t._idx, 'max_km', $event.target.value)"
                                    class="w-28 rounded-lg border-gray-200 bg-white py-1.5 font-mono text-sm tabular-nums text-gray-900 focus:border-red-400 focus:ring-red-300"
                                    placeholder="3" />
                                <span class="text-xs font-medium text-gray-500">km</span>
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-400">$</span>
                                <input type="number" step="1" min="0"
                                    :value="t.fee"
                                    @input="updateField(t._idx, 'fee', $event.target.value)"
                                    class="w-28 rounded-lg border-gray-200 bg-white py-1.5 font-mono text-sm tabular-nums text-gray-900 focus:border-red-400 focus:ring-red-300"
                                    placeholder="30" />
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-right">
                            <button type="button" @click="removeTier(t._idx)"
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                                aria-label="Eliminar rango">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="flex items-center justify-between border-t border-gray-100 bg-gray-50/60 px-4 py-2.5">
                <p class="text-xs text-gray-500">Las tarifas se aplican al cubrir hasta esa distancia.</p>
                <button type="button" @click="addTier"
                    class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-50 active:scale-95">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Agregar rango
                </button>
            </div>
        </div>

        <!-- Validación de duplicados -->
        <div v-if="hasDuplicates" class="flex items-start gap-2 rounded-xl bg-red-50 px-4 py-3 ring-1 ring-red-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <p class="text-xs text-red-700">
                Hay rangos con la misma distancia ({{ duplicateKms.map(formatKm).join(', ') }} km). Cada rango debe tener un valor único.
            </p>
        </div>

        <!-- Resumen -->
        <p v-if="tiers.length > 0 && !hasDuplicates" class="px-1 text-xs text-gray-500">
            <span class="font-medium text-gray-700">Resumen:</span>
            <template v-for="(t, i) in sorted" :key="`s${t._idx}`">
                <span v-if="i > 0" class="text-gray-300"> · </span>
                hasta {{ formatKm(t.max_km) }} km {{ formatFee(t.fee) }}
            </template>
        </p>
    </div>
</template>
