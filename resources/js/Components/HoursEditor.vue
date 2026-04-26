<script setup>
// HoursEditor — editor de horarios semanales reutilizable.
//
// Uso:
//   <HoursEditor v-model="form.hours" :show-online-impact="form.online_ordering_enabled" />
//
// Modelo: { mon: {open, close} | null, tue: ..., ..., sun: ... }
// Día con valor null = cerrado. Día con {open: 'HH:mm', close: 'HH:mm'} = abierto.
//
// Filosofía:
//  - Toggle iOS por día (verde abierto, gris cerrado).
//  - Tres presets de un click ("Comercio típico", "Lun-Vie", "24/7").
//  - Acción "copiar a todos" por fila para replicar horario.
//  - Validación visual cuando close ≤ open.
//  - Vista previa humano-legible al final (mismo formato que summarizeHours del backend).
//  - Banner contextual cuando este horario afecta al menú web.

import { computed } from 'vue';
import TimePicker from '@/Components/TimePicker.vue';

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
    showOnlineImpact: { type: Boolean, default: false },
    presets: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const DAY_LABELS = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };
const DAY_FULL = { mon: 'Lunes', tue: 'Martes', wed: 'Miércoles', thu: 'Jueves', fri: 'Viernes', sat: 'Sábado', sun: 'Domingo' };

const DEFAULT_OPEN = '09:00';
const DEFAULT_CLOSE = '18:00';

// Construye un value normalizado a partir de modelValue, asegurando que las
// claves existan (evita errores de v-model con `null` o claves faltantes).
const normalized = computed(() => {
    const out = {};
    for (const day of DAY_KEYS) {
        const entry = props.modelValue?.[day];
        if (entry && entry.open && entry.close) {
            out[day] = { open: entry.open, close: entry.close };
        } else {
            out[day] = null;
        }
    }
    return out;
});

const isOpen = (day) => normalized.value[day] !== null;

const setDay = (day, value) => {
    const next = { ...normalized.value, [day]: value };
    emit('update:modelValue', next);
};

const toggleDay = (day) => {
    if (isOpen(day)) {
        setDay(day, null);
    } else {
        setDay(day, { open: DEFAULT_OPEN, close: DEFAULT_CLOSE });
    }
};

const updateField = (day, field, value) => {
    const current = normalized.value[day] || { open: DEFAULT_OPEN, close: DEFAULT_CLOSE };
    setDay(day, { ...current, [field]: value });
};

// Copia las horas del día al resto de los días que están abiertos. Si no
// hay otros días abiertos, los abre con esos horarios.
const copyToAll = (day) => {
    const source = normalized.value[day];
    if (!source) return;
    const next = {};
    for (const d of DAY_KEYS) {
        next[d] = { open: source.open, close: source.close };
    }
    emit('update:modelValue', next);
};

const copyToWeekdays = (day) => {
    const source = normalized.value[day];
    if (!source) return;
    const next = { ...normalized.value };
    for (const d of ['mon', 'tue', 'wed', 'thu', 'fri']) {
        next[d] = { open: source.open, close: source.close };
    }
    emit('update:modelValue', next);
};

