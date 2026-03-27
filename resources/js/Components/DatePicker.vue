<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    allowFuture: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const today = new Date().toISOString().split('T')[0];

const currentDate = computed(() => props.modelValue || today);

const formatted = computed(() => {
    const d = new Date(currentDate.value + 'T12:00:00');
    const dayName = d.toLocaleDateString('es-MX', { weekday: 'short' });
    const day = d.getDate();
    const month = d.toLocaleDateString('es-MX', { month: 'short' });
    const year = d.getFullYear();
    const isToday = currentDate.value === today;
    return isToday ? `Hoy, ${day} ${month}` : `${dayName} ${day} ${month} ${year}`;
});

const canGoForward = computed(() => {
    if (props.allowFuture) return true;
    return currentDate.value < today;
});

const prev = () => {
    const d = new Date(currentDate.value + 'T12:00:00');
    d.setDate(d.getDate() - 1);
    emit('update:modelValue', d.toISOString().split('T')[0]);
};

const next = () => {
    if (!canGoForward.value) return;
    const d = new Date(currentDate.value + 'T12:00:00');
    d.setDate(d.getDate() + 1);
    emit('update:modelValue', d.toISOString().split('T')[0]);
};

const goToday = () => emit('update:modelValue', today);
</script>

<template>
    <div class="inline-flex items-center gap-1 rounded-xl bg-white px-1 py-1 ring-1 ring-gray-200">
        <button type="button" @click="prev" class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </button>

        <button type="button" @click="goToday" class="flex items-center gap-2 rounded-lg px-4 py-1.5 text-sm font-semibold text-gray-900 transition hover:bg-gray-50">
            <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
            {{ formatted }}
        </button>

        <button type="button" @click="next" :disabled="!canGoForward"
            :class="['flex h-9 w-9 items-center justify-center rounded-lg transition', canGoForward ? 'text-gray-400 hover:bg-gray-100 hover:text-gray-700' : 'text-gray-200 cursor-not-allowed']">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>
    </div>
</template>
