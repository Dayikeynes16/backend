<script setup>
/**
 * DateField — selector de fecha tipo iOS, popover-based.
 *
 * Modos:
 *   - mode="single": v-model recibe/emite un string 'YYYY-MM-DD'.
 *   - mode="range":  v-model recibe/emite { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }.
 *
 * Sin dependencias externas (sólo Vue + utils/date).
 *
 * Props clave:
 *   - mode               'single' | 'range'  (default 'single')
 *   - modelValue         string | { from, to } | null
 *   - max                string 'YYYY-MM-DD'  (opcional, fecha máxima permitida)
 *   - min                string 'YYYY-MM-DD'  (opcional)
 *   - placeholder        texto cuando no hay valor
 *   - align              'left' | 'right'    (alineación del popover, default 'left')
 *   - presets            string[]  para modo range. Default todos los comunes.
 *   - hideTriggerIcon    bool      oculta el icono del calendario en el trigger
 *   - size               'sm' | 'md'
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { localToday, toLocalDate } from '@/utils/date';

const props = defineProps({
    mode: { type: String, default: 'single' },
    modelValue: { type: [String, Object, null], default: null },
    max: { type: String, default: '' },
    min: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    align: { type: String, default: 'left' },
    presets: {
        type: Array,
        default: () => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_year'],
    },
    hideTriggerIcon: { type: Boolean, default: false },
    size: { type: String, default: 'md' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const today = localToday();

// --- Internal state ---
const open = ref(false);
const containerRef = ref(null);
const triggerRef = ref(null);
const popoverRef = ref(null);
const popoverStyle = ref({});
const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024);
const isMobile = computed(() => windowWidth.value < 640);

// Dimensiones aproximadas usadas para placement smart.
const POPOVER_W = 320;
const POPOVER_H_ESTIMATE = 440;
const VIEWPORT_PAD = 8;
const TRIGGER_GAP = 6;

// Range building state — primer click sets from, segundo click sets to.
const rangeFrom = ref(null);
const rangeHover = ref(null);

// Mes que muestra el calendario
const monthDate = ref(parseLocal(props.mode === 'range' ? props.modelValue?.from : props.modelValue) || new Date());

// --- Helpers ---
function parseLocal(yyyymmdd) {
    if (!yyyymmdd || typeof yyyymmdd !== 'string') return null;
    // 'YYYY-MM-DD' como local, no UTC
    const [y, m, d] = yyyymmdd.split('-').map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
}

function isOutOfBounds(yyyymmdd) {
    if (props.max && yyyymmdd > props.max) return true;
    if (props.min && yyyymmdd < props.min) return true;
    return false;
}

// --- Trigger label ---
const isRange = computed(() => props.mode === 'range');

const triggerLabel = computed(() => {
    if (isRange.value) {
        const v = props.modelValue || {};
        if (!v.from && !v.to) return props.placeholder || 'Selecciona rango';
        if (v.from === v.to) return formatLong(parseLocal(v.from));
        return `${formatShort(parseLocal(v.from))} → ${formatShort(parseLocal(v.to))}`;
    }
    if (!props.modelValue) return props.placeholder || 'Selecciona fecha';
    const d = parseLocal(props.modelValue);
    if (!d) return props.placeholder || 'Selecciona fecha';
    if (props.modelValue === today) return `Hoy, ${formatShort(d)}`;
    if (props.modelValue === yesterdayISO()) return `Ayer, ${formatShort(d)}`;
    return formatLong(d);
});

function formatShort(d) {
    if (!(d instanceof Date) || isNaN(d)) return '';
    return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatLong(d) {
    if (!(d instanceof Date) || isNaN(d)) return '';
    const dayName = d.toLocaleDateString('es-MX', { weekday: 'short' });
    return `${dayName.charAt(0).toUpperCase() + dayName.slice(1)} ${formatShort(d)}`;
}

function yesterdayISO() {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return toLocalDate(d);
}

// --- Calendar grid ---
// 6 filas × 7 columnas, con días del mes + días vecinos para llenar la rejilla.
const monthLabel = computed(() => {
    const m = monthDate.value;
    const text = m.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
    return text.charAt(0).toUpperCase() + text.slice(1);
});

const weekDays = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

const calendarCells = computed(() => {
    const m = monthDate.value;
    const year = m.getFullYear();
    const month = m.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    // Convertir Sunday=0 a Monday=0
    const firstWeekday = (firstOfMonth.getDay() + 6) % 7;
    const start = new Date(year, month, 1 - firstWeekday);

    const cells = [];
    for (let i = 0; i < 42; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        cells.push({
            date: d,
            iso: toLocalDate(d),
            inMonth: d.getMonth() === month,
        });
    }
    return cells;
});

function prevMonth() {
    const m = new Date(monthDate.value);
    m.setMonth(m.getMonth() - 1);
    monthDate.value = m;
}

function nextMonth() {
    const m = new Date(monthDate.value);
    m.setMonth(m.getMonth() + 1);
    monthDate.value = m;
}

// --- Day cell state ---
function isToday(iso) {
    return iso === today;
}

function isSelected(iso) {
    if (isRange.value) {
        const v = props.modelValue || {};
        if (rangeFrom.value && !v.to) return iso === rangeFrom.value || iso === v.from;
        if (v.from && v.to) return iso >= v.from && iso <= v.to;
        return iso === v.from;
    }
    return iso === props.modelValue;
}

function isRangeStart(iso) {
    if (!isRange.value) return false;
    const v = props.modelValue || {};
    if (rangeFrom.value && !v.to) return iso === rangeFrom.value;
    return iso === v.from;
}

function isRangeEnd(iso) {
    if (!isRange.value) return false;
    const v = props.modelValue || {};
    return v.from !== v.to && iso === v.to;
}

function isInHoverPreview(iso) {
    if (!isRange.value || !rangeFrom.value || !rangeHover.value) return false;
    const lo = rangeFrom.value < rangeHover.value ? rangeFrom.value : rangeHover.value;
    const hi = rangeFrom.value < rangeHover.value ? rangeHover.value : rangeFrom.value;
    return iso > lo && iso < hi;
}

// --- Click on day ---
function onDayClick(iso) {
    if (isOutOfBounds(iso)) return;

    if (!isRange.value) {
        emit('update:modelValue', iso);
        emit('change', iso);
        open.value = false;
        return;
    }

    // range mode
    if (!rangeFrom.value) {
        rangeFrom.value = iso;
        // Reset partial selection in modelValue
        emit('update:modelValue', { from: iso, to: iso });
        return;
    }

    const from = rangeFrom.value;
    const to = iso;
    const finalFrom = from <= to ? from : to;
    const finalTo = from <= to ? to : from;
    const value = { from: finalFrom, to: finalTo };
    emit('update:modelValue', value);
    emit('change', value);
    rangeFrom.value = null;
    rangeHover.value = null;
    open.value = false;
}

function onDayHover(iso) {
    if (!isRange.value || !rangeFrom.value) return;
    rangeHover.value = iso;
}

// --- Presets (range mode) ---
const presetOptions = computed(() => {
    const all = {
        today: { label: 'Hoy', range: () => ({ from: today, to: today }) },
        yesterday: { label: 'Ayer', range: () => {
            const y = yesterdayISO();
            return { from: y, to: y };
        } },
        last_7_days: { label: 'Últimos 7 días', range: () => {
            const d = new Date();
            d.setDate(d.getDate() - 6);
            return { from: toLocalDate(d), to: today };
        } },
        this_month: { label: 'Este mes', range: () => {
            const now = new Date();
            const first = new Date(now.getFullYear(), now.getMonth(), 1);
            return { from: toLocalDate(first), to: today };
        } },
        last_month: { label: 'Mes pasado', range: () => {
            const now = new Date();
            const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const last = new Date(now.getFullYear(), now.getMonth(), 0);
            return { from: toLocalDate(first), to: toLocalDate(last) };
        } },
        this_year: { label: 'Este año', range: () => {
            const now = new Date();
            const first = new Date(now.getFullYear(), 0, 1);
            return { from: toLocalDate(first), to: today };
        } },
    };
    return props.presets.filter(p => all[p]).map(p => ({ key: p, ...all[p] }));
});

const singlePresetOptions = computed(() => ([
    { key: 'today', label: 'Hoy', iso: today },
    { key: 'yesterday', label: 'Ayer', iso: yesterdayISO() },
]));

function applyPreset(preset) {
    const v = preset.range();
    rangeFrom.value = null;
    rangeHover.value = null;
    emit('update:modelValue', v);
    emit('change', v);
    open.value = false;
}

function applySinglePreset(p) {
    if (isOutOfBounds(p.iso)) return;
    emit('update:modelValue', p.iso);
    emit('change', p.iso);
    open.value = false;
}

// --- Active preset detection (for range mode header pill) ---
const activePresetKey = computed(() => {
    if (!isRange.value || !props.modelValue) return null;
    const v = props.modelValue;
    for (const p of presetOptions.value) {
        const r = p.range();
        if (r.from === v.from && r.to === v.to) return p.key;
    }
    return null;
});

// --- Toggle ---
function toggleOpen() {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        rangeFrom.value = null;
        rangeHover.value = null;
        const ref = isRange.value ? props.modelValue?.from : props.modelValue;
        const d = parseLocal(ref);
        if (d) monthDate.value = d;
        // Posicionar tras render para tener triggerRef montado en DOM.
        nextTickPosition();
    }
}

function close() {
    open.value = false;
    rangeFrom.value = null;
    rangeHover.value = null;
}

// En mobile el wrapper externo es el backdrop. En desktop el wrapper no
// recibe clicks (tamaño cero), así que esta función solo aplica a mobile.
function onBackdropClick() {
    if (isMobile.value) close();
}

/**
 * Calcula la posición del popover usando getBoundingClientRect del trigger.
 * En mobile se renderiza como bottom-sheet (clases vía isMobile, sin estilo
 * inline). En desktop se posiciona fixed, con fallback a "abrir hacia arriba"
 * si no cabe abajo y "alinear izquierda" si no cabe a la derecha.
 */
