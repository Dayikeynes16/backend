<script setup>
import { computed, ref } from 'vue';
import { formatDelta } from '@/composables/useCurrency';

const props = defineProps({
    label: String,
    value: [String, Number],
    format: { type: String, default: 'text' }, // text|currency|number|percent
    delta: { type: [Number, null], default: null },
    hint: { type: String, default: '' },
    icon: { type: String, default: '' },
    tone: { type: String, default: 'neutral' }, // neutral|red|green|amber|blue
    tooltip: { type: String, default: '' }, // explicación de cómo se calcula
});

const showTooltip = ref(false);

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
    <div :class="['relative flex min-w-0 flex-col justify-between rounded-2xl border bg-gradient-to-br p-4 shadow-sm transition hover:shadow-md sm:p-5', toneRing, toneAccent]">
        <div class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 items-center gap-1.5">
                <p class="truncate text-[10px] font-bold uppercase tracking-[0.12em] text-gray-500 sm:text-xs sm:tracking-wider">{{ label }}</p>
                <button v-if="tooltip" type="button"
                    @mouseenter="showTooltip = true" @mouseleave="showTooltip = false"
                    @click="showTooltip = !showTooltip" @blur="showTooltip = false"
                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-gray-200 text-[10px] font-bold text-gray-500 transition hover:bg-gray-300 hover:text-gray-700"
                    aria-label="Cómo se calcula">
                    ?
                </button>
            </div>
            <span v-if="deltaLabel" :class="['shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold tabular-nums sm:text-[11px]', deltaClasses]">{{ deltaLabel }}</span>
        </div>
        <!-- Valor: sin `truncate` para no cortar números grandes; usa font fluida
             y `tabular-nums` para alineación consistente. break-words evita
             desbordes en valores extremos. -->
        <p class="mt-2 break-words font-bold leading-tight tracking-tight tabular-nums text-gray-900 sm:mt-3 text-lg sm:text-xl lg:text-2xl">
            <slot>{{ value }}</slot>
        </p>
        <p v-if="hint" class="mt-1 line-clamp-2 text-[11px] leading-snug text-gray-500 sm:text-xs">{{ hint }}</p>

        <!-- Tooltip popover -->
        <Transition
            enter-active-class="transition duration-150 ease-out"
            leave-active-class="transition duration-100 ease-in"
            enter-from-class="opacity-0 -translate-y-1"
            leave-to-class="opacity-0 -translate-y-1">
            <div v-if="showTooltip && tooltip"
                class="absolute left-3 right-3 top-11 z-20 rounded-xl bg-gray-900 px-3 py-2 text-xs leading-relaxed text-white shadow-lg ring-1 ring-black/5 sm:left-4 sm:right-4 sm:top-12">
                {{ tooltip }}
            </div>
        </Transition>
    </div>
</template>
