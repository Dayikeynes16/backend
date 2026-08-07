<script setup>
/**
 * Calendario de la agenda.
 *
 * Antes pintaba, sin límite, el título truncado de cada ocurrencia y no
 * respondía al clic: con cuatro recordatorios recurrentes los 42 días del mes
 * se veían idénticos y no decían nada. Ahora funciona como un índice — de un
 * vistazo se ve dónde se acumula el trabajo, y al pulsar un día se inspecciona
 * su detalle en el panel de al lado.
 */
import { toLocalDate } from '@/utils/date';
import { computed, ref, onMounted } from 'vue';

const props = defineProps({ tenantSlug: { type: String, required: true } });
const emit = defineEmits(['open-item']);

const cursor = ref(new Date());
const occurrences = ref([]);
const loading = ref(false);
const selectedKey = ref(toLocalDate(new Date()));

const monthStart = computed(() => new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1));
const monthLabel = computed(() => cursor.value.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }));

const days = computed(() => {
    const start = new Date(monthStart.value);
    start.setDate(start.getDate() - start.getDay()); // arranca en domingo
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return d;
    });
});

const todayKey = toLocalDate(new Date());

const fetchRange = async () => {
    loading.value = true;
    try {
        const from = toLocalDate(days.value[0]);
        const to = toLocalDate(days.value[41]);
        const res = await fetch(route('agenda.calendar', props.tenantSlug) + `?from=${from}&to=${to}`, {
            headers: { Accept: 'application/json' },
        });
        occurrences.value = (await res.json()).occurrences ?? [];
    } finally {
        loading.value = false;
    }
};

/** Ocurrencias de un día, ya separadas: lo pendiente es lo que hay que ver. */
const dayBuckets = (d) => {
    const key = toLocalDate(d);
    const all = occurrences.value.filter((o) => o.starts_at.slice(0, 10) === key);
    return {
        pending: all.filter((o) => !o.completed_at),
        done: all.filter((o) => o.completed_at),
        all,
    };
};

const hourOf = (iso) => iso.slice(11, 16);
const priorityClass = (p) => ({
    high: 'border-l-red-600 bg-red-50 text-red-900',
    normal: 'border-l-amber-500 bg-amber-50 text-amber-900',
    low: 'border-l-gray-300 bg-gray-50 text-gray-700',
}[p] ?? 'border-l-amber-500 bg-amber-50 text-amber-900');

const RECURRENCE_LABEL = {
    daily: 'Diaria', weekly: 'Semanal', monthly: 'Mensual', yearly: 'Anual',
};

const selectedDate = computed(() => {
    const [y, m, d] = selectedKey.value.split('-').map(Number);
    return new Date(y, m - 1, d);
});
const selectedLabel = computed(() =>
    selectedDate.value.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })
);
const selectedBuckets = computed(() => dayBuckets(selectedDate.value));
const selectedSummary = computed(() => {
    const { pending, done } = selectedBuckets.value;
    if (!pending.length && !done.length) return 'Sin nada agendado';
    const p = `${pending.length} pendiente${pending.length === 1 ? '' : 's'}`;
    return done.length ? `${p} · ${done.length} hecha${done.length === 1 ? '' : 's'}` : p;
});

const selectDay = (d) => { selectedKey.value = toLocalDate(d); };

const prevMonth = () => {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1);
    fetchRange();
};
const nextMonth = () => {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1);
    fetchRange();
};
const inMonth = (d) => d.getMonth() === cursor.value.getMonth();
const isToday = (d) => toLocalDate(d) === todayKey;
const isSelected = (d) => toLocalDate(d) === selectedKey.value;

