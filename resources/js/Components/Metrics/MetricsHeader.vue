<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: String,
    subtitle: String,
    filters: Object,
    presetLabels: {
        type: Object,
        default: () => ({
            today: 'Hoy',
            yesterday: 'Ayer',
            last_7_days: '7 días',
            this_month: 'Este mes',
            last_month: 'Mes pasado',
            this_year: 'Este año',
        }),
    },
    showCompare: { type: Boolean, default: true },
    branches: { type: Array, default: () => [] },
    showBranchSelector: { type: Boolean, default: false },
});

const presets = ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_year'];

const isPresetActive = (p) => !props.filters.isCustom && props.filters.preset.value === p;

const customFrom = computed({
    get: () => props.filters.from.value,
    set: (v) => props.filters.setCustom(v, props.filters.to.value),
});
const customTo = computed({
    get: () => props.filters.to.value,
    set: (v) => props.filters.setCustom(props.filters.from.value, v),
});
</script>

<template>
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-gray-100 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
                <p v-if="subtitle" class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
            </div>

            <div class="flex items-center gap-2">
                <label v-if="showCompare" class="flex cursor-pointer items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 transition hover:bg-gray-100">
                    <input type="checkbox" :checked="filters.compare.value" @change="filters.setCompare($event.target.checked)" class="h-3.5 w-3.5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                    Comparar con periodo previo
                </label>
                <button @click="filters.refresh()" type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-gray-800">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    Actualizar
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 p-4">
            <div v-if="showBranchSelector && branches.length" class="flex items-center gap-2 border-r border-gray-200 pr-3">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sucursal</span>
                <select :value="filters.branchId.value ?? ''" @change="filters.setBranchId($event.target.value || null)" class="rounded-lg border-gray-200 bg-white py-1.5 pl-3 pr-8 text-sm font-medium text-gray-900 shadow-sm focus:border-red-500 focus:ring-red-500">
                    <option value="">Todas (consolidado)</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <button v-for="p in presets" :key="p" @click="filters.setPreset(p)" type="button"
                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        isPresetActive(p)
                            ? 'bg-red-600 text-white shadow-sm'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                    {{ presetLabels[p] }}
                </button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Personalizado</span>
                <input type="date" v-model="customFrom" class="rounded-lg border-gray-200 py-1.5 text-sm text-gray-900 focus:border-red-500 focus:ring-red-500" />
                <span class="text-gray-400">→</span>
                <input type="date" v-model="customTo" class="rounded-lg border-gray-200 py-1.5 text-sm text-gray-900 focus:border-red-500 focus:ring-red-500" />
            </div>
        </div>
    </div>
</template>