const presetTipico = () => {
    const open = '07:00', close = '20:00';
    const next = {};
    for (const d of ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']) next[d] = { open, close };
    next.sun = null;
    emit('update:modelValue', next);
};

const preset24h = () => {
    const next = {};
    for (const d of DAY_KEYS) next[d] = { open: '00:00', close: '23:59' };
    emit('update:modelValue', next);
};

const presetLunVie = () => {
    const open = '09:00', close = '18:00';
    const next = {};
    for (const d of ['mon', 'tue', 'wed', 'thu', 'fri']) next[d] = { open, close };
    for (const d of ['sat', 'sun']) next[d] = null;
    emit('update:modelValue', next);
};

const clearAll = () => {
    const next = {};
    for (const d of DAY_KEYS) next[d] = null;
    emit('update:modelValue', next);
};

// Validación: cierre debe ser mayor a apertura (excepto rango 00:00-23:59 que cubre todo el día).
const dayInvalid = (day) => {
    const v = normalized.value[day];
    if (!v) return false;
    if (!v.open || !v.close) return true;
    return v.close <= v.open;
};

const anyInvalid = computed(() => DAY_KEYS.some(dayInvalid));

// Resumen humano-legible (replica la lógica de summarizeHours del backend
// para que el admin vea exactamente lo que se va a guardar).
const summary = computed(() => {
    const perDay = {};
    for (const d of DAY_KEYS) {
        const v = normalized.value[d];
        perDay[d] = (!v || !v.open || !v.close) ? 'cerrado' : `${v.open}-${v.close}`;
    }
    const groups = [];
    let startIdx = 0;
    for (let i = 1; i <= DAY_KEYS.length; i++) {
        const current = perDay[DAY_KEYS[startIdx]];
        const next = i < DAY_KEYS.length ? perDay[DAY_KEYS[i]] : null;
        if (current !== next) {
            const endIdx = i - 1;
            const startLabel = DAY_LABELS[DAY_KEYS[startIdx]];
            const endLabel = DAY_LABELS[DAY_KEYS[endIdx]];
            const range = startIdx === endIdx ? startLabel : `${startLabel}-${endLabel}`;
            groups.push(`${range} ${current}`);
            startIdx = i;
        }
    }
    return groups.join(', ');
});

const allClosed = computed(() => DAY_KEYS.every((d) => !isOpen(d)));
</script>

<template>
    <div class="space-y-3">
        <!-- Presets row -->
        <div v-if="presets" class="flex flex-wrap items-center gap-2">
            <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Aplicar preset</span>
            <button type="button" @click="presetTipico"
                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 active:scale-95">
                Comercio (L-S 7-20)
            </button>
            <button type="button" @click="presetLunVie"
                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 active:scale-95">
                Solo L-V
            </button>
            <button type="button" @click="preset24h"
                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 active:scale-95">
                24/7
            </button>
            <button type="button" @click="clearAll"
                class="ml-auto inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium text-gray-400 transition hover:text-gray-700 hover:bg-gray-100">
                Limpiar todos
            </button>
        </div>

        <!-- Day rows -->
        <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-100">
            <div v-for="(day, idx) in DAY_KEYS" :key="day"
                :class="['group flex flex-col gap-2 px-4 py-3 transition sm:flex-row sm:items-center sm:gap-4', idx > 0 && 'border-t border-gray-50', isOpen(day) ? 'bg-white' : 'bg-gray-50/40']">
                <!-- Día + toggle -->
                <div class="flex shrink-0 items-center gap-3 sm:w-32">
                    <button type="button" @click="toggleDay(day)"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', isOpen(day) ? 'bg-emerald-500' : 'bg-gray-300']"
                        :aria-label="isOpen(day) ? `Cerrar ${DAY_FULL[day]}` : `Abrir ${DAY_FULL[day]}`">
                        <span :class="['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', isOpen(day) ? 'translate-x-5' : 'translate-x-0.5']" />
                    </button>
                    <span :class="['text-sm font-bold', isOpen(day) ? 'text-gray-900' : 'text-gray-400']">{{ DAY_FULL[day] }}</span>
                </div>

                <!-- Inputs cuando abierto -->
                <div v-if="isOpen(day)" class="flex flex-1 items-center gap-3">
                    <div class="flex flex-1 items-center gap-2">
                        <div class="flex-1">
                            <label class="block text-[10px] font-medium uppercase tracking-wider text-gray-400">Abre</label>
                            <TimePicker
                                :model-value="normalized[day].open"
                                @update:model-value="updateField(day, 'open', $event)"
                                :error="dayInvalid(day)"
                                :aria-label="`Hora de apertura ${DAY_FULL[day]}`" />
                        </div>
                        <svg class="mt-4 h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" /></svg>
                        <div class="flex-1">
                            <label class="block text-[10px] font-medium uppercase tracking-wider text-gray-400">Cierra</label>
                            <TimePicker
                                :model-value="normalized[day].close"
                                @update:model-value="updateField(day, 'close', $event)"
                                :error="dayInvalid(day)"
                                :aria-label="`Hora de cierre ${DAY_FULL[day]}`" />
                        </div>
                    </div>

                    <!-- Acciones de copia (visibles en hover desktop, siempre en mobile) -->
                    <div class="flex shrink-0 items-center gap-1 opacity-0 transition group-hover:opacity-100 sm:opacity-0 max-sm:opacity-100">
                        <button type="button" @click="copyToWeekdays(day)" title="Copiar este horario a Lun-Vie"
                            class="rounded-md px-2 py-1 text-[11px] font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700">
                            → L-V
                        </button>
                        <button type="button" @click="copyToAll(day)" title="Copiar a todos los días"
                            class="rounded-md px-2 py-1 text-[11px] font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700">
                            → Todos
                        </button>
                    </div>
                </div>

                <!-- Cerrado state -->
                <div v-else class="flex flex-1 items-center justify-between">
                    <span class="text-sm italic text-gray-400">Cerrado</span>
                    <button type="button" @click="toggleDay(day)"
                        class="rounded-md px-2 py-1 text-[11px] font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700">
                        Abrir
                    </button>
                </div>

                <!-- Error inline -->
                <p v-if="dayInvalid(day)" class="text-[11px] text-red-600 sm:absolute sm:right-4 sm:bottom-1">
                    El cierre debe ser después de la apertura.
                </p>
            </div>
        </div>

        <!-- Vista previa + estado -->
        <div class="flex flex-col gap-2 rounded-xl bg-gradient-to-r from-gray-50 to-white px-4 py-3 ring-1 ring-gray-100 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-2">
                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-500">Vista previa</span>
            </div>
            <p class="truncate text-sm font-medium text-gray-700">
                <span v-if="allClosed" class="italic text-gray-400">Sin horarios — la sucursal aparecerá como siempre cerrada</span>
                <span v-else>{{ summary }}</span>
            </p>
        </div>

        <!-- Banner si hay error -->
        <div v-if="anyInvalid" class="flex items-start gap-2 rounded-xl bg-red-50 px-4 py-3 ring-1 ring-red-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <p class="text-xs text-red-700">Hay días con horario inválido (cierre antes de apertura). Corrígelos antes de guardar.</p>
        </div>

        <!-- Banner contextual: impacto en menú online -->
        <div v-if="showOnlineImpact" class="flex items-start gap-2 rounded-xl bg-blue-50 px-4 py-3 ring-1 ring-blue-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
            <p class="text-xs leading-relaxed text-blue-800">
                Estos horarios controlan cuándo los clientes pueden hacer pedidos online. Fuera de horario verán "Cerrado" y no podrán enviar pedidos.
            </p>
        </div>
    </div>
</template>
