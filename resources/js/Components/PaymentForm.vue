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
            <!-- Payment method: segmented control -->
            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Método de pago</label>
                <div class="mt-2 flex gap-2">
                    <button v-for="m in availableMethods" :key="m.id" type="button"
                        @click="form.method = m.id"
                        :class="['flex flex-1 items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold transition-all',
                            form.method === m.id
                                ? 'bg-red-600 text-white shadow-sm'
                                : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200 hover:bg-gray-100 active:bg-gray-200']">
                        <svg v-if="m.id === 'cash'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        <svg v-else-if="m.id === 'card'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>
                        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                        {{ m.label }}
                    </button>
                </div>
            </div>

            <!-- Amount input: large, touch-friendly -->
            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                    {{ form.method === 'cash' ? 'Monto recibido' : 'Monto' }}
                </label>
                <div class="relative mt-2">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-lg font-semibold text-gray-400">$</span>
                    <input v-model="form.amount" type="number" inputmode="decimal" step="0.01" min="0.01" required placeholder="0.00"
                        class="block w-full rounded-xl border-gray-200 py-4 pl-10 pr-4 text-xl font-bold tabular-nums placeholder:text-gray-300 focus:border-red-400 focus:ring-red-400" />
                </div>
            </div>

            <!-- Change calculation (all methods) -->
            <div v-if="form.amount" class="grid grid-cols-3 gap-2 rounded-xl bg-gray-50 p-4 text-center">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Pendiente</p>
                    <p class="mt-0.5 text-base font-bold text-gray-900">${{ pending.toFixed(2) }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Recibido</p>
                    <p class="mt-0.5 text-base font-bold text-gray-900">${{ received.toFixed(2) }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ isOverpay ? 'Cambio' : 'Falta' }}</p>
                    <p class="mt-0.5 text-base font-bold" :class="isOverpay ? 'text-green-600' : 'text-amber-600'">
                        ${{ isOverpay ? change.toFixed(2) : remaining.toFixed(2) }}
                    </p>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" :disabled="form.processing"
                    class="flex-1 rounded-xl bg-red-600 py-3.5 text-sm font-bold text-white transition hover:bg-red-700 active:scale-[0.98] disabled:opacity-50">
                    Cobrar
                </button>
                <button type="button" @click="emit('cancel')"
                    class="rounded-xl px-5 py-3.5 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 active:bg-gray-200">
                    Cancelar
                </button>
            </div>

            <p v-if="form.errors.method" class="text-xs text-red-600">{{ form.errors.method }}</p>
            <p v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</p>
        </form>
    </div>
</template>
