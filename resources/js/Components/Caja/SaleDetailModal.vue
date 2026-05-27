<script setup>
import SaleDetail from '@/Components/Caja/SaleDetail.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    sale: { type: Object, default: null },
    tenantSlug: { type: String, required: true },
    tenant: { type: Object, required: true },
    branchInfo: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    customers: { type: Array, default: () => [] },
    isLockedByOther: { type: Boolean, default: false },
    lockedByName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'paid', 'mutated', 'pause', 'reactivate', 'request-cancel']);

// "dirty" = hay un monto capturado en el formulario de cobro que aún no se ha cobrado.
const dirty = ref(false);
const showCloseConfirm = ref(false);

const requestClose = () => {
    if (dirty.value) {
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
        dirty.value = false;
    }
});

// Escape cierra el modal, pero solo si no hay un sub-diálogo encima
// (WhatsApp usa <dialog> nativo; la confirmación de cierre se maneja sola).
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
                    <SaleDetail v-if="sale"
                        :key="sale.id"
                        :sale="sale"
                        :tenant-slug="tenantSlug"
                        :tenant="tenant"
                        :branch-info="branchInfo"
                        :payment-methods="paymentMethods"
                        :customers="customers"
                        :is-locked-by-other="isLockedByOther"
                        :locked-by-name="lockedByName"
                        @update:dirty="dirty = $event"
                        @close="requestClose"
                        @paid="$emit('paid')"
                        @mutated="$emit('mutated')"
                        @pause="$emit('pause', $event)"
                        @reactivate="$emit('reactivate', $event)"
                        @request-cancel="$emit('request-cancel')" />
                </div>
            </div>
        </Transition>

        <ConfirmDialog v-if="showCloseConfirm"
            title="Cerrar sin cobrar"
            message="Capturaste un monto que aún no se ha cobrado. Si cierras, se descartará. ¿Cerrar de todos modos?"
            confirm-label="Cerrar"
            cancel-label="Seguir aquí"
            variant="warning"
            @confirm="confirmClose"
            @cancel="showCloseConfirm = false" />
    </Teleport>
</template>
