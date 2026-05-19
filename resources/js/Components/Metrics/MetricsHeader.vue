<script setup>
// Header genérico de pantallas de Métricas. Selector de fecha simplificado
// (Hoy / Ayer / 7 días / Calendario) + selector de sucursal (Empresa) +
// chip de estados donde aplica. Sin toggle Comparar ni botón Actualizar:
// las pantallas viven en el rango elegido sin capa de comparativa.

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DateRangeFilter from '@/Components/Metrics/DateRangeFilter.vue';
import StatusFilterChips from '@/Components/Metrics/StatusFilterChips.vue';
import { formatAbsoluteRange } from '@/composables/useDateRange';

const props = defineProps({
    title: String,
    subtitle: String,
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    showBranchSelector: { type: Boolean, default: false },
    // Solo mostrar el chip de estados en pantallas de "venta generada"
    // (Resumen, Ventas, Productos, Clientes). En Margen/Cobranza/Cajeros/
    // Turnos no aplica porque esas usan su propia lógica fija.
    showStatusChip: { type: Boolean, default: false },
    statusChipShowCancelled: { type: Boolean, default: false },
});

const page = usePage();
const range = computed(() => page.props.range || null);

const currentRangeLabel = computed(() => {
    if (!range.value) return '';
    return formatAbsoluteRange(range.value.from, range.value.to);
});
</script>

<template>
    <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <!-- Top bar: título a la izquierda, selector de sucursal a la derecha -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
                <p v-if="subtitle" class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
            </div>

            <div v-if="showBranchSelector && branches.length" class="flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center gap-2 rounded-xl bg-gray-50 px-3 py-1.5 ring-1 ring-gray-200">
                    <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Sucursal</span>
                    <select :value="filters.branchId.value ?? ''" @change="filters.setBranchId($event.target.value || null)"
                        class="rounded-lg border-0 bg-transparent py-1 pr-7 text-sm font-medium text-gray-900 focus:ring-2 focus:ring-red-300">
                        <option value="">Todas</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Filtro de fechas (segmented + custom + resumen) -->
        <div class="px-5 py-4">
            <DateRangeFilter :filters="filters" />
        </div>

        <!-- Chip de estados (solo en pantallas de "venta generada") -->
        <div v-if="showStatusChip" class="border-t border-gray-100 px-5 py-3">
            <StatusFilterChips :filters="filters" :show-cancelled="statusChipShowCancelled" compact />
        </div>

        <!-- Subheader: rango actual con fechas absolutas -->
        <div v-if="range" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-gray-100 bg-gray-50/60 px-5 py-2.5 text-xs">
            <span class="inline-flex items-center gap-1.5 font-semibold text-gray-700">
                <svg class="h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                Mostrando: <span class="font-bold text-gray-900">{{ currentRangeLabel }}</span>
                <span class="text-gray-400">· {{ range.label }}</span>
            </span>
        </div>
    </div>
</template>
