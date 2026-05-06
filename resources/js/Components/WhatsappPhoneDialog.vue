<script setup>
import Modal from '@/Components/Modal.vue';
import { ref, computed, nextTick, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    serverError: { type: String, default: null },
    initialPhone: { type: String, default: '' },
    title: { type: String, default: 'Enviar nota por WhatsApp' },
    subtitle: { type: String, default: 'Captura el teléfono. Lo guardamos en la venta para próximos envíos.' },
    actionLabel: { type: String, default: 'Guardar y enviar' },
});

const emit = defineEmits(['close', 'submit']);

const phone = ref('');
const localError = ref(null);
const inputRef = ref(null);

// Quita el prefijo +52 (si lo trae) y deja sólo los 10 dígitos locales en el input.
const stripCountryPrefix = (raw) => {
    const digits = String(raw || '').replace(/\D/g, '');
    if (digits.length === 12 && digits.startsWith('52')) return digits.slice(2);
    if (digits.length === 11 && digits.startsWith('1')) return digits.slice(1);
    return digits.slice(-10);
};

watch(() => props.show, (open) => {
    if (open) {
        phone.value = stripCountryPrefix(props.initialPhone);
        localError.value = null;
        nextTick(() => inputRef.value?.focus());
    }
});

const sanitized = computed(() => phone.value.replace(/\D/g, '').slice(0, 10));
const isComplete = computed(() => sanitized.value.length === 10);
const formatted = computed(() => {
    if (!isComplete.value) return null;
    const d = sanitized.value;
    return `+52 ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`;
});
const errorMessage = computed(() => localError.value || props.serverError);

const onInput = (e) => {
    phone.value = e.target.value.replace(/\D/g, '').slice(0, 10);
    localError.value = null;
};

const onSubmit = () => {
    if (!isComplete.value) {
        localError.value = 'El número debe tener 10 dígitos.';
        return;
    }
    emit('submit', sanitized.value);
};

const sendsAfterSave = computed(() => /enviar/i.test(props.actionLabel));
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="onSubmit" class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#25D366]/10 text-[#25D366]">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-bold text-gray-900">{{ title }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ subtitle }}</p>
                </div>
            </div>

            <div class="mt-5">
                <label for="wa-phone" class="block text-sm font-medium text-gray-700">Teléfono (10 dígitos)</label>
                <div class="mt-1.5 flex overflow-hidden rounded-lg ring-1 ring-gray-200 transition focus-within:ring-2 focus-within:ring-[#25D366]">
                    <span class="inline-flex items-center border-r border-gray-200 bg-gray-50 px-3 text-sm font-semibold text-gray-500">+52</span>
                    <input
                        id="wa-phone"
                        ref="inputRef"
                        :value="phone"
                        @input="onInput"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="55 1234 5678"
                        :disabled="saving"
                        class="flex-1 border-0 bg-transparent py-2.5 pl-3 pr-3 font-mono text-base tabular-nums tracking-wide placeholder-gray-300 focus:ring-0"
                    />
                </div>
                <p v-if="formatted && !errorMessage" class="mt-1.5 text-xs text-gray-500">
                    Se guardará como <span class="font-mono font-semibold tabular-nums text-gray-700">{{ formatted }}</span>
                </p>
                <p v-if="errorMessage" class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                    {{ errorMessage }}
                </p>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="emit('close')" :disabled="saving"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 disabled:opacity-50">
                    Cancelar
                </button>
                <button type="submit" :disabled="saving || !isComplete"
                    class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-[#1ebe5b] disabled:cursor-not-allowed disabled:opacity-50">
                    <svg v-if="!saving && sendsAfterSave" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                    </svg>
                    <svg v-else-if="saving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                    </svg>
                    {{ actionLabel }}
                </button>
            </div>
        </form>
    </Modal>
</template>