function updatePopoverPosition() {
    if (!triggerRef.value || isMobile.value) {
        popoverStyle.value = {};
        return;
    }
    const rect = triggerRef.value.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    const spaceBelow = vh - rect.bottom - VIEWPORT_PAD;
    const spaceAbove = rect.top - VIEWPORT_PAD;
    const placeAbove = spaceBelow < POPOVER_H_ESTIMATE && spaceAbove >= POPOVER_H_ESTIMATE;

    const top = placeAbove
        ? Math.max(VIEWPORT_PAD, rect.top - POPOVER_H_ESTIMATE - TRIGGER_GAP)
        : Math.min(rect.bottom + TRIGGER_GAP, vh - VIEWPORT_PAD - POPOVER_H_ESTIMATE);

    let left = props.align === 'right' ? rect.right - POPOVER_W : rect.left;
    if (left + POPOVER_W > vw - VIEWPORT_PAD) left = vw - POPOVER_W - VIEWPORT_PAD;
    if (left < VIEWPORT_PAD) left = VIEWPORT_PAD;

    popoverStyle.value = {
        position: 'fixed',
        top: `${top}px`,
        left: `${left}px`,
        width: `${POPOVER_W}px`,
    };
}

function nextTickPosition() {
    requestAnimationFrame(() => updatePopoverPosition());
}

