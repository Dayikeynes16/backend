<script setup>
// TimePicker — selector de hora reutilizable, sin depender del control nativo.
//
// Uso:
//   <TimePicker v-model="form.open" placeholder="Hora de apertura" />
//
// Modelo: string en formato "HH:mm" (24h, igual que <input type="time">).
// Display: el botón muestra "9:00 AM" en formato 12h amigable.
// Panel popover (teleportado a body, position fixed): grilla de horas (1-12),
// minutos (cada `step`), y selector AM/PM. Atajos para horarios comunes.
//
// Pensado para reutilizarse en horarios de sucursal, menú online, promociones,
// o cualquier configuración de disponibilidad.

import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },        // "HH:mm" 24h o vacío
    placeholder: { type: String, default: '--:--' },
    error: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    step: { type: Number, default: 5 },               // intervalo de minutos
    id: { type: String, default: null },
    ariaLabel: { type: String, default: 'Seleccionar hora' },
});

const emit = defineEmits(['update:modelValue']);

// ─── Parseo de modelValue ──────────────────────────────────────────
const parsed = computed(() => {
    const v = props.modelValue;
    if (!v || !/^\d{1,2}:\d{2}$/.test(v)) return { hour24: null, minute: null };
    const [h, m] = v.split(':').map(Number);
    if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
        return { hour24: null, minute: null };
    }
    return { hour24: h, minute: m };
});

const display = computed(() => {
    if (parsed.value.hour24 === null) return null;
    const h24 = parsed.value.hour24;
    const m = parsed.value.minute;
    const period = h24 < 12 ? 'AM' : 'PM';
    let h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    return {
        hour12: h12,
        minute: m,
        period,
        text: `${h12}:${String(m).padStart(2, '0')} ${period}`,
    };
});

// ─── Estado del popover ────────────────────────────────────────────
const open = ref(false);
const triggerRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});

// Draft local mientras el panel está abierto.
const draftHour12 = ref(null);
const draftMinute = ref(null);
const draftPeriod = ref('AM');

const draftText = computed(() => {
    if (draftHour12.value === null || draftMinute.value === null) return '--:-- --';
    return `${draftHour12.value}:${String(draftMinute.value).padStart(2, '0')} ${draftPeriod.value}`;
});

const initDraft = () => {
    if (display.value) {
        draftHour12.value = display.value.hour12;
        draftMinute.value = display.value.minute;
        draftPeriod.value = display.value.period;
    } else {
        draftHour12.value = 9;
        draftMinute.value = 0;
        draftPeriod.value = 'AM';
    }
};

// ─── Posicionamiento del panel ─────────────────────────────────────
const PANEL_HEIGHT = 380;
const PANEL_MIN_WIDTH = 304;

const positionPanel = () => {
    const trigger = triggerRef.value;
    if (!trigger) return;
    const rect = trigger.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const spaceBelow = vh - rect.bottom;
    const spaceAbove = rect.top;
    const openUp = spaceBelow < PANEL_HEIGHT + 16 && spaceAbove > spaceBelow;

    const width = Math.max(rect.width, PANEL_MIN_WIDTH);
    let left = rect.left;
    if (left + width > vw - 8) left = vw - width - 8;
    if (left < 8) left = 8;

    panelStyle.value = {
        position: 'fixed',
        left: `${left}px`,
        width: `${width}px`,
        ...(openUp
            ? { bottom: `${vh - rect.top + 8}px` }
            : { top: `${rect.bottom + 8}px` }),
        zIndex: 60,
    };
};

const openPanel = async () => {
    if (props.disabled) return;
    initDraft();
    open.value = true;
    await nextTick();
    positionPanel();
};

const closePanel = () => {
    open.value = false;
};

// ─── Picks ─────────────────────────────────────────────────────────
const pickHour = (h) => { draftHour12.value = h; };
const pickMinute = (m) => { draftMinute.value = m; };
const pickPeriod = (p) => { draftPeriod.value = p; };

const commit = () => {
    if (draftHour12.value === null || draftMinute.value === null) return;
    let h24 = draftHour12.value % 12;
    if (draftPeriod.value === 'PM') h24 += 12;
    const value = `${String(h24).padStart(2, '0')}:${String(draftMinute.value).padStart(2, '0')}`;
    emit('update:modelValue', value);
    closePanel();
};

const clear = () => {
    emit('update:modelValue', '');
    closePanel();
};

// Atajos comunes (24h interno → label 12h amigable).
const presets = [
    { label: '7:00 AM', value: '07:00' },
    { label: '9:00 AM', value: '09:00' },
    { label: '12:00 PM', value: '12:00' },
    { label: '3:00 PM', value: '15:00' },
    { label: '6:00 PM', value: '18:00' },
    { label: '8:00 PM', value: '20:00' },
];

const applyPreset = (val) => {
    emit('update:modelValue', val);
    closePanel();
};

// ─── Opciones de minutos según step ────────────────────────────────
const minuteOptions = computed(() => {
    const out = [];
    for (let m = 0; m < 60; m += props.step) out.push(m);
    return out;
});

// ─── Cierre por click fuera / ESC ──────────────────────────────────
const onClickOutside = (e) => {
    if (!open.value) return;
    if (triggerRef.value?.contains(e.target)) return;
    if (panelRef.value?.contains(e.target)) return;
    closePanel();
};

const onKeydown = (e) => {
    if (open.value && e.key === 'Escape') closePanel();
};

const onScrollOrResize = () => {
    if (open.value) positionPanel();
};

