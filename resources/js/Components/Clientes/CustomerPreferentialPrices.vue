<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PriceEditor from '@/Components/Clientes/PriceEditor.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    tenantSlug: { type: String, required: true },
    customer: { type: Object, required: true },
    products: { type: Array, default: () => [] },
});

// --- Formato ---
const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));

// --- Listado actual de precios ---
const prices = computed(() => props.customer.prices || []);
const priceCount = computed(() => prices.value.length);

const productMap = computed(() => {
    const map = new Map();
    (props.products || []).forEach(p => map.set(p.id, p));

    return map;
});

const productsAvailable = computed(() => {
    const taken = new Set(prices.value.map(p => p.product_id));

    return (props.products || []).filter(p => !taken.has(p.id));
});

const savingsFor = (price) => {
    const std = Number(price.product?.price ?? 0);
    const pref = Number(price.price);
    if (!std) return null;
    const delta = std - pref;
    const pct = std > 0 ? (delta / std) * 100 : 0;

    return { delta, pct, isDiscount: delta > 0 };
};

// --- Edición de precio existente ---
const editingId = ref(null);
const editingError = ref('');
const editingProcessing = ref(false);

const startEdit = (price) => {
    editingId.value = price.id;
    editingError.value = '';
};
const cancelEdit = () => {
    editingId.value = null;
    editingError.value = '';
};
const saveEdit = (price, newValue) => {
    editingProcessing.value = true;
    editingError.value = '';
    router.put(
        route('sucursal.clientes.precios.update', [props.tenantSlug, props.customer.id, price.id]),
        { price: newValue },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { editingId.value = null; },
            onError: (errors) => { editingError.value = errors.price || 'No se pudo actualizar el precio.'; },
            onFinish: () => { editingProcessing.value = false; },
        },
    );
};

// --- Eliminación ---
const deletingPrice = ref(null);
const confirmDelete = () => {
    if (!deletingPrice.value) return;
    router.delete(
        route('sucursal.clientes.precios.destroy', [props.tenantSlug, props.customer.id, deletingPrice.value.id]),
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { deletingPrice.value = null; },
        },
    );
};

// --- Agregar nuevo precio ---
const showAdd = ref(false);
const addForm = useForm({ product_id: '', price: '' });
const addError = ref('');

const selectedProduct = computed(() => addForm.product_id ? productMap.value.get(Number(addForm.product_id)) : null);

const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addError.value = '';
    showAdd.value = true;
};

const submitAdd = () => {
    addError.value = '';
    addForm.post(
        route('sucursal.clientes.precios.store', [props.tenantSlug, props.customer.id]),
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { showAdd.value = false; addForm.reset(); },
            onError: (errors) => {
                addError.value = errors.price || errors.product_id || 'No se pudo registrar el precio.';
            },
        },
    );
};
</script>

<template>
    <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-bold text-gray-900">Precios preferenciales</h2>
                <span v-if="priceCount > 0" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">{{ priceCount }}</span>
            </div>
            <button v-if="!showAdd && productsAvailable.length > 0" type="button" @click="openAdd"
                class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Agregar precio
            </button>
        </div>

        <!-- Formulario de nuevo precio -->
        <div v-if="showAdd" class="border-b border-gray-100 bg-red-50/30 px-6 py-4">
            <form @submit.prevent="submitAdd" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_auto]">
                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Producto</label>
                    <select v-model="addForm.product_id" required
                        class="block w-full rounded-lg border-gray-200 bg-white py-2 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                        <option value="">Selecciona un producto…</option>
                        <option v-for="p in productsAvailable" :key="p.id" :value="p.id">
                            {{ p.name }} — {{ money(p.price) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-gray-500">Precio preferencial</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">$</span>
                        <input v-model.number="addForm.price" type="number" step="0.01" min="0" required
                            :placeholder="selectedProduct ? Number(selectedProduct.price).toFixed(2) : '0.00'"
                            class="block w-32 rounded-lg border-gray-200 bg-white py-2 pl-7 pr-3 text-sm tabular-nums shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <button type="button" @click="showAdd = false" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</button>
                    <button type="submit" :disabled="addForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                        {{ addForm.processing ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </form>
            <p v-if="addError" class="mt-2 text-xs text-red-600">{{ addError }}</p>
        </div>

        <!-- Listado de precios -->
        <div class="px-6 py-5">
            <div v-if="priceCount === 0 && !showAdd" class="flex flex-col items-center rounded-xl bg-gray-50/50 px-6 py-8 text-center ring-1 ring-gray-100">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-100 to-red-100 text-red-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z" /></svg>
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-700">Sin precios preferenciales</p>
                <p class="mt-1 max-w-sm text-xs text-gray-500">Asigna precios especiales para este cliente que se aplicarán automáticamente al vender.</p>
                <button v-if="productsAvailable.length > 0" type="button" @click="openAdd"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700">
                    Agregar el primero
                </button>
            </div>

            <div v-else class="grid gap-3 lg:grid-cols-2">
                <div v-for="price in prices" :key="price.id">
                    <!-- Edit mode -->
                    <PriceEditor v-if="editingId === price.id"
                        :current-price="price.price"
                        :standard-price="price.product?.price ?? 0"
                        :product-name="price.product?.name ?? ''"
                        :processing="editingProcessing"
                        :error-message="editingError"
                        @save="(v) => saveEdit(price, v)"
                        @cancel="cancelEdit" />
                    <!-- Display mode -->
                    <div v-else
                        class="group flex items-center gap-3 rounded-xl bg-white p-4 ring-1 ring-gray-100 transition hover:ring-gray-200 hover:shadow-sm">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ price.product?.name || 'Producto sin nombre' }}</p>
                            <div class="mt-1 flex items-baseline gap-2">
                                <span class="text-xs text-gray-400 line-through">{{ money(price.product?.price ?? 0) }}</span>
                                <span class="text-lg font-bold tabular-nums text-gray-900">{{ money(price.price) }}</span>
                            </div>
                            <div v-if="savingsFor(price)" class="mt-1">
                                <span v-if="savingsFor(price).isDiscount"
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                    Ahorra {{ savingsFor(price).pct.toFixed(1) }}%
                                </span>
                                <span v-else-if="savingsFor(price).delta < 0"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                    +{{ Math.abs(savingsFor(price).pct).toFixed(1) }}% sobre catálogo
                                </span>
                                <span v-else class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500">
                                    Mismo precio que el catálogo
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                            <button type="button" @click="startEdit(price)" title="Editar"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-orange-50 hover:text-orange-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                            </button>
                            <button type="button" @click="deletingPrice = price" title="Eliminar"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog v-if="deletingPrice"
            title="Eliminar precio preferencial"
            :message="`Se eliminará el precio especial de ${deletingPrice.product?.name || 'este producto'} para ${customer.name}.`"
            confirm-label="Eliminar"
            variant="danger"
            @confirm="confirmDelete"
            @cancel="deletingPrice = null" />
    </section>
</template>