// --- Listeners globales ---
function handleClickOutside(e) {
    if (!open.value) return;
    const inTrigger = triggerRef.value && triggerRef.value.contains(e.target);
    const inPopover = popoverRef.value && popoverRef.value.contains(e.target);
    if (!inTrigger && !inPopover) close();
}

function handleKeydown(e) {
    if (!open.value) return;
    if (e.key === 'Escape') close();
}

function handleResize() {
    windowWidth.value = window.innerWidth;
    if (open.value) updatePopoverPosition();
}

/**
 * Patrón iOS: cerrar al hacer scroll fuera del popover. El form modal de
 * gastos tiene overflow-y-auto interno, lo cual movería el trigger sin
 * mover el popover (que está teleported a body) → mejor cerrarlo.
 */
function handleScroll(e) {
    if (!open.value) return;
    if (popoverRef.value && popoverRef.value.contains(e.target)) return;
    close();
}

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside, true);
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('resize', handleResize);
    document.addEventListener('scroll', handleScroll, true);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleClickOutside, true);
    document.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('resize', handleResize);
    document.removeEventListener('scroll', handleScroll, true);
});

// Re-sync month when v-model changes externally
watch(() => isRange.value ? props.modelValue?.from : props.modelValue, (val) => {
    if (val) {
        const d = parseLocal(val);
        if (d) monthDate.value = d;
    }
});

const triggerSizeClass = computed(() => props.size === 'sm'
    ? 'h-9 px-3 text-xs'
    : 'h-10 px-3.5 text-sm');
</script>

