<script setup>
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch, watchEffect } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
});
const emit = defineEmits(['close', 'merged']);

// ── Estado ────────────────────────────────────────────────────────────────
const q = ref('');
const candidates = ref([]);
const searching = ref(false);
const searchFailed = ref(false);
// Mapa id → {id, name, unit}: conserva la ficha aunque deje de coincidir
// con la búsqueda actual (la selección sobrevive a cambios de `q`).
const selected = ref(new Map());
const canonicalId = ref(null);
const preview = ref(null);
const previewLoading = ref(false);
const confirming = ref(false);
const submitting = ref(false);
const submitError = ref('');

const searchInput = ref(null);
const selectAllInput = ref(null);
const cancelConfirmBtn = ref(null);

const fmt = new Intl.NumberFormat('es-MX');
const n = (v) => fmt.format(v ?? 0);

// ── Búsqueda (debounce 250 ms + descarte de respuestas obsoletas) ─────────
let searchTimer;
let searchSeq = 0;
watch(q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(search, 250);
});

async function search() {
    const seq = ++searchSeq;
    searching.value = true;
    searchFailed.value = false;
    try {
        const { data } = await window.axios.get(
            route('empresa.productos-compra.fusionar.candidatos', { tenant: props.tenantSlug, q: q.value }),
        );
        if (seq !== searchSeq) return;
        candidates.value = data.data;
    } catch {
        if (seq === searchSeq) {
            candidates.value = [];
            searchFailed.value = true;
        }
    } finally {
        if (seq === searchSeq) searching.value = false;
    }
}

function clearSearch() {
    q.value = '';
    searchInput.value?.focus();
}

// ── Selección ─────────────────────────────────────────────────────────────
const isSelected = (id) => selected.value.has(id);

function toggle(candidate) {
    const map = new Map(selected.value);
    map.has(candidate.id) ? map.delete(candidate.id) : map.set(candidate.id, candidate);
    selected.value = map;
    afterSelectionChange();
}

const allSelected = computed(
    () => candidates.value.length > 0 && candidates.value.every((c) => selected.value.has(c.id)),
);
const someSelected = computed(() => candidates.value.some((c) => selected.value.has(c.id)));

function toggleAll() {
    const map = new Map(selected.value);
    if (allSelected.value) {
        candidates.value.forEach((c) => map.delete(c.id));
    } else {
        candidates.value.forEach((c) => map.set(c.id, c));
    }
    selected.value = map;
    afterSelectionChange();
}

// Estado indeterminado nativo del checkbox "Seleccionar todas".
watchEffect(() => {
    if (selectAllInput.value) {
        selectAllInput.value.indeterminate = someSelected.value && !allSelected.value;
    }
});

function afterSelectionChange() {
    if (!selected.value.has(canonicalId.value)) canonicalId.value = null;
    if (canonicalId.value === null && selected.value.size) pickDefaultCanonical();
    if (!selected.value.size) canonicalId.value = null;
    schedulePreview();
}

// ── Canónica (por defecto: nombre más corto entre las seleccionadas) ──────
const selectedList = computed(() =>
    [...selected.value.values()].sort(
        (a, b) => a.name.length - b.name.length || a.name.localeCompare(b.name),
    ),
);

function pickDefaultCanonical() {
    canonicalId.value = selectedList.value[0]?.id ?? null;
}

function setCanonical(id) {
    if (canonicalId.value === id) return;
    canonicalId.value = id;
    schedulePreview();
}

const canonical = computed(() => selected.value.get(canonicalId.value) ?? null);
const absorbedIds = computed(() => [...selected.value.keys()].filter((id) => id !== canonicalId.value));
const selectedUnits = computed(() => [...new Set(selectedList.value.map((c) => c.unit))]);

// ── Preview (debounce corto + descarte de respuestas obsoletas) ───────────
let previewTimer;
let previewSeq = 0;

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(refreshPreview, 200);
}

