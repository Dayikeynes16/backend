<script setup>
import { computed, ref, watch } from 'vue';
import { useCart } from '../composables/useCart.js';

const props = defineProps({
    product: { type: Object, required: true },
    branchId: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const cart = useCart(props.branchId);
const selectedPresentation = ref(
    props.product.sale_mode === 'presentation' && props.product.presentations.length > 0
        ? props.product.presentations[0]
        : null,
);

// Mode: 'qty' (por cantidad) or 'amount' (por monto $). Only applies to kg products without presentation.
const mode = ref('qty');
const quantity = ref(1);
const amount = ref(100);
const notes = ref('');

const unitStep = computed(() => (props.product.unit_type === 'kg' ? 0.25 : 1));
const unitMin = computed(() => (props.product.unit_type === 'kg' ? 0.25 : 1));
const unitLabel = computed(() => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[props.product.unit_type] || ''));

const effectivePrice = computed(() => {
    if (selectedPresentation.value && ['presentation', 'both'].includes(props.product.sale_mode)) {
        return selectedPresentation.value.price;
    }
    return props.product.price;
});

// "Por precio" only makes sense for kg products without a fixed presentation.
const supportsAmountMode = computed(
    () => props.product.unit_type === 'kg' && !selectedPresentation.value,
);

// Force back to 'qty' if presentation gets selected or product is not kg
watch(supportsAmountMode, (v) => { if (!v) mode.value = 'qty'; });

// When in 'amount' mode, quantity derives from amount / unit_price
const derivedQuantity = computed(() => {
    if (mode.value !== 'amount') return Number(quantity.value);
    const price = Number(effectivePrice.value);
    if (!price) return 0;
    return Number((Number(amount.value) / price).toFixed(3));
});

const subtotal = computed(() => {
    if (mode.value === 'amount') return Number(amount.value);
    return Number(quantity.value) * Number(effectivePrice.value);
});

const canAdd = computed(() => {
    if (mode.value === 'amount') return Number(amount.value) >= 10;
    return Number(quantity.value) >= unitMin.value;
});

const decrement = () => {
    const next = Number(quantity.value) - unitStep.value;
    if (next >= unitMin.value) quantity.value = Number(next.toFixed(3));
};
const increment = () => {
    quantity.value = Number((Number(quantity.value) + unitStep.value).toFixed(3));
};

const bumpAmount = (delta) => {
    const next = Number(amount.value) + delta;
    if (next >= 10) amount.value = next;
};

const quickAmounts = [100, 200, 300, 500];

const addToCart = () => {
    if (!canAdd.value) return;

    const productName = selectedPresentation.value
        ? `${props.product.name} - ${selectedPresentation.value.name}`
        : props.product.name;

    // If user ordered by amount, auto-prepend a note so the butcher knows the intent.
    let finalNotes = notes.value.trim() || '';
    if (mode.value === 'amount') {
        const prefix = `Pedido por monto: $${Number(amount.value).toFixed(2)}`;
        finalNotes = finalNotes ? `${prefix}. ${finalNotes}` : prefix;
    }

    cart.addItem({
        product_id: props.product.id,
        product_name: productName,
        unit_type: props.product.unit_type,
        quantity: derivedQuantity.value,
        unit_price: Number(effectivePrice.value),
        presentation_id: selectedPresentation.value?.id || null,
        notes: finalNotes || null,
    });

    emit('close');
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center sm:justify-center bg-black/40 backdrop-blur-sm" @click.self="emit('close')">
        <div class="w-full max-w-lg overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl">
            <!-- Image -->
            <div class="relative h-48 w-full overflow-hidden bg-gray-100 sm:h-56">
                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
                <div v-else class="flex h-full w-full items-center justify-center">
                    <svg class="h-16 w-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" /></svg>
                </div>
                <button @click="emit('close')" class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow-sm">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Body -->
            <div class="p-5">
                <h2 class="text-xl font-bold text-gray-900">{{ product.name }}</h2>
                <p v-if="product.description" class="mt-1 text-sm text-gray-500">{{ product.description }}</p>
                <p class="mt-2 text-lg font-bold text-red-600">${{ effectivePrice.toFixed(2) }} <span class="text-sm font-normal text-gray-400">/ {{ unitLabel }}</span></p>

                <!-- Presentations -->
                <div v-if="product.presentations.length > 0 && ['presentation', 'both'].includes(product.sale_mode)" class="mt-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Presentación</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <button v-for="pr in product.presentations" :key="pr.id"
                            @click="selectedPresentation = pr"
                            :class="['rounded-xl p-3 text-left text-sm font-semibold transition ring-1',
                                selectedPresentation?.id === pr.id
                                    ? 'bg-red-50 text-red-700 ring-red-300'
                                    : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50']">
                            <span>{{ pr.name }}</span>
                            <span class="ml-2 text-gray-400">${{ pr.price.toFixed(2) }}</span>
                        </button>
                    </div>
                </div>

                <!-- Mode toggle (only for kg products without fixed presentation) -->
                <div v-if="supportsAmountMode" class="mt-5">
                    <div class="grid grid-cols-2 gap-0 rounded-xl bg-gray-100 p-1">
                        <button type="button" @click="mode = 'qty'"
                            :class="['flex items-center justify-center gap-2 rounded-lg py-2.5 text-xs font-bold transition',
                                mode === 'qty' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">
                            ⚖️ Por kilos
                        </button>
                        <button type="button" @click="mode = 'amount'"
                            :class="['flex items-center justify-center gap-2 rounded-lg py-2.5 text-xs font-bold transition',
                                mode === 'amount' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500']">
                            💲 Por precio
                        </button>
                    </div>
                </div>

                <!-- Quantity (por kilos / piezas) -->
                <div v-if="mode === 'qty'" class="mt-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Cantidad</p>
                    <div class="mt-2 flex items-center gap-3">
                        <button @click="decrement" type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition hover:bg-gray-200 active:bg-gray-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                        </button>
                        <div class="flex-1 text-center">
                            <input type="number" v-model.number="quantity" :step="unitStep" :min="unitMin"
                                class="w-24 rounded-xl border-gray-200 text-center text-lg font-bold focus:border-red-400 focus:ring-red-300" />
                            <p class="mt-0.5 text-xs text-gray-400">{{ unitLabel }}</p>
                        </div>
                        <button @click="increment" type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition hover:bg-gray-200 active:bg-gray-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Amount (por precio) -->
                <div v-else class="mt-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">¿Cuánto quieres gastar?</p>

                    <!-- Big amount display with +/- -->
                    <div class="mt-2 flex items-center gap-3">
                        <button @click="bumpAmount(-50)" type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition hover:bg-gray-200 active:bg-gray-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                        </button>
                        <div class="flex-1 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-2xl font-bold text-gray-400">$</span>
                                <input type="number" v-model.number="amount" min="10" step="10"
                                    class="w-32 border-0 bg-transparent text-center text-3xl font-extrabold text-gray-900 focus:ring-0 focus:outline-none" />
                            </div>
                            <p class="-mt-1 text-xs text-gray-400">≈ <span class="font-semibold">{{ derivedQuantity.toFixed(3) }} {{ unitLabel }}</span></p>
                        </div>
                        <button @click="bumpAmount(50)" type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition hover:bg-gray-200 active:bg-gray-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        </button>
                    </div>

                    <!-- Quick amount chips -->
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button v-for="a in quickAmounts" :key="a" type="button" @click="amount = a"
                            :class="['flex-1 rounded-xl px-3 py-2 text-sm font-bold transition ring-1 min-w-[70px]',
                                Number(amount) === a ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50']">
                            ${{ a }}
                        </button>
                    </div>

                    <!-- Helper text -->
                    <div class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-amber-100">
                        💡 Te despacharemos <span class="font-semibold">aproximadamente {{ derivedQuantity.toFixed(3) }} {{ unitLabel }}</span>. El peso exacto puede variar ligeramente; tu ticket final reflejará el peso real.
                    </div>
                </div>

                <!-- Notes -->
                <div class="mt-5">
                    <label for="notes" class="text-xs font-bold uppercase tracking-wider text-gray-500">Nota para la carnicería (opcional)</label>
                    <textarea id="notes" v-model="notes" rows="2" maxlength="500" placeholder="Ej: cortar delgado, limpia de grasa, por favor..."
                        class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-red-400 focus:ring-red-300"></textarea>
                </div>
            </div>

            <!-- Footer -->
            <div class="border-t border-gray-100 bg-gray-50 p-4">
                <button @click="addToCart" :disabled="!canAdd"
                    class="flex w-full items-center justify-center gap-3 rounded-2xl bg-red-600 py-4 text-sm font-bold text-white shadow-lg transition hover:bg-red-700 disabled:opacity-50">
                    <span>Agregar al carrito</span>
                    <span class="rounded-full bg-white/20 px-3 py-0.5">${{ subtotal.toFixed(2) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