<template>
    <div ref="containerRef" class="relative inline-block">
        <button type="button" ref="triggerRef" :disabled="disabled" @click="toggleOpen"
            :class="['inline-flex items-center gap-2 rounded-xl bg-white font-medium text-gray-800 ring-1 ring-gray-200 shadow-sm transition',
                'hover:ring-gray-300 hover:bg-gray-50',
                'focus:outline-none focus:ring-2 focus:ring-red-300 focus:border-transparent',
                disabled ? 'opacity-50 cursor-not-allowed' : '',
                open ? 'ring-2 ring-red-300 bg-white' : '',
                triggerSizeClass]">
            <svg v-if="!hideTriggerIcon" class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
            <span class="truncate">{{ triggerLabel }}</span>
            <svg class="h-3.5 w-3.5 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <!-- Popover teleported al body para escapar overflow/clipping del padre. -->
        <Teleport to="body">
            <Transition
                :enter-active-class="isMobile ? 'transition duration-200 ease-out' : 'transition duration-150 ease-out'"
                :leave-active-class="isMobile ? 'transition duration-150 ease-in' : 'transition duration-100 ease-in'"
                :enter-from-class="isMobile ? 'opacity-0 translate-y-4' : 'opacity-0 -translate-y-1'"
                :leave-to-class="isMobile ? 'opacity-0 translate-y-4' : 'opacity-0 -translate-y-1'">
                <div v-if="open"
                    :class="isMobile
                        ? 'fixed inset-0 z-[60] flex items-end justify-center bg-black/40 backdrop-blur-sm'
                        : ''"
                    @click.self="onBackdropClick">
                    <div ref="popoverRef"
                        :style="isMobile ? null : popoverStyle"
                        :class="[
                            'bg-white shadow-2xl ring-1 ring-gray-200/80',
                            isMobile
                                ? 'w-full max-h-[90vh] overflow-y-auto rounded-t-3xl pb-2'
                                : 'z-[60] rounded-2xl',
                        ]">
                        <!-- Drag handle solo en mobile -->
                        <div v-if="isMobile" class="mx-auto mt-2 mb-1 h-1 w-10 rounded-full bg-gray-300" aria-hidden="true"></div>

                        <!-- Presets (range only) -->
                        <div v-if="isRange && presetOptions.length"
                            :class="[
                                'flex flex-wrap gap-1.5 border-b border-gray-100 bg-gray-50/60 p-3',
                                isMobile ? '' : 'rounded-t-2xl',
                            ]">
                            <button v-for="p in presetOptions" :key="p.key" type="button" @click="applyPreset(p)"
                                :class="['rounded-lg px-2.5 py-1.5 text-xs font-semibold transition active:scale-[0.97]',
                                    activePresetKey === p.key
                                        ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200/80'
                                        : 'text-gray-500 hover:text-gray-800 hover:bg-white']">
                                {{ p.label }}
                            </button>
                        </div>

                        <!-- Single mode quick presets -->
                        <div v-else-if="!isRange"
                            :class="[
                                'flex gap-1.5 border-b border-gray-100 bg-gray-50/60 p-3',
                                isMobile ? '' : 'rounded-t-2xl',
                            ]">
                            <button v-for="p in singlePresetOptions" :key="p.key" type="button" @click="applySinglePreset(p)"
                                :disabled="isOutOfBounds(p.iso)"
                                :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition active:scale-[0.97]',
                                    modelValue === p.iso
                                        ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200/80'
                                        : 'text-gray-500 hover:text-gray-800 hover:bg-white',
                                    isOutOfBounds(p.iso) ? 'opacity-40 cursor-not-allowed' : '']">
                                {{ p.label }}
                            </button>
                        </div>

                        <!-- Month nav -->
                        <div class="flex items-center justify-between px-4 pt-3 pb-2">
                            <button type="button" @click="prevMonth" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-800" aria-label="Mes anterior">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                            </button>
                            <span class="text-sm font-bold text-gray-900">{{ monthLabel }}</span>
                            <button type="button" @click="nextMonth" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-800" aria-label="Mes siguiente">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        </div>

                        <!-- Weekday headers -->
                        <div class="grid grid-cols-7 px-3 pb-1 text-center text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            <span v-for="w in weekDays" :key="w">{{ w }}</span>
                        </div>

                        <!-- Day grid -->
                        <div class="grid grid-cols-7 gap-y-0.5 px-3 pb-3">
                            <button v-for="cell in calendarCells" :key="cell.iso" type="button"
                                @click="onDayClick(cell.iso)"
                                @mouseenter="onDayHover(cell.iso)"
                                :disabled="isOutOfBounds(cell.iso)"
                                :class="[
                                    'relative h-9 text-sm font-medium transition select-none',
                                    cell.inMonth ? 'text-gray-700' : 'text-gray-300',
                                    isOutOfBounds(cell.iso) ? 'opacity-30 cursor-not-allowed' : 'hover:bg-gray-100',
                                    isSelected(cell.iso) && !isRange ? 'bg-red-600 text-white rounded-full' : '',
                                    isRange && isSelected(cell.iso) && !isRangeStart(cell.iso) && !isRangeEnd(cell.iso) ? 'bg-red-50 text-red-700' : '',
                                    isRangeStart(cell.iso) ? 'bg-red-600 text-white rounded-l-full' : '',
                                    isRangeEnd(cell.iso) ? 'bg-red-600 text-white rounded-r-full' : '',
                                    isInHoverPreview(cell.iso) ? 'bg-red-50 text-red-700' : '',
                                    isToday(cell.iso) && !isSelected(cell.iso) ? 'ring-1 ring-red-300 rounded-full' : '',
                                ]">
                                {{ cell.date.getDate() }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
