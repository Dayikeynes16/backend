<script setup>
// Chips toggleables para filtrar qué estados de venta entran en las métricas
// de "venta generada". Default expone Completed/Pending (las opciones que
// piden los usuarios). Pasar :show-cancelled para incluir el chip de canceladas
// en pantallas que sí lo usan (e.g. Productos).
import { computed } from 'vue';

const props = defineProps({
    filters: { type: Object, required: true },
    showCancelled: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const isActive = (s) => (props.filters.statuses?.value || []).includes(s);

const noneSelected = computed(() => (props.filters.statuses?.value || []).length === 0);

const allChips = [
    {
        key: 'completed',
        label: 'Completadas',
        toneActive: 'bg-emerald-600 text-white shadow-sm',
        toneInactive: 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-200',
        icon: 'M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z',
    },
    {
        key: 'pending',
        label: 'Pendientes',
        toneActive: 'bg-amber-500 text-white shadow-sm',
        toneInactive: 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-amber-50 hover:text-amber-700 hover:ring-amber-200',
        icon: 'M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 1 0 1.06-1.06l-2.78-2.78V5Z',
    },
    {
        key: 'cancelled',
        label: 'Canceladas',
        toneActive: 'bg-rose-600 text-white shadow-sm',
        toneInactive: 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-rose-50 hover:text-rose-700 hover:ring-rose-200',
        icon: 'M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z',
    },
];

const chips = computed(() =>
    allChips.filter((c) => c.key !== 'cancelled' || props.showCancelled)
);

const labelClass = computed(() =>
    props.compact ? 'text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400'
        : 'text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400'
);

const buttonSize = computed(() =>
    props.compact ? 'px-2.5 py-1 text-[11px]' : 'px-3 py-1.5 text-xs'
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span :class="labelClass">Incluir</span>
        <button v-for="c in chips" :key="c.key" type="button" @click="filters.toggleStatus(c.key)"
            :class="['inline-flex items-center gap-1.5 rounded-full font-semibold transition active:scale-95',
                buttonSize,
                isActive(c.key) ? c.toneActive : c.toneInactive]">
            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd" :d="c.icon" clip-rule="evenodd" />
            </svg>
            {{ c.label }}
        </button>
        <span v-if="noneSelected" class="ml-1 inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
            Selecciona al menos un estado
        </span>
    </div>
</template>
