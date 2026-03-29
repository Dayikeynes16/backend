<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    folio: { type: String, required: true },
    mode: { type: String, default: 'request' }, // 'direct' (admin) | 'request' (cajero)
    processing: { type: Boolean, default: false },
    isCompleted: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);

const reasons = [
    'Venta duplicada',
    'Producto equivocado',
    'Cliente ya no quiso la compra',
    'Error de captura',
];

const selectedReason = ref('');
const customReason = ref('');

const finalReason = computed(() =>
    selectedReason.value === 'otro' ? customReason.value.trim() : selectedReason.value
);

const canSubmit = computed(() =>
    finalReason.value.length > 0 && !props.processing
);

const title = computed(() =>
    props.mode === 'direct' ? 'Cancelar venta' : 'Solicitar cancelacion'
);

const subtitle = computed(() => {
    if (props.mode === 'direct' && props.isCompleted) {
        return `Esta venta ya fue cobrada. Al cancelarla, los pagos seran revertidos y el corte de caja se recalculara automaticamente.`;
    }
    return props.mode === 'direct'
        ? `Se cancelara la venta ${props.folio}. Los pagos registrados seran eliminados.`
        : `Tu solicitud sera revisada por el administrador de sucursal.`;
});

const confirmLabel = computed(() =>
    props.mode === 'direct' ? 'Cancelar venta' : 'Enviar solicitud'
);

const submit = () => {
    if (!canSubmit.value) return;
    emit('confirm', finalReason.value);
};
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="emit('cancel')">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl" @click.stop>
                <!-- Header -->
                <div class="px-6 pt-6 pb-2">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="mode === 'direct' ? 'bg-red-100' : 'bg-amber-100'">
                            <svg class="h-5 w-5" :class="mode === 'direct' ? 'text-red-600' : 'text-amber-600'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-gray-900">{{ title }}</h3>
                            <p class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
                        </div>
                    </div>
                </div>

                <!-- Warning for completed sales -->
                <div v-if="isCompleted" class="mx-6 mt-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                    <div class="flex gap-2">
                        <svg class="h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        <p class="text-xs font-semibold text-red-800">Esta venta ya fue cobrada. Los pagos se revertiran y los cortes de caja afectados se recalcularan automaticamente.</p>
                    </div>
                </div>

                <!-- Reasons -->
                <div class="px-6 py-4 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Motivo</p>
                    <button v-for="reason in reasons" :key="reason" type="button" @click="selectedReason = reason"
                        :class="['w-full rounded-lg px-4 py-2.5 text-left text-sm transition',
                            selectedReason === reason
                                ? (mode === 'direct' ? 'bg-red-100 font-semibold text-red-900 ring-1 ring-red-200' : 'bg-amber-100 font-semibold text-amber-900 ring-1 ring-amber-200')
                                : 'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100']">
                        {{ reason }}
                    </button>
                    <button type="button" @click="selectedReason = 'otro'"
                        :class="['w-full rounded-lg px-4 py-2.5 text-left text-sm transition',
                            selectedReason === 'otro'
                                ? (mode === 'direct' ? 'bg-red-100 font-semibold text-red-900 ring-1 ring-red-200' : 'bg-amber-100 font-semibold text-amber-900 ring-1 ring-amber-200')
                                : 'bg-gray-50 text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100']">
                        Otro motivo
                    </button>

                    <!-- Custom reason input -->
                    <div v-if="selectedReason === 'otro'" class="pt-1">
                        <textarea v-model="customReason" rows="2" placeholder="Describe el motivo..."
                            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4">
                    <button type="button" @click="emit('cancel')"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                        Volver
                    </button>
                    <button type="button" @click="submit" :disabled="!canSubmit"
                        :class="['rounded-lg px-5 py-2 text-sm font-bold text-white transition disabled:opacity-40',
                            mode === 'direct' ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700']">
                        <svg v-if="processing" class="mr-1.5 inline h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" /></svg>
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
