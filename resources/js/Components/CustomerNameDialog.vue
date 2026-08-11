<script setup>
import Modal from '@/Components/Modal.vue';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    phone: { type: String, default: '' },
    serverError: { type: String, default: null },
});

const emit = defineEmits(['close', 'submit']);

const name = ref('');
const localError = ref(null);
const inputRef = ref(null);

// Formato visual de un E.164 mexicano (+52 993 123 4567).
const prettyPhone = computed(() => {
    if (!props.phone) return '';
    const digits = props.phone.replace(/\D/g, '');
    if (digits.length === 12 && digits.startsWith('52')) {
        const local = digits.slice(2);
        return `+52 ${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6)}`;
    }

    return props.phone;
});

const errorMessage = computed(() => localError.value || props.serverError);

watch(() => props.show, (open) => {
    if (open) {
        name.value = '';
        localError.value = null;
        nextTick(() => inputRef.value?.focus());
    }
});

const onSubmit = () => {
    const value = name.value.trim();

    if (value.length < 2) {
        localError.value = 'Escribe el nombre del cliente.';

        return;
    }

    emit('submit', value);
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="onSubmit" class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-3.04 0-7 1.52-7 4.5V17h14v-1.5c0-2.98-3.96-4.5-7-4.5Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-bold text-gray-900">¿Cómo se llama el cliente?</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Se registró automáticamente con su teléfono. Ponle nombre para encontrarlo después.
                    </p>
                </div>
            </div>

            <div class="mt-5 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-100">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Teléfono</p>
                <p class="mt-1 font-mono text-lg font-bold tabular-nums text-gray-900">{{ prettyPhone }}</p>
            </div>

            <div class="mt-4">
                <label for="customer-name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input
                    id="customer-name"
                    ref="inputRef"
                    v-model="name"
                    type="text"
                    maxlength="255"
                    placeholder="Juan Pérez"
                    autocomplete="off"
                    class="mt-1 w-full rounded-lg border-gray-200 text-sm placeholder-gray-400 focus:border-red-400 focus:ring-red-300"
                    @input="localError = null"
                />
                <p v-if="errorMessage" class="mt-1 flex items-center gap-1.5 text-xs text-red-600">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    {{ errorMessage }}
                </p>
            </div>

            <div class="mt-6 flex items-center justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 disabled:opacity-50">
                    Ahora no
                </button>
                <button type="submit" :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-wait disabled:opacity-60">
                    <svg v-if="saving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                    </svg>
                    {{ saving ? 'Guardando…' : 'Guardar nombre' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
