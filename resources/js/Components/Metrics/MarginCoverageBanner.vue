<script setup>
defineProps({
    itemsWithoutCost: { type: Number, required: true },
});

const emit = defineEmits(['filter-uncosted']);
</script>

<template>
    <div v-if="itemsWithoutCost > 0"
        class="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <p class="text-sm leading-snug">
                <span class="font-semibold">{{ itemsWithoutCost }}
                    {{ itemsWithoutCost === 1 ? 'item vendido' : 'items vendidos' }} sin costo registrado</span>
                {{ itemsWithoutCost === 1 ? 'fue excluido' : 'fueron excluidos' }}
                del cálculo de ganancia y margen en este rango.
            </p>
        </div>
        <button type="button" @click="emit('filter-uncosted')"
            class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 sm:self-auto">
            Ver productos afectados
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </button>
    </div>
</template>