async function refreshPreview() {
    if (!canonicalId.value || absorbedIds.value.length === 0) {
        preview.value = null;
        previewLoading.value = false;
        return;
    }
    const seq = ++previewSeq;
    previewLoading.value = true;
    try {
        const { data } = await window.axios.post(
            route('empresa.productos-compra.fusionar.preview', props.tenantSlug),
            { canonical_id: canonicalId.value, absorbed_ids: absorbedIds.value },
        );
        if (seq === previewSeq) preview.value = data;
    } catch {
        if (seq === previewSeq) preview.value = null;
    } finally {
        if (seq === previewSeq) previewLoading.value = false;
    }
}

const ready = computed(
    () => !!canonicalId.value && absorbedIds.value.length > 0 && !!preview.value && !previewLoading.value,
);

// ── Confirmación + ejecución ──────────────────────────────────────────────
function askConfirm() {
    if (!ready.value) return;
    submitError.value = '';
    confirming.value = true;
    nextTick(() => cancelConfirmBtn.value?.focus());
}

function submit() {
    if (submitting.value) return;
    submitting.value = true;
    submitError.value = '';
    router.post(
        route('empresa.productos-compra.fusionar', props.tenantSlug),
        { canonical_id: canonicalId.value, absorbed_ids: absorbedIds.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('merged');
                emit('close');
            },
            onError: () => {
                submitError.value = 'No se pudo completar la fusión. Revisa la selección e inténtalo de nuevo.';
            },
            onFinish: () => {
                submitting.value = false;
                confirming.value = false;
            },
        },
    );
}

// ── Ciclo de vida del modal ───────────────────────────────────────────────
function reset() {
    q.value = '';
    candidates.value = [];
    selected.value = new Map();
    canonicalId.value = null;
    preview.value = null;
    previewLoading.value = false;
    confirming.value = false;
    submitError.value = '';
    searchFailed.value = false;
}

function tryClose() {
    if (submitting.value) return;
    if (confirming.value) {
        confirming.value = false;
        return;
    }
    emit('close');
}

function onKeydown(e) {
    if (e.key === 'Escape') {
        e.stopPropagation();
        tryClose();
    }
}

