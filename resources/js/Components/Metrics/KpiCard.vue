<script setup>
import { computed } from 'vue';
import { formatDelta } from '@/composables/useCurrency';

const props = defineProps({
    label: String,
    value: [String, Number],
    format: { type: String, default: 'text' }, // text|currency|number|percent
    delta: { type: [Number, null], default: null },
    hint: { type: String, default: '' },
    icon: { type: String, default: '' },
    tone: { type: String, default: 'neutral' }, // neutral|red|green|amber|blue
});

const deltaLabel = computed(() => formatDelta(props.delta));

const deltaClasses = computed(() => {
    if (props.delta === null || props.delta === undefined || isNaN(props.delta)) return 'bg-gray-100 text-gray-500';
    if (props.delta > 0) return 'bg-emerald-50 text-emerald-700';
    if (props.delta < 0) return 'bg-rose-50 text-rose-700';
    return 'bg-gray-100 text-gray-600';
});

const toneRing = computed(() => ({
    neutral: 'border-gray-200',
    red: 'border-red-200',
    green: 'border-emerald-200',
    amber: 'border-amber-200',
    blue: 'border-blue-200',
}[props.tone] || 'border-gray-200'));

const toneAccent = computed(() => ({
    neutral: 'from-gray-50 to-white',
    red: 'from-red-50 to-white',
    green: 'from-emerald-50 to-white',
    amber: 'from-amber-50 to-white',
    blue: 'from-blue-50 to-white',
}[props.tone] || 'from-gray-50 to-white'));
</script>

<template>
    <div :class="['relative flex flex-col justify-between rounded-2xl border bg-gradient-to-br p-5 shadow-sm transition hover:shadow-md', toneRing, toneAccent]">
        <div class="flex items-start justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ label }}</p>
            <span v-if="deltaLabel" :class="['rounded-full px-2 py-0.5 text-[11px] font-bold', deltaClasses]">{{ deltaLabel }}</span>
        </div>
        <p class="mt-3 text-2xl font-bold leading-tight tracking-tight text-gray-900">
            <slot>{{ value }}</slot>
        </p>
        <p v-if="hint" class="mt-1 text-xs text-gray-500">{{ hint }}</p>
    </div>
</template>
