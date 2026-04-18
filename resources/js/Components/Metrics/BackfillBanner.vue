<script setup>
import { computed } from 'vue';

const props = defineProps({
    date: { type: [String, null], default: null },
    range: { type: Object, default: () => ({}) },
});

const shouldShow = computed(() => {
    if (!props.date) return false;
    if (!props.range?.from) return false;
    return props.range.from < props.date.slice(0, 10);
});

const displayDate = computed(() => {
    if (!props.date) return '';
    return new Date(props.date).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
});
</script>

<template>
    <div v-if="shouldShow" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
        <div class="text-xs text-amber-800">
            <strong>Márgenes anteriores al {{ displayDate }} son aproximados.</strong>
            El costo se calculó con el precio al día del backfill — pueden diferir del costo real en esa fecha.
        </div>
    </div>
</template>
