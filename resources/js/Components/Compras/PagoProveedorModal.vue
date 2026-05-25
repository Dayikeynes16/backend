<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Modo "purchase": pago atado a una compra específica.
    purchase: { type: Object, default: null },
    // Modo "account": pago a cuenta del proveedor (FIFO).
    provider: { type: Object, default: null },
    routes: {
        type: Object,
        required: true,
        // Espera storePurchase (compras.pagos.store) y storeProvider (proveedores.pagos.store).
        validator: (v) => v.storePurchase || v.storeProvider,
    },
});
const emit = defineEmits(['close', 'created']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const mode = computed(() => props.purchase ? 'purchase' : 'provider');
const todayIso = () => new Date().toISOString().slice(0, 10);

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const form = useForm({
    amount: 0,
    payment_method: 'cash',
    paid_at: todayIso(),
    reference: '',
    notes: '',
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.reset();
    form.amount = mode.value === 'purchase' ? (props.purchase?.amount_pending || 0) : 0;
    form.paid_at = todayIso();
    form.payment_method = 'cash';
    form.clearErrors();
});

const close = () => { form.clearErrors(); emit('close'); };

const submit = () => {
    if (mode.value === 'purchase') {
        form.post(route(props.routes.storePurchase, { tenant: slug.value, compra: props.purchase.id }), {
            preserveScroll: true,
            onSuccess: () => { emit('created'); close(); },
        });
    } else {
        form.post(route(props.routes.storeProvider, { tenant: slug.value, provider: props.provider.id }), {
            preserveScroll: true,
            onSuccess: () => { emit('created'); close(); },
        });
    }
};

const methodOptions = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transferencia' },
];
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">
                                {{ mode === 'purchase' ? 'Registrar pago' : 'Pago a cuenta del proveedor' }}
                            </h2>
                            <p v-if="mode === 'purchase' && purchase" class="text-xs text-gray-500">
                                {{ purchase.folio }} · Pendiente: {{ fmt(purchase.amount_pending) }}
                            </p>
                            <p v-else-if="provider" class="text-xs text-gray-500">
                                {{ provider.name }} · El pago se aplica en orden a las compras pendientes (más antiguas primero).
                            </p>
                        </div>
                        <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Monto <span class="text-red-600">*</span></label>
                            <div class="relative">
                                <input v-model.number="form.amount" type="number" step="0.01" min="0.01"
                                    class="w-full rounded-xl border-gray-300 py-2 pl-3 pr-20 text-base font-semibold focus:border-orange-500 focus:ring-orange-500" />
                                <button v-if="mode === 'purchase' && purchase" type="button"
                                    @click="form.amount = purchase.amount_pending"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 active:bg-gray-300">
                                    Exacto
                                </button>
                            </div>
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Método <span class="text-red-600">*</span></label>
                            <div class="flex gap-2">
                                <button v-for="m in methodOptions" :key="m.value" type="button"
                                    @click="form.payment_method = m.value"
                                    :class="['flex-1 rounded-xl px-3 py-2 text-sm font-semibold transition',
                                        form.payment_method === m.value
                                            ? 'bg-orange-600 text-white shadow-sm'
                                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                                    {{ m.label }}
                                </button>
                            </div>
                            <p v-if="form.errors.payment_method" class="mt-1 text-xs text-red-600">{{ form.errors.payment_method }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Fecha del pago</label>
                            <input v-model="form.paid_at" type="date"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Referencia (opcional)</label>
                            <input v-model="form.reference" type="text" placeholder="Núm. de cheque, folio de transferencia, etc."
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Notas</label>
                            <textarea v-model="form.notes" rows="2"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"></textarea>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button @click="submit" :disabled="form.processing || !form.amount"
                            class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-emerald-700 hover:to-teal-700 disabled:opacity-50">
                            {{ form.processing ? 'Registrando…' : 'Registrar pago' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
