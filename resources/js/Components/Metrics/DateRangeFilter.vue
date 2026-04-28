<script setup>
// Filtro de rango de fechas para Métricas. Usa el componente reutilizable
// `DateField` (mode="range") para la captura del rango personalizado, y
// mantiene los presets segmented control + summary "Mostrando: X" como antes.
//
// API hacia el composable `useMetricsFilters` no cambia: el componente recibe
// el objeto `filters` con preset/from/to/isCustom/setPreset/setCustom.

import { computed } from 'vue';
import DateField from '@/Components/DateField.vue';
import { formatRangeLabel, presetLabelsShort } from '@/composables/useDateRange';

const props = defineProps({
    filters: { type: Object, required: true },
    presets: {
        type: Array,
        default: () => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_year'],
    },
});

const isPresetActive = (p) => !props.filters.isCustom.value && props.filters.preset.value === p;
const isCustomActive = computed(() => props.filters.isCustom.value);

// v-model que se enlaza con setCustom del composable. DateField emite
// { from, to } cuando el usuario cierra la selección.
const customRange = computed({
    get: () => ({ from: props.filters.from.value || '', to: props.filters.to.value || '' }),
    set: (v) => {
        if (v && v.from && v.to) props.filters.setCustom(v.from, v.to);
    },
});

const rangeLabel = computed(() => formatRangeLabel(props.filters));

const enableCustom = () => {
    const today = new Date().toISOString().slice(0, 10);
    props.filters.setCustom(props.filters.from.value || today, props.filters.to.value || today);
};
</script>

<template>
    <div class="space-y-3">
        <!-- Segmented control + DateField custom -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-xl bg-gray-100 p-1 shadow-inner">
                <button v-for="p in presets" :key="p" type="button" @click="filters.setPreset(p)"
                    :class="['relative rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200',
                        isPresetActive(p)
                            ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200/60'
                            : 'text-gray-500 hover:text-gray-700']">
                    {{ presetLabelsShort[p] || p }}
                </button>
            </div>

            <!-- Custom range trigger / actual DateField cuando ya está activo -->
            <DateField v-if="isCustomActive" v-model="customRange" mode="range" :presets="[]" align="left" size="sm" />
            <button v-else type="button" @click="enableCustom"
                class="inline-flex items-center gap-1.5 rounded-xl bg-gray-100 px-3 py-2 text-xs font-semibold text-gray-500 transition hover:bg-gray-200 hover:text-gray-700 active:scale-95">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                Personalizado
            </button>
        </div>

        <!-- Resumen del rango aplicado -->
        <div class="flex items-center gap-2 px-1 text-xs text-gray-500">
            <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
            <span>Mostrando:</span>
            <span class="font-semibold text-gray-700">{{ rangeLabel }}</span>
        </div>
    </div>
</template>
