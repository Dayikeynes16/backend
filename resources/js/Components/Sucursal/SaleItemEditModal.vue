<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SaleItemReasonField from '@/Components/Sucursal/SaleItemReasonField.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    sale: { type: Object, required: true },
    item: { type: Object, default: null },
    reasonMode: { type: String, default: 'optional' }, // disabled | optional | required
});

const emit = defineEmits(['close', 'success']);

const form = useForm({
    quantity: '',
    unit_price: '',
    reason: '',
});

const reasonError = ref('');

watch(() => props.show, (v) => {
    if (v && props.item) {
        form.reset();
        form.clearErrors();
        form.quantity = Number(props.item.quantity);
        form.unit_price = Number(props.item.unit_price);
        form.reason = '';
        reasonError.value = '';
    }
});

const subtotal = computed(() => {
    const q = Number(form.quantity) || 0;
    const p = Number(form.unit_price) || 0;

    return q * p;
});

const isDirty = computed(() => {
    if (!props.item) {
        return false;
    }

    return Number(form.quantity) !== Number(props.item.quantity)
        || Number(form.unit_price) !== Number(props.item.unit_price);
});

const canSubmit = computed(() =>
    !form.processing
    && form.quantity > 0
    && form.unit_price >= 0
    && isDirty.value
    && (props.reasonMode !== 'required' || (form.reason || '').trim().length > 0)
);

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const submit = () => {
    if (!canSubmit.value || !props.item) {
        return;
    }
    reasonError.value = '';

    form
        .transform((data) => ({
            ...data,
            // Inertia router con PATCH y FormRequest funciona sin spoofing.
            reason: data.reason?.trim() || '',
        }))
        .patch(route('sucursal.workbench.items.update', [props.tenantSlug, props.sale.id, props.item.id]), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('success');
                emit('close');
            },
            onError: (errors) => {
                reasonError.value = errors.reason || '';
            },
        });
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
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold text-gray-900">Editar producto</h3>
                            <p class="mt-0.5 text-sm text-gray-500 truncate">{{ item.product_name }}</p>
                        </div>
                        <button @click="$emit('close')" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <form @submit.prevent="submit" class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Cantidad</label>
                                <input v-model.number="form.quantity" type="number" step="0.001" min="0.001" inputmode="decimal" required
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="form.errors.quantity" class="mt-1 text-xs text-red-600">{{ form.errors.quantity }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Precio unitario</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-gray-400">$</span>
                                    <input v-model.number="form.unit_price" type="number" step="0.01" min="0" inputmode="decimal" required
                                        class="block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-7 pr-3 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                                <p v-if="form.errors.unit_price" class="mt-1 text-xs text-red-600">{{ form.errors.unit_price }}</p>
                            </div>
                        </div>

                        <div class="rounded-xl bg-gray-50 px-4 py-3 text-sm ring-1 ring-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Subtotal</span>
                                <span class="font-mono text-lg font-bold tabular-nums text-gray-900">{{ formatMoney(subtotal) }}</span>
                            </div>
                            <p v-if="!isDirty" class="mt-1 text-xs text-amber-600">Cambia la cantidad o el precio para guardar.</p>
                        </div>

                        <SaleItemReasonField v-model="form.reason" :mode="reasonMode" tone="red" :error="reasonError" />
                    </form>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button type="button" @click="$emit('close')" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                        <button type="button" @click="submit" :disabled="!canSubmit"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
