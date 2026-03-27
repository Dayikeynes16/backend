<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const allMethods = [
    { id: 'cash', label: 'Efectivo' },
    { id: 'card', label: 'Tarjeta' },
    { id: 'transfer', label: 'Transferencia' },
];

const props = defineProps({
    sale: { type: Object, required: true },
    paymentRoute: { type: String, required: true },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

const availableMethods = computed(() => allMethods.filter(m => props.paymentMethods.includes(m.id)));
const hasNoMethods = computed(() => availableMethods.value.length === 0);

const emit = defineEmits(['success', 'cancel']);

const defaultMethod = props.paymentMethods.length > 0 ? props.paymentMethods[0] : '';
const form = useForm({ method: defaultMethod, amount: '' });

const received = computed(() => parseFloat(form.amount) || 0);
const pending = computed(() => parseFloat(props.sale.amount_pending) || 0);
const change = computed(() => Math.max(received.value - pending.value, 0));
const isOverpay = computed(() => received.value > pending.value);
const remaining = computed(() => Math.max(pending.value - received.value, 0));

const submit = () => {
    form.post(props.paymentRoute, {
        preserveScroll: true,
        onSuccess: () => { form.reset('amount'); emit('success'); },
    });
};
</script>

<template>
    <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 ring-1 ring-gray-100">
        <h3 class="mb-4 text-sm font-bold text-gray-900">Registrar Cobro</h3>

        <div v-if="hasNoMethods" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            No hay metodos de pago habilitados para esta sucursal. Contacta al administrador.
        </div>

        <form v-else @submit.prevent="submit" class="space-y-4">
            <div class="flex gap-3">
                <div class="w-36">
                    <label class="text-xs font-medium text-gray-500">Metodo</label>
                    <select v-model="form.method" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                        <option v-for="m in availableMethods" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>
                </div>
                <div class="flex-1">
                    <label class="text-xs font-medium text-gray-500">{{ form.method === 'cash' ? 'Monto recibido' : 'Monto' }}</label>
                    <div class="relative mt-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" required placeholder="0.00"
                            class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </div>
            </div>

            <!-- Change calculation (only for cash) -->
            <div v-if="form.amount && form.method === 'cash'" class="rounded-lg bg-gray-50 p-4">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <p class="text-xs text-gray-400">Pendiente</p>
                        <p class="text-sm font-bold text-gray-900">${{ pending.toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Recibido</p>
                        <p class="text-sm font-bold text-gray-900">${{ received.toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">{{ isOverpay ? 'Cambio' : 'Falta' }}</p>
                        <p class="text-sm font-bold" :class="isOverpay ? 'text-green-600' : 'text-amber-600'">
                            ${{ isOverpay ? change.toFixed(2) : remaining.toFixed(2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Cobrar</button>
                <button type="button" @click="emit('cancel')" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
            </div>

            <p v-if="form.errors.method" class="text-xs text-red-600">{{ form.errors.method }}</p>
            <p v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</p>
        </form>
    </div>
</template>
