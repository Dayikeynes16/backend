<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SaleItemReasonField from '@/Components/Sucursal/SaleItemReasonField.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    sale: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    reasonMode: { type: String, default: 'optional' },
});

const emit = defineEmits(['close', 'success']);

const step = ref('pick'); // 'pick' | 'configure'
const search = ref('');
const selectedProduct = ref(null);
const selectedPresentation = ref(null);
const reasonError = ref('');

const form = useForm({
    product_id: '',
    presentation_id: '',
    quantity: '',
    unit_price: '',
    reason: '',
});

watch(() => props.show, (v) => {
    if (v) {
        form.reset();
        form.clearErrors();
        step.value = 'pick';
        search.value = '';
        selectedProduct.value = null;
        selectedPresentation.value = null;
        reasonError.value = '';
    }
});

const filteredProducts = computed(() => {
    const term = search.value.trim().toLowerCase();
    const active = (props.products || []).filter(p => p.status === 'active');
    if (!term) {
        return active.slice(0, 40);
    }

    return active.filter(p => p.name.toLowerCase().includes(term)).slice(0, 40);
});

const pickProduct = (product, presentation = null) => {
    selectedProduct.value = product;
    selectedPresentation.value = presentation;

    form.product_id = product.id;
    form.presentation_id = presentation ? presentation.id : '';
    form.quantity = 1;
    form.unit_price = presentation ? Number(presentation.price) : Number(product.price);
    form.reason = '';

    step.value = 'configure';
};

const back = () => {
    step.value = 'pick';
};

const subtotal = computed(() => {
    const q = Number(form.quantity) || 0;
    const p = Number(form.unit_price) || 0;

    return q * p;
});

const itemLabel = computed(() => {
    if (!selectedProduct.value) {
        return '';
    }
    if (selectedPresentation.value) {
        return `${selectedProduct.value.name} — ${selectedPresentation.value.name}`;
    }

    return selectedProduct.value.name;
});

const canSubmit = computed(() =>
    !form.processing
    && form.product_id
    && Number(form.quantity) > 0
    && Number(form.unit_price) >= 0
    && (props.reasonMode !== 'required' || (form.reason || '').trim().length > 0)
);

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const submit = () => {
    if (!canSubmit.value) {
        return;
    }
    reasonError.value = '';

    form
        .transform((data) => ({
            ...data,
            presentation_id: data.presentation_id || null,
            reason: data.reason?.trim() || '',
        }))
        .post(route('sucursal.workbench.items.store', [props.tenantSlug, props.sale.id]), {
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
            <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="$emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold text-gray-900">
                                {{ step === 'pick' ? 'Agregar producto a la venta' : itemLabel }}
                            </h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                {{ step === 'pick' ? 'Busca y elige el producto que quieres añadir.' : 'Ajusta cantidad y precio antes de guardar.' }}
                            </p>
                        </div>
                        <button @click="$emit('close')" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Step 1: Pick product -->
                    <div v-if="step === 'pick'" class="flex flex-1 flex-col overflow-hidden">
                        <div class="px-6 py-4">
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                <input v-model="search" type="text" placeholder="Buscar producto por nombre…"
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-700 placeholder-gray-400 shadow-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto px-6 pb-5">
                            <div v-if="filteredProducts.length === 0" class="py-10 text-center text-sm text-gray-400">
                                {{ search ? 'Sin productos para esa búsqueda.' : 'No hay productos activos.' }}
                            </div>
                            <div v-else class="space-y-1.5">
                                <div v-for="p in filteredProducts" :key="p.id" class="rounded-xl ring-1 ring-gray-100">
                                    <!-- Producto sin presentaciones → click directo -->
                                    <button v-if="!p.presentations || p.presentations.length === 0"
                                        type="button" @click="pickProduct(p)"
                                        class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-red-50">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ p.name }}</p>
                                            <p class="text-xs text-gray-500">{{ p.unit_type }}</p>
                                        </div>
                                        <span class="shrink-0 font-mono text-sm font-bold tabular-nums text-gray-900">{{ formatMoney(p.price) }}</span>
                                    </button>
                                    <!-- Con presentaciones → desplegar opciones -->
                                    <div v-else class="px-3 py-2">
                                        <div class="mb-1.5 flex items-center justify-between">
                                            <span class="text-sm font-semibold text-gray-900">{{ p.name }}</span>
                                            <span class="text-[10px] uppercase tracking-wider text-gray-400">presentaciones</span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            <button v-if="p.sale_mode === 'both' || p.sale_mode === 'weight' || p.sale_mode === 'piece'"
                                                type="button" @click="pickProduct(p)"
                                                class="rounded-lg bg-gray-50 px-3 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-red-50 hover:text-red-700">
                                                {{ p.sale_mode === 'weight' || p.sale_mode === 'both' ? 'A granel' : 'Por pieza' }}
                                                <span class="ml-1 font-mono tabular-nums">{{ formatMoney(p.price) }}</span>
                                            </button>
                                            <button v-for="pres in p.presentations" :key="pres.id"
                                                type="button" @click="pickProduct(p, pres)"
                                                class="rounded-lg bg-gray-50 px-3 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-red-50 hover:text-red-700">
                                                {{ pres.name }}
                                                <span class="ml-1 font-mono tabular-nums">{{ formatMoney(pres.price) }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Configure (qty, price, reason) -->
                    <form v-else @submit.prevent="submit" class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
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

                        <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Subtotal</span>
                                <span class="font-mono text-lg font-bold tabular-nums text-gray-900">{{ formatMoney(subtotal) }}</span>
                            </div>
                        </div>

                        <SaleItemReasonField v-model="form.reason" :mode="reasonMode" tone="red" :error="reasonError" />
                    </form>

                    <!-- Footer -->
                    <div class="flex justify-between gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button v-if="step === 'configure'" type="button" @click="back"
                            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-200">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                            Volver
                        </button>
                        <div v-else></div>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="$emit('close')"
                                class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                            <button v-if="step === 'configure'" type="button" @click="submit" :disabled="!canSubmit"
                                class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                {{ form.processing ? 'Agregando…' : 'Agregar a la venta' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
