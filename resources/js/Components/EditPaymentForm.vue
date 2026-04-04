<script setup>
import { useForm } from '@inertiajs/vue3';

const methodLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };

const props = defineProps({
    payment: { type: Object, required: true },
    updateRoute: { type: String, required: true },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

const emit = defineEmits(['saved', 'cancel']);

const methods = props.paymentMethods.map(id => ({ id, label: methodLabels[id] || id }));

const form = useForm({
    method: props.payment.method,
    amount: parseFloat(props.payment.amount),
});

const submit = () => {
    form.put(props.updateRoute, {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-3">
        <!-- Method: segmented control -->
        <div class="flex gap-1.5">
            <button v-for="m in methods" :key="m.id" type="button"
                @click="form.method = m.id"
                :class="['flex-1 rounded-lg py-2.5 text-xs font-bold transition-all',
                    form.method === m.id
                        ? 'bg-red-600 text-white shadow-sm'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 active:bg-gray-300']">
                {{ m.label }}
            </button>
        </div>

        <!-- Amount input -->
        <div class="relative">
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base font-semibold text-gray-400">$</span>
            <input v-model="form.amount" type="number" inputmode="decimal" step="0.01" min="0.01" required
                class="block w-full rounded-xl border-gray-200 py-3 pl-8 text-lg font-bold tabular-nums focus:border-red-400 focus:ring-red-400" />
        </div>

        <!-- Actions -->
        <div class="flex gap-2">
            <button type="submit" :disabled="form.processing"
                class="flex-1 rounded-xl bg-red-600 py-3 text-sm font-bold text-white transition hover:bg-red-700 active:scale-[0.98] disabled:opacity-50">
                Guardar
            </button>
            <button type="button" @click="emit('cancel')"
                class="rounded-xl px-5 py-3 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 active:bg-gray-200">
                Cancelar
            </button>
        </div>

        <!-- Validation errors -->
        <p v-if="form.errors.method" class="text-xs text-red-600">{{ form.errors.method }}</p>
        <p v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</p>
    </form>
</template>
