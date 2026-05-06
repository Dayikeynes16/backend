<script setup>
import Modal from '@/Components/Modal.vue';
import { computed } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    sending: { type: Boolean, default: false },
    phone: { type: String, default: null },
    source: { type: String, default: null }, // 'customer' | 'manual'
    customerName: { type: String, default: null },
    serverError: { type: String, default: null },
});

const emit = defineEmits(['close', 'send', 'edit']);

// Formato visual de un E.164 mexicano (+52 555 123 4567).
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
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#25D366]/10 text-[#25D366]">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-bold text-gray-900">Enviar nota por WhatsApp</h3>
                    <p class="mt-1 text-sm text-gray-500">Confirma el número antes de enviar.</p>
                </div>
            </div>

            <div class="mt-5 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Se enviará a</p>
                <p class="mt-1 font-mono text-lg font-bold tabular-nums text-gray-900">{{ prettyPhone }}</p>
                <div class="mt-2 flex items-center gap-1.5">
                    <span v-if="source === 'customer'" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 ring-1 ring-inset ring-blue-600/20">
                        <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3.04 0-7 1.52-7 4.5V17h14v-1.5c0-2.98-3.96-4.5-7-4.5Z" /></svg>
                        Cliente
                    </span>
                    <span v-else class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-600/20">
                        <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                        Manual
                    </span>
                    <span v-if="source === 'customer' && customerName" class="truncate text-xs text-gray-500">{{ customerName }}</span>
                </div>
            </div>

            <p v-if="serverError" class="mt-3 flex items-center gap-1.5 text-xs text-red-600">
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                {{ serverError }}
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="sending"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 disabled:opacity-50">
                    Cancelar
                </button>
                <button v-if="source === 'manual'" type="button" @click="emit('edit')" :disabled="sending"
                    class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-amber-700 ring-1 ring-amber-200 transition hover:bg-amber-50 disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                    Editar
                </button>
                <button type="button" @click="emit('send')" :disabled="sending"
                    class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-[#1ebe5b] disabled:cursor-wait disabled:opacity-60">
                    <svg v-if="!sending" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                    </svg>
                    <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                    </svg>
                    Enviar
                </button>
            </div>
        </div>
    </Modal>
</template>
