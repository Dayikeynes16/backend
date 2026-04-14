<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:from', 'update:to', 'apply']);

const localFrom = ref(props.from);
const localTo = ref(props.to);
const activePreset = ref(props.from || props.to ? 'custom' : '30d');

watch(() => props.from, v => { localFrom.value = v; });
watch(() => props.to, v => { localTo.value = v; });

const pad = (n) => String(n).padStart(2, '0');
const toIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

const presets = [
    { key: 'today', label: 'Hoy', compute: () => {
        const now = new Date();
        return { from: toIso(now), to: toIso(now) };
    }},
    { key: 'yesterday', label: 'Ayer', compute: () => {
        const d = new Date(); d.setDate(d.getDate() - 1);
        return { from: toIso(d), to: toIso(d) };
    }},
    { key: '7d', label: 'Últimos 7 días', compute: () => {
        const to = new Date();
        const from = new Date(); from.setDate(to.getDate() - 6);
        return { from: toIso(from), to: toIso(to) };
    }},
    { key: '30d', label: 'Últimos 30 días', compute: () => {
        const to = new Date();
        const from = new Date(); from.setDate(to.getDate() - 29);
        return { from: toIso(from), to: toIso(to) };
    }},
    { key: 'month', label: 'Este mes', compute: () => {
        const now = new Date();
        const from = new Date(now.getFullYear(), now.getMonth(), 1);
        return { from: toIso(from), to: toIso(now) };
    }},
    { key: 'lastmonth', label: 'Mes pasado', compute: () => {
        const now = new Date();
        const from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const to = new Date(now.getFullYear(), now.getMonth(), 0);
        return { from: toIso(from), to: toIso(to) };
    }},
    { key: 'all', label: 'Todo', compute: () => ({ from: '', to: '' }) },
];

const applyPreset = (preset) => {
    activePreset.value = preset.key;
    const { from, to } = preset.compute();
    localFrom.value = from;
    localTo.value = to;
    emit('update:from', from);
    emit('update:to', to);
    emit('apply');
};

const onCustomChange = () => {
    activePreset.value = 'custom';
    emit('update:from', localFrom.value);
    emit('update:to', localTo.value);
};

const apply = () => emit('apply');

const invalid = computed(() => {
    if (!localFrom.value || !localTo.value) return false;
    return localFrom.value > localTo.value;
});

const displayRange = computed(() => {
    if (!localFrom.value && !localTo.value) return 'Todo el historial';
    const fmt = (iso) => {
        if (!iso) return '...';
        const d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    if (localFrom.value === localTo.value) return fmt(localFrom.value);
    return `${fmt(localFrom.value)} → ${fmt(localTo.value)}`;
});
</script>

<template>
    <div class="space-y-3">
        <!-- Preset chips -->
        <div class="flex flex-wrap gap-2">
            <button v-for="p in presets" :key="p.key" type="button" @click="applyPreset(p)"
                :class="['h-10 rounded-lg px-4 text-sm font-semibold transition',
                    activePreset === p.key
                        ? 'bg-red-600 text-white shadow-sm'
                        : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 hover:ring-gray-300']">
                {{ p.label }}
            </button>
        </div>

        <!-- Custom range + apply -->
        <div class="flex flex-wrap items-end gap-3 rounded-xl bg-gray-50/70 p-4 ring-1 ring-gray-100">
            <div class="flex-1 min-w-[160px]">
                <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Desde</label>
                <input v-model="localFrom" @change="onCustomChange" type="date"
                    class="h-11 w-full rounded-lg border-gray-200 text-sm font-medium focus:border-red-400 focus:ring-red-300" />
            </div>
            <div class="flex items-center self-center pt-5 text-gray-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" /></svg>
            </div>
            <div class="flex-1 min-w-[160px]">
                <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Hasta</label>
                <input v-model="localTo" @change="onCustomChange" type="date" :min="localFrom"
                    class="h-11 w-full rounded-lg border-gray-200 text-sm font-medium focus:border-red-400 focus:ring-red-300" />
            </div>

            <button type="button" @click="apply" :disabled="invalid || loading"
                class="flex h-11 items-center gap-2 rounded-lg bg-red-600 px-5 text-sm font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                <svg v-if="loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path>
                </svg>
                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ loading ? 'Cargando...' : 'Aplicar' }}
            </button>
        </div>

        <!-- Range display -->
        <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-1.5 text-gray-500">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                <span class="font-semibold">{{ displayRange }}</span>
            </div>
            <p v-if="invalid" class="font-semibold text-red-600">La fecha "Desde" no puede ser mayor que "Hasta"</p>
        </div>
    </div>
</template>
