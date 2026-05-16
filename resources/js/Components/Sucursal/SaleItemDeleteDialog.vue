<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SaleItemReasonField from '@/Components/Sucursal/SaleItemReasonField.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    sale: { type: Object, required: true },
    item: { type: Object, default: null },
});

const emit = defineEmits(['close', 'success']);

const reason = ref('');
const processing = ref(false);
const errorMsg = ref('');

watch(() => props.show, (v) => {
    if (v) {
        reason.value = '';
        processing.value = false;
        errorMsg.value = '';
    }
});

const canSubmit = computed(() => reason.value.trim().length > 0 && !processing.value);

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const submit = () => {
    if (!canSubmit.value || !props.item) {
        return;
    }
    processing.value = true;
    errorMsg.value = '';
    router.delete(
        route('sucursal.workbench.items.destroy', [props.tenantSlug, props.sale.id, props.item.id]),
        {
            data: { reason: reason.value.trim() },
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('success');
                emit('close');
            },
            onError: (errors) => {
                errorMsg.value = errors.reason || 'No se pudo eliminar el producto.';
            },
            onFinish: () => { processing.value = false; },
        },
    );
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" leave-active-class="transition duration-100"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show && item" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="$emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-md flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold text-gray-900">Eliminar producto de la venta</h3>
                            <p class="mt-0.5 text-sm text-gray-500">Indica un motivo. El cambio queda registrado en la auditoría.</p>
                        </div>
                        <button @click="$emit('close')" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" type="button">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="space-y-4 px-6 py-5">
                        <!-- Item card -->
                        <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-200">
                            <p class="text-sm font-bold text-gray-900">{{ item.product_name }}</p>
                            <div class="mt-1 flex items-center justify-between text-xs text-gray-500">
                                <span>{{ item.quantity }} × {{ formatMoney(item.unit_price) }}</span>
                                <span class="font-mono tabular-nums text-gray-700">{{ formatMoney(item.subtotal) }}</span>
                            </div>
                        </div>

                        <SaleItemReasonField v-model="reason" mode="required" tone="red" :error="errorMsg" />
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button type="button" @click="$emit('close')" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                        <button type="button" @click="submit" :disabled="!canSubmit"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                            <svg v-if="processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ processing ? 'Eliminando…' : 'Eliminar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