onMounted(fetchRange);
defineExpose({ refresh: fetchRange });
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        <!-- Mes -->
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
            <div class="mb-3 flex items-center justify-between">
                <div class="flex gap-1">
                    <button type="button" @click="prevMonth" aria-label="Mes anterior"
                        class="rounded-lg px-3 py-1 text-gray-600 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">‹</button>
                    <button type="button" @click="nextMonth" aria-label="Mes siguiente"
                        class="rounded-lg px-3 py-1 text-gray-600 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500">›</button>
                </div>
                <h3 class="text-sm font-bold capitalize text-gray-900">{{ monthLabel }}</h3>
                <span v-if="loading" class="text-[11px] font-semibold text-gray-400">Actualizando…</span>
                <span v-else class="w-16"></span>
            </div>

            <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase text-gray-400">
                <div v-for="d in ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']" :key="d">{{ d }}</div>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1">
                <button v-for="(d, i) in days" :key="i" type="button" @click="selectDay(d)"
                    :aria-label="`${d.getDate()} de ${d.toLocaleDateString('es-MX', { month: 'long' })}, ${dayBuckets(d).pending.length} pendientes`"
                    :class="['flex min-h-[92px] flex-col gap-0.5 rounded-lg border p-1 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500',
                        isSelected(d) ? 'border-red-400 bg-red-50/60'
                        : inMonth(d) ? 'border-gray-100 hover:border-gray-200 hover:bg-gray-50'
                        : 'border-transparent bg-gray-50/50 text-gray-300']">
                    <!-- Número + carga del día: cuánto hay, sin leer nada -->
                    <div class="flex items-center gap-1.5 text-[11px] font-semibold">
                        <span :class="isToday(d) ? 'inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-white' : 'text-gray-600'">{{ d.getDate() }}</span>
                        <span v-if="dayBuckets(d).all.length" class="ml-auto flex items-center gap-0.5">
                            <i v-for="n in Math.min(dayBuckets(d).pending.length, 4)" :key="`p${n}`" class="block h-1 w-1 rounded-full bg-red-500"></i>
                            <i v-for="n in Math.min(dayBuckets(d).done.length, 2)" :key="`d${n}`" class="block h-1 w-1 rounded-full bg-gray-300"></i>
                        </span>
                    </div>

                    <!-- Hasta 3 pendientes, con hora y franja de prioridad -->
                    <span v-for="o in dayBuckets(d).pending.slice(0, 3)" :key="o.id + o.starts_at"
                        :class="['flex items-baseline gap-1 overflow-hidden rounded border-l-2 px-1 text-[10px] leading-tight', priorityClass(o.priority)]">
                        <span v-if="!o.all_day" class="shrink-0 font-bold tabular-nums opacity-70">{{ hourOf(o.starts_at) }}</span>
                        <span class="truncate">{{ o.title }}</span>
                    </span>
                    <span v-if="dayBuckets(d).pending.length > 3" class="px-1 text-[10px] font-bold text-gray-500">
                        +{{ dayBuckets(d).pending.length - 3 }} más
                    </span>

                    <!-- Lo hecho se pliega: no compite por el espacio con lo pendiente -->
                    <span v-if="dayBuckets(d).done.length" class="mt-auto flex items-center gap-1 px-1 text-[10px] text-gray-400">
                        <span class="font-bold text-green-600">✓</span>
                        {{ dayBuckets(d).done.length }} hecha{{ dayBuckets(d).done.length > 1 ? 's' : '' }}
                    </span>
                </button>
            </div>

            <!-- El color por sí solo no explica nada -->
            <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-[11px] text-gray-500">
                <span class="font-semibold text-gray-700">Prioridad:</span>
                <span class="inline-flex items-center gap-1.5"><i class="block h-2 w-2 rounded-sm bg-red-600"></i> Alta</span>
                <span class="inline-flex items-center gap-1.5"><i class="block h-2 w-2 rounded-sm bg-amber-500"></i> Normal</span>
                <span class="inline-flex items-center gap-1.5"><i class="block h-2 w-2 rounded-sm bg-gray-300"></i> Baja</span>
                <span class="inline-flex items-center gap-1.5"><span class="font-bold text-green-600">✓</span> Hechas</span>
                <span class="inline-flex items-center gap-1.5"><i class="block h-1 w-1 rounded-full bg-red-500"></i> Carga del día</span>
            </div>
        </div>

        <!-- Detalle del día seleccionado -->
        <div class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100 lg:sticky lg:top-4">
            <div class="border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-bold capitalize text-gray-900">{{ selectedLabel }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ selectedSummary }}</p>
            </div>

            <div class="flex max-h-[460px] flex-col gap-1.5 overflow-y-auto p-3">
                <p v-if="!selectedBuckets.all.length" class="py-10 text-center text-xs text-gray-400">
                    Nada agendado este día.
                </p>

                <button v-for="o in [...selectedBuckets.pending, ...selectedBuckets.done]" :key="o.id + o.starts_at"
                    type="button" @click="emit('open-item', o)"
                    :class="['w-full rounded-xl border border-gray-100 p-2.5 text-left transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500',
                        o.completed_at ? 'opacity-60' : '']">
                    <p :class="['text-[13px] font-semibold leading-snug text-gray-900', o.completed_at ? 'line-through' : '']">
                        {{ o.title }}
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-gray-500">
                        <span class="rounded-full bg-gray-100 px-1.5 py-0.5 font-semibold tabular-nums">
                            {{ o.all_day ? 'Todo el día' : hourOf(o.starts_at) }}
                        </span>
                        <span v-if="o.priority === 'high'" class="rounded-full bg-red-50 px-1.5 py-0.5 font-semibold text-red-700">Alta</span>
                        <span v-if="o.recurrence && o.recurrence !== 'none'" class="rounded-full bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-700">
                            ↻ {{ RECURRENCE_LABEL[o.recurrence] ?? o.recurrence }}
                        </span>
                        <span v-if="o.owner">{{ o.owner }}</span>
                    </div>
                    <p v-if="o.body" class="mt-1 line-clamp-2 text-[11px] text-gray-500">{{ o.body }}</p>
                </button>
            </div>
        </div>
    </div>
</template>
