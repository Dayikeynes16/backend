<script setup>
import Modal from '@/Components/Modal.vue';
import { computed } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    customer: { type: Object, default: null },
    preview: { type: Object, default: null },
    serverError: { type: String, default: null },
});

const emit = defineEmits(['close', 'confirm', 'skip']);

const money = (n) => `$${Number(n ?? 0).toLocaleString('es-MX', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

const changesTotal = computed(() => props.preview?.changes_total === true);
const wouldComplete = computed(() => props.preview?.would_complete === true);
const skipped = computed(() => props.preview?.skipped_piece_presentations ?? []);
const isCheaper = computed(
    () => Number(props.preview?.new_total ?? 0) < Number(props.preview?.current_total ?? 0),
);
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3.04 0-7 1.52-7 4.5V17h14v-1.5c0-2.98-3.96-4.5-7-4.5Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-bold text-gray-900">Este número ya es de un cliente</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Es de <span class="font-semibold text-gray-700">{{ customer?.name }}</span>.
                        Asociarlo a esta venta cambiará lo siguiente:
                    </p>
                </div>
            </div>

            <div class="mt-5 flex flex-col gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
                <div v-if="changesTotal" class="flex flex-col gap-1">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700">Total de la venta</p>
                    <p class="flex items-baseline gap-2 font-mono text-lg font-bold tabular-nums text-amber-900">
                        <span class="text-gray-400 line-through">{{ money(preview.current_total) }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                        </svg>
                        <span>{{ money(preview.new_total) }}</span>
                    </p>
                    <p class="text-xs text-amber-700">
                        {{ isCheaper
                            ? 'El cliente tiene precios preferenciales que aplican a esta venta.'
                            : 'Al aplicar los precios del cliente, el total sube.' }}
                    </p>
                </div>

                <p v-if="wouldComplete" class="flex items-start gap-1.5 text-xs font-semibold text-amber-800">
                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    La venta quedará marcada como cobrada.
                </p>

                <p v-if="skipped.length" class="text-xs text-amber-700">
                    Sin precio preferencial (presentaciones por pieza): {{ skipped.join(', ') }}.
                </p>
            </div>

            <p v-if="serverError" class="mt-3 flex items-center gap-1.5 text-xs text-red-600">
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                {{ serverError }}
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 disabled:opacity-50">
                    Cancelar
                </button>
                <button type="button" @click="emit('skip')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-50 disabled:opacity-50">
                    Solo guardar el teléfono
                </button>
                <button type="button" @click="emit('confirm')" :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-wait disabled:opacity-60">
                    <svg v-if="saving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                    </svg>
                    {{ saving ? 'Asociando…' : 'Asociar cliente' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
