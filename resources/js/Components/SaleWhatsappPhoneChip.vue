<script setup>
import { computed } from 'vue';

const props = defineProps({
    phone: { type: String, default: null },
    source: { type: String, default: null }, // 'customer' | 'manual' | null
    customerName: { type: String, default: null },
});

const emit = defineEmits(['edit', 'remove', 'add']);

const prettyPhone = computed(() => {
    if (!props.phone) return '';
    const digits = props.phone.replace(/\D/g, '');
    if (digits.length === 12 && digits.startsWith('52')) {
        const local = digits.slice(2);
        return `+52 ${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6)}`;
    }
    return props.phone;
});
</script>

<template>
    <div class="inline-flex items-center gap-2 rounded-full bg-gray-50 py-1 pl-3 pr-1.5 text-xs ring-1 ring-gray-200">
        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
        </svg>

        <template v-if="phone">
            <span class="font-mono font-semibold tabular-nums text-gray-800">{{ prettyPhone }}</span>
            <span v-if="source === 'customer'"
                class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 ring-1 ring-inset ring-blue-600/20">
                <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3.04 0-7 1.52-7 4.5V17h14v-1.5c0-2.98-3.96-4.5-7-4.5Z" /></svg>
                Cliente
            </span>
            <span v-else
                class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-600/20">
                Manual
            </span>
            <span v-if="source === 'customer' && customerName" class="max-w-[160px] truncate text-[11px] text-gray-500">{{ customerName }}</span>

            <!-- Editar/quitar solo para fuente manual -->
            <span v-if="source === 'manual'" class="ml-1 flex items-center gap-0.5">
                <button type="button" @click="emit('edit')"
                    title="Editar teléfono" aria-label="Editar teléfono"
                    class="flex h-6 w-6 items-center justify-center rounded-full text-gray-400 transition hover:bg-amber-50 hover:text-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                </button>
                <button type="button" @click="emit('remove')"
                    title="Quitar teléfono" aria-label="Quitar teléfono"
                    class="flex h-6 w-6 items-center justify-center rounded-full text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                </button>
            </span>
        </template>

        <template v-else>
            <span class="text-gray-500">Sin teléfono asociado</span>
            <button type="button" @click="emit('add')"
                title="Agregar teléfono" aria-label="Agregar teléfono"
                class="ml-1 flex h-6 items-center gap-1 rounded-full bg-[#25D366]/10 px-2 text-[11px] font-bold text-[#128C7E] transition hover:bg-[#25D366]/20 focus:outline-none focus:ring-2 focus:ring-[#25D366]/40">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Agregar
            </button>
        </template>
    </div>
</template>
