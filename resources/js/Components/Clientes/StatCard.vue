<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    hint: { type: String, default: '' },
    tone: { type: String, default: 'neutral' }, // neutral | positive | negative | accent
    loading: { type: Boolean, default: false },
});

const toneClasses = {
    neutral: 'bg-white ring-gray-100',
    positive: 'bg-green-50/50 ring-green-100',
    negative: 'bg-red-50/50 ring-red-100',
    accent: 'bg-amber-50/60 ring-amber-100',
};
const valueTone = {
    neutral: 'text-gray-900',
    positive: 'text-green-700',
    negative: 'text-red-700',
    accent: 'text-amber-700',
};
</script>

<template>
    <div :class="['rounded-xl px-4 py-3 ring-1 shadow-sm transition', toneClasses[tone]]">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ label }}</p>
        <p v-if="loading" class="mt-1.5 h-6 w-20 animate-pulse rounded bg-gray-100"></p>
        <p v-else :class="['mt-1 text-xl font-bold tabular-nums', valueTone[tone]]">{{ value }}</p>
        <p v-if="hint && !loading" class="mt-0.5 text-[11px] text-gray-400">{{ hint }}</p>
    </div>
</template>
