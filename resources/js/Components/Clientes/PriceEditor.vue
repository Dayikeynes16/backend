<script setup>
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
    currentPrice: { type: [Number, String], required: true },
    standardPrice: { type: [Number, String], default: 0 },
    productName: { type: String, default: '' },
    processing: { type: Boolean, default: false },
    errorMessage: { type: String, default: '' },
});

const emit = defineEmits(['save', 'cancel']);

const value = ref(Number(props.currentPrice));
const inputRef = ref(null);

watch(() => props.currentPrice, (v) => { value.value = Number(v); });

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const diff = computed(() => {
    const std = Number(props.standardPrice);
    const pref = Number(value.value);
    if (!std || isNaN(pref)) return null;
    const delta = std - pref;
    const pct = std > 0 ? (delta / std) * 100 : 0;
    return { delta, pct };
});

const tone = computed(() => {
    if (!diff.value) return 'neutral';
    if (diff.value.delta > 0) return 'positive';
    if (diff.value.delta < 0) return 'negative';
    return 'neutral';
});

const focus = async () => {
    await nextTick();
    inputRef.value?.focus();
    inputRef.value?.select();
};
defineExpose({ focus });

const onSubmit = () => {
    if (props.processing) return;
    const n = Number(value.value);
    if (isNaN(n) || n < 0) return;
    emit('save', n);
};

const onCancel = () => emit('cancel');

const handleKey = (e) => {
    if (e.key === 'Enter') { e.preventDefault(); onSubmit(); }
    if (e.key === 'Escape') { e.preventDefault(); onCancel(); }
};
</script>

<template>
    <div class="rounded-xl bg-gradient-to-br from-red-50/40 to-amber-50/40 p-4 ring-2 ring-red-200 shadow-sm">
        <form @submit.prevent="onSubmit" class="space-y-3">
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-gray-500">
                        Precio preferencial<span v-if="productName"> · {{ productName }}</span>
                    </label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-lg font-bold text-gray-400">$</span>
                        <input
                            ref="inputRef"
                            v-model.number="value"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            @keydown="handleKey"
                            class="h-12 w-full rounded-lg border-gray-200 pl-9 pr-4 text-lg font-bold tabular-nums focus:border-red-500 focus:ring-red-300" />
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <p class="mb-1 text-[11px] font-bold uppercase tracking-wide text-gray-400">Estándar</p>
                    <p class="text-base font-semibold tabular-nums text-gray-500">{{ money(standardPrice) }}</p>
                </div>
            </div>

            <!-- Live diff -->
            <div v-if="diff" :class="['flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold',
                tone === 'positive' ? 'bg-green-50 text-green-700' :
                tone === 'negative' ? 'bg-red-50 text-red-700' :
                'bg-gray-50 text-gray-600']">
                <svg v-if="tone === 'positive'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <svg v-else-if="tone === 'negative'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>

                <template v-if="tone === 'positive'">
                    Ahorra {{ money(diff.delta) }}
                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs">{{ diff.pct.toFixed(1) }}%</span>
                </template>
                <template v-else-if="tone === 'negative'">
                    Sobre precio por {{ money(Math.abs(diff.delta)) }}
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs">+{{ Math.abs(diff.pct).toFixed(1) }}%</span>
                </template>
                <template v-else>
                    Mismo precio que el catálogo
                </template>
            </div>

            <p v-if="errorMessage" class="text-xs font-semibold text-red-600">{{ errorMessage }}</p>

            <div class="flex items-center gap-2">
                <button type="submit" :disabled="processing"
                    class="flex h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-red-600 px-5 text-sm font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg v-if="processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4z"></path>
                    </svg>
                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Guardar
                </button>
                <button type="button" @click="onCancel"
                    class="h-11 rounded-lg bg-white px-5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">
                    Cancelar
                </button>
            </div>
            <p class="text-[11px] text-gray-400">Enter para guardar · Esc para cancelar</p>
        </form>
    </div>
</template>
