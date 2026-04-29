<script setup>
// Header genérico de pantallas de Métricas. API de props idéntica a la
// versión anterior — solo cambia el markup interno.
//
// Internamente delega los presets/custom range al componente
// DateRangeFilter para no duplicar lógica visual y permitir reuso fuera
// de Métricas (cortes, historial, etc.).

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DateRangeFilter from '@/Components/Metrics/DateRangeFilter.vue';
import { formatAbsoluteRange } from '@/composables/useDateRange';

const props = defineProps({
    title: String,
    subtitle: String,
    filters: { type: Object, required: true },
    // Props legacy — quedan declaradas para no romper callsites que las
    // pasen, pero ya no se usan internamente (DateRangeFilter usa su
    // propia lista corta y el useDateRange helper).
    presetLabels: { type: Object, default: null },
    showCompare: { type: Boolean, default: true },
    branches: { type: Array, default: () => [] },
    showBranchSelector: { type: Boolean, default: false },
});

const page = usePage();
const range = computed(() => page.props.range || null);
const compareEnabled = computed(() => props.filters.compare?.value === true);

const currentRangeLabel = computed(() => {
    if (!range.value) return '';
    return formatAbsoluteRange(range.value.from, range.value.to);
});
const previousRangeLabel = computed(() => {
    if (!range.value?.previous) return '';
    return formatAbsoluteRange(range.value.previous.from, range.value.previous.to);
});
</script>

<template>
    <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <!-- Top bar: título a la izquierda, controles globales a la derecha -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
                <p v-if="subtitle" class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Selector de sucursal (Empresa) -->
                <div v-if="showBranchSelector && branches.length" class="inline-flex items-center gap-2 rounded-xl bg-gray-50 px-3 py-1.5 ring-1 ring-gray-200">
                    <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Sucursal</span>
                    <select :value="filters.branchId.value ?? ''" @change="filters.setBranchId($event.target.value || null)"
                        class="rounded-lg border-0 bg-transparent py-1 pr-7 text-sm font-medium text-gray-900 focus:ring-2 focus:ring-red-300">
                        <option value="">Todas</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>

                <!-- Comparar con periodo previo -->
                <label v-if="showCompare" class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-100">
                    <input type="checkbox" :checked="filters.compare.value" @change="filters.setCompare($event.target.checked)"
                        class="h-3.5 w-3.5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                    Comparar
                </label>

                <!-- Refrescar -->
                <button @click="filters.refresh()" type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gray-900 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-gray-800 active:scale-95"
                    title="Limpiar caché y volver a calcular">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    Actualizar
                </button>
            </div>
        </div>

        <!-- Filtro de fechas (segmented + custom + resumen) -->
        <div class="px-5 py-4">
            <DateRangeFilter :filters="filters" />
        </div>

        <!-- Subheader: rango actual con fechas absolutas + comparativo -->
        <div v-if="range" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-100 bg-gray-50/60 px-5 py-2.5 text-xs">
            <span class="inline-flex items-center gap-1.5 font-semibold text-gray-700">
                <svg class="h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                Mostrando: <span class="font-bold text-gray-900">{{ currentRangeLabel }}</span>
                <span class="text-gray-400">· {{ range.label }}</span>
            </span>
            <span v-if="compareEnabled && previousRangeLabel" class="inline-flex items-center gap-1.5 text-gray-500">
                <span class="text-gray-300">|</span>
                <span>vs.</span>
                <span class="font-semibold text-gray-700">{{ previousRangeLabel }}</span>
                <span class="text-gray-400">(periodo previo)</span>
            </span>
        </div>
    </div>
</template>
