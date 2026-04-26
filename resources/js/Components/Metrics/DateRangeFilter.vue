<script setup>
// Filtro de rango de fechas estilo iOS — segmented control para presets
// + zona expandible para rango personalizado + resumen del rango aplicado.
//
// Uso:
//   <DateRangeFilter :filters="filters" />
//
// `filters` viene del composable useMetricsFilters. La API esperada:
//   - filters.preset (ref)
//   - filters.from / filters.to (refs)
//   - filters.isCustom (computed)
//   - filters.setPreset(name)
//   - filters.setCustom(from, to)
//
// Patrón visual: segmented control (iOS Calendar/Settings) — los presets
// agrupados en un contenedor neutro y el activo se "eleva" con bg blanco
// + sombra. El botón "Personalizado" es separado a la derecha y sigue el
// mismo patrón de elevación cuando está activo. La zona de inputs solo
// aparece (con animación) en modo custom.

import { computed } from 'vue';
import { formatRangeLabel, presetLabelsShort } from '@/composables/useDateRange';

const props = defineProps({
    filters: { type: Object, required: true },
    // Lista de presets a mostrar. Default cubre todos los rangos comunes.
    presets: {
        type: Array,
        default: () => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_year'],
    },
});

const isPresetActive = (p) => !props.filters.isCustom.value && props.filters.preset.value === p;
const isCustomActive = computed(() => props.filters.isCustom.value);

const customFrom = computed({
    get: () => props.filters.from.value,
    set: (v) => props.filters.setCustom(v, props.filters.to.value),
});
const customTo = computed({
    get: () => props.filters.to.value,
    set: (v) => props.filters.setCustom(props.filters.from.value, v),
});

const rangeLabel = computed(() => formatRangeLabel(props.filters));

const enableCustom = () => {
    // Si no hay from/to aún, default razonable: hoy → hoy. El backend valida.
    const today = new Date().toISOString().slice(0, 10);
    props.filters.setCustom(props.filters.from.value || today, props.filters.to.value || today);
};
</script>

<template>
    <div class="space-y-3">
        <!-- Segmented control + botón custom -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Segmented (presets) -->
            <div class="inline-flex rounded-xl bg-gray-100 p-1 shadow-inner">
                <button v-for="p in presets" :key="p" type="button" @click="filters.setPreset(p)"
                    :class="['relative rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200',
                        isPresetActive(p)
                            ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200/60'
                            : 'text-gray-500 hover:text-gray-700']">
                    {{ presetLabelsShort[p] || p }}
                </button>
            </div>

            <!-- Botón "Personalizado" como pill independiente -->
            <button type="button" @click="enableCustom"
                :class="['inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold transition active:scale-95',
                    isCustomActive
                        ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200'
                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-700']">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                Personalizado
                <span v-if="isCustomActive" class="ml-0.5 inline-block h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true" />
            </button>
        </div>

        <!-- Zona custom expandible -->
        <Transition
            enter-active-class="transition duration-250 ease-out"
            leave-active-class="transition duration-200 ease-in"
            enter-from-class="opacity-0 -translate-y-1 max-h-0"
            enter-to-class="opacity-100 translate-y-0 max-h-40"
            leave-from-class="opacity-100 max-h-40"
            leave-to-class="opacity-0 -translate-y-1 max-h-0">
            <div v-if="isCustomActive" class="overflow-hidden rounded-2xl bg-gradient-to-br from-gray-50 to-white p-4 ring-1 ring-gray-100">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Desde</label>
                        <input type="date" v-model="customFrom" :max="customTo || undefined"
                            class="mt-1 block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm font-medium text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div class="flex h-10 items-center justify-center sm:mb-1">
                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <label class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Hasta</label>
                        <input type="date" v-model="customTo" :min="customFrom || undefined"
                            class="mt-1 block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm font-medium text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </div>
            </div>
        </Transition>

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
