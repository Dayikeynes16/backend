<script setup>
import { ref } from 'vue';

const props = defineProps({
    title: { type: String, default: 'Confirmar accion' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirmar' },
    cancelLabel: { type: String, default: 'Cancelar' },
    variant: { type: String, default: 'danger' }, // danger | warning | info
    requireInput: { type: Boolean, default: false },
    inputLabel: { type: String, default: '' },
    inputPlaceholder: { type: String, default: '' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
const inputValue = ref('');

const colors = {
    danger: { bg: 'bg-red-100', icon: 'text-red-600', btn: 'bg-red-600 hover:bg-red-700' },
    warning: { bg: 'bg-amber-100', icon: 'text-amber-600', btn: 'bg-amber-600 hover:bg-amber-700' },
    info: { bg: 'bg-blue-100', icon: 'text-blue-600', btn: 'bg-blue-600 hover:bg-blue-700' },
};

const c = colors[props.variant] || colors.danger;

const submit = () => {
    if (props.requireInput && !inputValue.value.trim()) return;
    emit('confirm', inputValue.value.trim());
};
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="emit('cancel')">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" @click.stop>
                <div class="flex items-start gap-4">
                    <div :class="[c.bg, 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full']">
                        <svg :class="[c.icon, 'h-5 w-5']" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-gray-900">{{ title }}</h3>
                        <p v-if="message" class="mt-1 text-sm text-gray-600">{{ message }}</p>
                    </div>
                </div>

                <!-- Optional input -->
                <div v-if="requireInput" class="mt-4">
                    <label v-if="inputLabel" class="block text-sm font-medium text-gray-700">{{ inputLabel }}</label>
                    <textarea v-model="inputValue" rows="2" :placeholder="inputPlaceholder" required
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" @click="emit('cancel')"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                        {{ cancelLabel }}
                    </button>
                    <button type="button" @click="submit" :disabled="processing || (requireInput && !inputValue.trim())"
                        :class="[c.btn, 'rounded-lg px-5 py-2 text-sm font-bold text-white transition disabled:opacity-50']">
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
