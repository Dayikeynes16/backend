<script setup>
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Chrome reutilizable para el modal de detalle de venta (Caja y Sucursal).
 * No sabe nada de la venta: solo overlay + transición + cierre seguro.
 * El contenido (el detalle) va en el slot por defecto, que recibe `requestClose`
 * para que la ✕ del header pase por la misma confirmación que Escape/backdrop.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // Hay cambios sin guardar (p. ej. monto capturado sin cobrar) → confirmar antes de cerrar.
    dirty: { type: Boolean, default: false },
    confirmTitle: { type: String, default: 'Cerrar sin cobrar' },
    confirmMessage: { type: String, default: 'Capturaste un monto que aún no se ha cobrado. Si cierras, se descartará. ¿Cerrar de todos modos?' },
});

const emit = defineEmits(['close']);

const showCloseConfirm = ref(false);

const requestClose = () => {
    if (props.dirty) {
        showCloseConfirm.value = true;
        return;
    }
    emit('close');
};
const confirmClose = () => {
    showCloseConfirm.value = false;
    emit('close');
};

watch(() => props.show, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (!open) {
        showCloseConfirm.value = false;
    }
});

// Escape cierra el modal, pero solo si no hay un sub-diálogo nativo encima
// (WhatsApp usa <dialog>; la confirmación de cierre se maneja sola).
const onKeydown = (event) => {
    if (event.key !== 'Escape' || !props.show) {
        return;
    }
    if (showCloseConfirm.value || document.querySelector('dialog[open]')) {
        return;
    }
    event.preventDefault();
    requestClose();
};
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0">
            <div v-if="show"
                class="fixed inset-0 z-50 flex items-stretch justify-center bg-gray-900/60 backdrop-blur-sm sm:items-center sm:p-4"
                @click.self="requestClose">
                <div class="flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl sm:h-[88vh] sm:max-h-[92vh] sm:w-full sm:max-w-5xl sm:rounded-2xl">
                    <slot :request-close="requestClose" />
                </div>
            </div>
        </Transition>

        <ConfirmDialog v-if="showCloseConfirm"
            :title="confirmTitle"
            :message="confirmMessage"
            confirm-label="Cerrar"
            cancel-label="Seguir aquí"
            variant="warning"
            @confirm="confirmClose"
            @cancel="showCloseConfirm = false" />
    </Teleport>
</template>
