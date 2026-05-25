<script setup>
import { toLocalDate } from '@/utils/date';
import { computed, ref, onMounted } from 'vue';

const props = defineProps({ tenantSlug: { type: String, required: true } });

const cursor = ref(new Date());
const occurrences = ref([]);
const loading = ref(false);

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

const itemsForDay = (d) => {
    const key = toLocalDate(d);
    return occurrences.value.filter((o) => o.starts_at.slice(0, 10) === key);
};
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

onMounted(fetchRange);
</script>

<template>
    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
        <div class="mb-3 flex items-center justify-between">
            <button type="button" @click="prevMonth" class="rounded-lg px-3 py-1 text-gray-600 hover:bg-gray-100">‹</button>
            <h3 class="text-sm font-bold capitalize text-gray-900">{{ monthLabel }}</h3>
            <button type="button" @click="nextMonth" class="rounded-lg px-3 py-1 text-gray-600 hover:bg-gray-100">›</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase text-gray-400">
            <div v-for="d in ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']" :key="d">{{ d }}</div>
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1">
            <div v-for="(d, i) in days" :key="i"
                :class="['min-h-[64px] rounded-lg border p-1 text-left', inMonth(d) ? 'border-gray-100' : 'border-transparent bg-gray-50/50 text-gray-300']">
                <div :class="['text-[11px] font-semibold', isToday(d) ? 'inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-white' : '']">{{ d.getDate() }}</div>
                <div v-for="o in itemsForDay(d)" :key="o.id + o.starts_at"
                    :class="['mt-0.5 truncate rounded px-1 text-[10px] font-medium', o.completed_at ? 'bg-gray-100 text-gray-500 line-through opacity-60' : 'bg-red-50 text-red-700']">
                    {{ o.title }}
                </div>
            </div>
        </div>
    </div>
</template>