onMounted(() => {
    document.addEventListener('mousedown', onClickOutside);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('scroll', onScrollOrResize, { passive: true, capture: true });
    window.addEventListener('resize', onScrollOrResize);
});

onBeforeUnmount(() => {
    // Cerrar el panel antes del unmount para evitar que Vue intente
    // patchear el contenido teleportado mientras el componente se destruye.
    open.value = false;
    document.removeEventListener('mousedown', onClickOutside);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', onScrollOrResize, { capture: true });
    window.removeEventListener('resize', onScrollOrResize);
});

// Si el modelValue cambia desde fuera con el panel abierto, sincroniza el draft.
watch(() => props.modelValue, () => {
    if (open.value) initDraft();
});
</script>

<template>
    <div class="relative">
        <!-- Trigger -->
        <button ref="triggerRef" type="button" :id="id" :disabled="disabled" :aria-label="ariaLabel"
            :aria-expanded="open" aria-haspopup="dialog"
            @click="openPanel"
            :class="['flex w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-left transition focus:outline-none focus:ring-2',
                error ? 'border-red-300 focus:border-red-400 focus:ring-red-200'
                : open ? 'border-emerald-400 ring-2 ring-emerald-200'
                : 'border-gray-200 hover:border-gray-300 focus:border-emerald-400 focus:ring-emerald-200',
                disabled ? 'cursor-not-allowed opacity-50' : '']">
            <span :class="['font-mono text-sm tabular-nums', display ? 'font-semibold text-gray-900' : 'text-gray-400']">
                {{ display ? display.text : placeholder }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
        </button>

        <!-- Popover panel teleportado al body para evitar overflow/clipping.
             Sin <Transition>: el leave-animation durante un unmount de la
             página causa crashes en Vue. Aceptamos abrir/cerrar instantáneo. -->
        <Teleport to="body">
            <div v-if="open" ref="panelRef" :style="panelStyle" role="dialog" aria-modal="false"
                class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl ring-1 ring-black/5">
                    <!-- Header con preview en vivo -->
                    <div class="flex items-center justify-between border-b border-gray-100 bg-gradient-to-br from-emerald-50 to-white px-4 py-3">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-emerald-700/70">Hora seleccionada</p>
                            <p class="mt-0.5 font-mono text-xl font-bold tabular-nums text-emerald-900">{{ draftText }}</p>
                        </div>
                        <button type="button" @click="closePanel"
                            class="rounded-md p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                            aria-label="Cerrar">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- 3 columnas: Hora | Minutos | AM/PM -->
                    <div class="grid grid-cols-[1fr_1fr_auto] divide-x divide-gray-100">
                        <!-- Horas 1-12 -->
                        <div class="p-2.5">
                            <p class="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Hora</p>
                            <div class="grid grid-cols-3 gap-1">
                                <button v-for="h in 12" :key="`h${h}`" type="button" @click="pickHour(h)"
                                    :class="['rounded-md py-2 text-sm font-semibold tabular-nums transition active:scale-95',
                                        draftHour12 === h
                                            ? 'bg-emerald-600 text-white shadow-sm'
                                            : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-700']">
                                    {{ h }}
                                </button>
                            </div>
                        </div>

                        <!-- Minutos -->
                        <div class="max-h-60 overflow-y-auto p-2.5">
                            <p class="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Min</p>
                            <div class="grid grid-cols-3 gap-1">
                                <button v-for="m in minuteOptions" :key="`m${m}`" type="button" @click="pickMinute(m)"
                                    :class="['rounded-md py-2 text-sm font-semibold tabular-nums transition active:scale-95',
                                        draftMinute === m
                                            ? 'bg-emerald-600 text-white shadow-sm'
                                            : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-700']">
                                    {{ String(m).padStart(2, '0') }}
                                </button>
                            </div>
                        </div>

                        <!-- AM/PM segmented -->
                        <div class="flex flex-col gap-1.5 p-2.5">
                            <p class="px-1 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Periodo</p>
                            <button type="button" @click="pickPeriod('AM')"
                                :class="['rounded-md px-4 py-3 text-sm font-bold transition active:scale-95',
                                    draftPeriod === 'AM'
                                        ? 'bg-emerald-600 text-white shadow-sm'
                                        : 'bg-gray-50 text-gray-700 hover:bg-emerald-50 hover:text-emerald-700']">
                                AM
                            </button>
                            <button type="button" @click="pickPeriod('PM')"
                                :class="['rounded-md px-4 py-3 text-sm font-bold transition active:scale-95',
                                    draftPeriod === 'PM'
                                        ? 'bg-emerald-600 text-white shadow-sm'
                                        : 'bg-gray-50 text-gray-700 hover:bg-emerald-50 hover:text-emerald-700']">
                                PM
                            </button>
                        </div>
                    </div>

                    <!-- Atajos -->
                    <div class="border-t border-gray-100 bg-gray-50/60 px-3 py-2">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class="mr-1 text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Rápido</span>
                            <button v-for="p in presets" :key="p.value" type="button" @click="applyPreset(p.value)"
                                class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-gray-200 transition hover:bg-emerald-50 hover:text-emerald-700 active:scale-95">
                                {{ p.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Footer con acciones -->
                    <div class="flex items-center justify-between gap-2 border-t border-gray-100 bg-white px-3 py-2.5">
                        <button type="button" @click="clear"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                            Limpiar
                        </button>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="closePanel"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:bg-gray-100">
                                Cancelar
                            </button>
                            <button type="button" @click="commit"
                                class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95">
                                Aceptar
                            </button>
                        </div>
                    </div>
            </div>
        </Teleport>
    </div>
</template>