let previousOverflow = '';
watch(
    () => props.open,
    (open, wasOpen) => {
        if (open) {
            reset();
            search();
            window.addEventListener('keydown', onKeydown);
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            nextTick(() => searchInput.value?.focus());
        } else if (wasOpen) {
            window.removeEventListener('keydown', onKeydown);
            document.body.style.overflow = previousOverflow;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearTimeout(previewTimer);
    window.removeEventListener('keydown', onKeydown);
    if (props.open) document.body.style.overflow = previousOverflow;
});

// ── Textos ────────────────────────────────────────────────────────────────
const impactLabel = computed(() =>
    preview.value?.items_count === 1
        ? 'línea de compra se reapuntará'
        : 'líneas de compra se reapuntarán',
);
const fichas = (count) => (count === 1 ? 'ficha' : 'fichas');
</script>

<template>
    <Teleport to="body">
        <Transition name="fmodal">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
                @click.self="tryClose"
            >
                <div
                    class="fmodal-panel relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl shadow-gray-950/25 motion-reduce:transition-none"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="fusion-modal-title"
                >
                    <!-- Cabecera -->
                    <header class="flex items-start justify-between gap-4 px-6 pb-4 pt-6">
                        <div>
                            <h2 id="fusion-modal-title" class="text-lg font-bold tracking-tight text-gray-900">
                                Fusionar duplicados
                            </h2>
                            <p class="mt-1 text-[13px] leading-relaxed text-gray-500">
                                Elige las fichas repetidas y conserva una sola. Su historial de compras se reapunta automáticamente.
                            </p>
                        </div>
                        <button
                            type="button"
                            aria-label="Cerrar"
                            class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-100 text-gray-500 transition duration-150 hover:bg-gray-200 hover:text-gray-700 active:scale-95 motion-reduce:transition-none"
                            @click="tryClose"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                        </button>
                    </header>

                    <!-- Buscador -->
                    <div class="px-6 pb-4">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
                            <input
                                ref="searchInput"
                                v-model="q"
                                type="text"
                                placeholder="Buscar duplicados…"
                                class="w-full rounded-xl border-transparent bg-gray-100 py-2.5 pl-10 pr-10 text-sm text-gray-900 placeholder:text-gray-400 transition duration-150 focus:border-orange-500 focus:bg-white focus:ring-orange-500 motion-reduce:transition-none"
                            />
                            <span v-if="searching" class="absolute right-3.5 top-1/2 -translate-y-1/2">
                                <svg class="h-4 w-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z" /></svg>
                            </span>
                            <button
                                v-else-if="q"
                                type="button"
                                aria-label="Limpiar búsqueda"
                                class="absolute right-3.5 top-1/2 grid h-4 w-4 -translate-y-1/2 place-items-center rounded-full bg-gray-300 text-white transition duration-150 hover:bg-gray-400 motion-reduce:transition-none"
                                @click="clearSearch"
                            >
                                <svg class="h-2 w-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Cuerpo scrolleable -->
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain px-6 pb-6">
                        <!-- Resultados -->
                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3 px-1">
                                <label class="flex cursor-pointer select-none items-center gap-2.5">
                                    <input
                                        ref="selectAllInput"
                                        type="checkbox"
                                        :checked="allSelected"
                                        :disabled="!candidates.length"
                                        class="h-[18px] w-[18px] rounded-[6px] border-gray-300 text-orange-600 transition duration-150 focus:ring-orange-500 disabled:opacity-40 motion-reduce:transition-none"
                                        @change="toggleAll"
                                    />
                                    <span class="text-[13px] font-medium text-gray-600">Seleccionar todas las coincidencias</span>
                                </label>
                                <Transition name="ffade">
                                    <span
                                        v-if="selected.size"
                                        class="shrink-0 rounded-full bg-orange-100 px-2.5 py-0.5 text-[11px] font-bold tabular-nums text-orange-700"
                                    >
                                        {{ selected.size }} {{ selected.size === 1 ? 'seleccionada' : 'seleccionadas' }}
                                    </span>
                                </Transition>
                            </div>

                            <div class="max-h-60 overflow-y-auto overscroll-contain rounded-2xl border border-gray-200/90">
                                <!-- Cargando (primer fetch): skeleton -->
                                <div v-if="searching && !candidates.length" class="divide-y divide-gray-100">
                                    <div v-for="i in 3" :key="i" class="flex animate-pulse items-center gap-3 px-4 py-3">
                                        <span class="h-[22px] w-[22px] rounded-full bg-gray-100"></span>
                                        <span class="h-3 rounded-full bg-gray-100" :class="i === 2 ? 'w-44' : 'w-32'"></span>
                                        <span class="ml-auto h-4 w-9 rounded-full bg-gray-100"></span>
                                    </div>
                                </div>

                                <!-- Error de búsqueda -->
                                <div v-else-if="searchFailed" class="flex flex-col items-center gap-1 px-4 py-10 text-center">
                                    <p class="text-sm font-medium text-gray-600">No se pudo buscar</p>
                                    <button type="button" class="text-xs font-semibold text-orange-600 hover:text-orange-700" @click="search">
                                        Reintentar
                                    </button>
                                </div>

                                <!-- Vacío -->
                                <div v-else-if="!candidates.length" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                                    <span class="grid h-10 w-10 place-items-center rounded-full bg-gray-100">
                                        <svg class="h-[18px] w-[18px] text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
                                    </span>
                                    <p class="text-sm font-medium text-gray-600">Sin coincidencias</p>
                                    <p class="text-xs text-gray-400">Prueba con otro término de búsqueda.</p>
                                </div>

                                <!-- Lista de candidatos -->
                                <div v-else class="divide-y divide-gray-100">
                                    <button
                                        v-for="c in candidates"
                                        :key="c.id"
                                        type="button"
                                        :aria-pressed="isSelected(c.id)"
                                        :class="[
                                            'group flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-150 active:bg-gray-100 motion-reduce:transition-none',
                                            isSelected(c.id) ? 'bg-orange-50/50' : 'hover:bg-gray-50/80',
                                        ]"
                                        @click="toggle(c)"
                                    >
                                        <span
                                            :class="[
                                                'grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full border-2 transition-colors duration-200 motion-reduce:transition-none',
                                                isSelected(c.id)
                                                    ? 'fcheck-on border-transparent bg-gradient-to-br from-orange-500 to-red-600'
                                                    : 'border-gray-300 bg-white group-hover:border-gray-400',
                                            ]"
                                        >
                                            <svg class="fcheck h-3 w-3 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 6.5 5 9l4.5-5.5" /></svg>
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ c.name }}</span>
                                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-500">{{ c.unit }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <!-- Canónica -->
                        <Transition name="fsection">
                            <section v-if="selectedList.length >= 2">
                                <p class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                                    ¿Cuál ficha se conserva?
                                </p>
                                <div class="overflow-hidden rounded-2xl border border-gray-200/90" role="radiogroup" aria-label="Ficha canónica">
                                    <div class="divide-y divide-gray-100">
                                        <button
                                            v-for="c in selectedList"
                                            :key="c.id"
                                            type="button"
                                            role="radio"
                                            :aria-checked="c.id === canonicalId"
                                            :class="[
                                                'flex w-full items-center gap-3 px-4 py-3 text-left transition-colors duration-150 active:bg-gray-100 motion-reduce:transition-none',
                                                c.id === canonicalId ? 'bg-orange-50/50' : 'hover:bg-gray-50/80',
                                            ]"
                                            @click="setCanonical(c.id)"
                                        >
                                            <span
                                                :class="[
                                                    'grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full border-2 transition-colors duration-200 motion-reduce:transition-none',
                                                    c.id === canonicalId ? 'border-orange-500' : 'border-gray-300',
                                                ]"
                                            >
                                                <span
                                                    :class="[
                                                        'h-2.5 w-2.5 rounded-full bg-gradient-to-br from-orange-500 to-red-600 transition-[transform,opacity] duration-200 ease-out motion-reduce:transition-none',
                                                        c.id === canonicalId ? 'scale-100 opacity-100' : 'scale-[0.4] opacity-0',
                                                    ]"
                                                ></span>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium" :class="c.id === canonicalId ? 'text-gray-900' : 'text-gray-600'">
                                                {{ c.name }}
                                            </span>
                                            <span
                                                v-if="c.id === canonicalId"
                                                class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700"
                                            >
                                                Se conserva
                                            </span>
                                            <span v-else class="shrink-0 text-[11px] font-medium text-gray-400">se absorbe</span>
                                        </button>
                                    </div>
                                </div>
                            </section>
                        </Transition>

                        <!-- Impacto -->
                        <Transition name="fsection">
                            <section v-if="preview || previewLoading" aria-live="polite">
                                <p class="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Impacto</p>
                                <div
                                    :class="[
                                        'rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50/80 to-red-50/40 p-5 transition-opacity duration-200 motion-reduce:transition-none',
                                        previewLoading && 'opacity-50',
                                    ]"
                                >
                                    <template v-if="preview">
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-3xl font-extrabold tabular-nums leading-none tracking-tight text-gray-900">
                                                {{ n(preview.items_count) }}
                                            </span>
                                            <span class="text-sm font-medium text-gray-500">{{ impactLabel }}</span>
                                        </div>
                                        <p class="mt-3 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-gray-600">
                                            <span class="font-semibold tabular-nums text-gray-900">{{ preview.absorbed_count }} {{ fichas(preview.absorbed_count) }}</span>
                                            <svg class="h-3.5 w-3.5 shrink-0 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h14m0 0-5-5m5 5-5 5" /></svg>
                                            <span class="min-w-0 truncate font-semibold text-gray-900">«{{ canonical?.name }}»</span>
                                        </p>
                                    </template>
                                    <div v-else class="flex items-center gap-2.5 py-1 text-sm text-gray-400">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z" /></svg>
                                        Calculando impacto…
                                    </div>

                                    <Transition name="fsection">
                                        <div
                                            v-if="preview?.unit_mismatch"
                                            class="mt-4 flex items-start gap-2.5 rounded-xl bg-amber-50 px-3.5 py-3 text-[13px] leading-snug text-amber-800"
                                        >
                                            <svg class="mt-px h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                                            <p>
                                                Las fichas mezclan unidades distintas
                                                <span class="font-semibold">({{ selectedUnits.join(' · ') }})</span>.
                                                Confirma que realmente son el mismo producto antes de fusionar.
                                            </p>
                                        </div>
                                    </Transition>
                                </div>
                            </section>
                        </Transition>
                    </div>

                    <!-- Pie -->
                    <footer class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                        <p v-if="submitError" class="min-w-0 flex-1 text-xs font-medium leading-snug text-red-600">{{ submitError }}</p>
                        <p v-else-if="ready" class="text-xs tabular-nums text-gray-500">
                            {{ selected.size }} {{ fichas(selected.size) }} · {{ n(preview.items_count) }} {{ preview.items_count === 1 ? 'línea' : 'líneas' }}
                        </p>
                        <p v-else-if="previewLoading" class="text-xs text-gray-400">Calculando impacto…</p>
                        <p v-else class="text-xs text-gray-400">Selecciona al menos 2 fichas duplicadas.</p>

                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600 transition duration-150 hover:bg-gray-100 hover:text-gray-800 active:scale-[0.98] motion-reduce:transition-none"
                                @click="tryClose"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                :disabled="!ready"
                                class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-orange-500/30 transition duration-150 hover:from-orange-600 hover:to-red-700 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-40 motion-reduce:transition-none"
                                @click="askConfirm"
                            >
                                Fusionar<span v-if="ready" class="tabular-nums"> {{ selected.size }} {{ fichas(selected.size) }}</span>
                            </button>
                        </div>
                    </footer>

                    <!-- Confirmación fuerte -->
                    <Transition name="fconfirm">
                        <div
                            v-if="confirming"
                            class="absolute inset-0 z-10 flex items-center justify-center rounded-3xl bg-white/60 p-6 backdrop-blur-sm"
                            @click.self="!submitting && (confirming = false)"
                        >
                            <div
                                class="fconfirm-card w-full max-w-sm rounded-2xl border border-gray-100 bg-white p-5 shadow-2xl shadow-gray-950/20 motion-reduce:transition-none"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="fusion-confirm-title"
                            >
                                <div class="flex items-start gap-3.5">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-orange-500 to-red-600 text-white shadow-sm shadow-orange-500/40">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v5a4 4 0 0 0 4 4h6m0 0-3-3m3 3-3 3M7 20v-5" /></svg>
                                    </span>
                                    <div class="min-w-0">
                                        <h3 id="fusion-confirm-title" class="text-base font-bold tracking-tight text-gray-900">
                                            ¿Fusionar {{ selected.size }} {{ fichas(selected.size) }}?
                                        </h3>
                                        <p class="mt-1.5 text-[13px] leading-relaxed text-gray-600">
                                            Se conservará <span class="font-semibold text-gray-900">«{{ canonical?.name }}»</span>.
                                            {{ absorbedIds.length === 1 ? 'La otra ficha se dará' : `Las otras ${absorbedIds.length} fichas se darán` }}
                                            de baja y sus
                                            <span class="font-semibold tabular-nums text-gray-900">{{ n(preview?.items_count) }}</span>
                                            {{ preview?.items_count === 1 ? 'línea de compra se reapuntará' : 'líneas de compra se reapuntarán' }}.
                                        </p>
                                        <p class="mt-2 text-[13px] font-semibold text-red-600">Esta acción no se puede deshacer.</p>
                                    </div>
                                </div>
                                <div class="mt-5 flex justify-end gap-2">
                                    <button
                                        ref="cancelConfirmBtn"
                                        type="button"
                                        :disabled="submitting"
                                        class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition duration-150 hover:bg-gray-50 active:scale-[0.98] disabled:opacity-50 motion-reduce:transition-none"
                                        @click="confirming = false"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="submitting"
                                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-orange-500/30 transition duration-150 hover:from-orange-600 hover:to-red-700 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-60 motion-reduce:transition-none"
                                        @click="submit"
                                    >
                                        <svg v-if="submitting" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z" /></svg>
                                        {{ submitting ? 'Fusionando…' : 'Sí, fusionar' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Entrada/salida del modal: fade del overlay + fade y scale del panel.
   Curvas propias (ease-out fuerte) — las nativas de CSS se quedan cortas. */
.fmodal-enter-active {
    transition: opacity 200ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fmodal-leave-active {
    transition: opacity 150ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fmodal-enter-active .fmodal-panel {
    transition:
        transform 220ms cubic-bezier(0.23, 1, 0.32, 1),
        opacity 220ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fmodal-leave-active .fmodal-panel {
    transition:
        transform 150ms cubic-bezier(0.23, 1, 0.32, 1),
        opacity 150ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fmodal-enter-from,
.fmodal-leave-to {
    opacity: 0;
}
.fmodal-enter-from .fmodal-panel,
.fmodal-leave-to .fmodal-panel {
    opacity: 0;
    transform: scale(0.95) translateY(10px);
}

/* Confirmación: fade del velo + scale de la tarjeta. */
.fconfirm-enter-active {
    transition: opacity 180ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fconfirm-leave-active {
    transition: opacity 130ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fconfirm-enter-active .fconfirm-card {
    transition:
        transform 200ms cubic-bezier(0.23, 1, 0.32, 1),
        opacity 200ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fconfirm-leave-active .fconfirm-card {
    transition:
        transform 130ms cubic-bezier(0.23, 1, 0.32, 1),
        opacity 130ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fconfirm-enter-from,
.fconfirm-leave-to {
    opacity: 0;
}
.fconfirm-enter-from .fconfirm-card,
.fconfirm-leave-to .fconfirm-card {
    opacity: 0;
    transform: scale(0.95);
}

/* Secciones (canónica, impacto, banner): aparición suave en flujo. */
.fsection-enter-active {
    transition:
        opacity 200ms cubic-bezier(0.23, 1, 0.32, 1),
        transform 200ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fsection-leave-active {
    transition: opacity 130ms cubic-bezier(0.23, 1, 0.32, 1);
}
.fsection-enter-from {
    opacity: 0;
    transform: translateY(4px);
}
.fsection-leave-to {
    opacity: 0;
}

/* Contador de seleccionadas. */
.ffade-enter-active,
.ffade-leave-active {
    transition: opacity 150ms ease;
}
.ffade-enter-from,
.ffade-leave-to {
    opacity: 0;
}

/* Check circular: la palomita se "dibuja" (stroke-dashoffset) y el círculo
   hace un pop con leve overshoot al seleccionar. Deselección: sin ceremonia. */
.fcheck path {
    stroke-dasharray: 13;
    stroke-dashoffset: 13;
    transition: stroke-dashoffset 200ms cubic-bezier(0.23, 1, 0.32, 1) 60ms;
}
.fcheck-on .fcheck path {
    stroke-dashoffset: 0;
}
.fcheck-on {
    animation: fcheck-pop 260ms cubic-bezier(0.175, 0.885, 0.32, 1.4);
}
@keyframes fcheck-pop {
    0% {
        transform: scale(0.85);
    }
    60% {
        transform: scale(1.06);
    }
    100% {
        transform: scale(1);
    }
}

/* Movimiento reducido: se conservan los fades (ayudan a la comprensión),
   se elimina todo desplazamiento/escala/dibujo. */
@media (prefers-reduced-motion: reduce) {
    .fmodal-enter-from .fmodal-panel,
    .fmodal-leave-to .fmodal-panel,
    .fconfirm-enter-from .fconfirm-card,
    .fconfirm-leave-to .fconfirm-card {
        transform: none;
    }
    .fsection-enter-from {
        transform: none;
    }
    /* La palomita aparece/desaparece al instante (el estado sigue mandando). */
    .fcheck path {
        transition: none;
    }
    .fcheck-on {
        animation: none;
    }
}
</style>
