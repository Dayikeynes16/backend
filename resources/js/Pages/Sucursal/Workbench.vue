<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    sales: Array, products: Array, categories: Array,
    tenant: Object, branchId: Number, paymentMethods: Array,
    canCreate: Boolean, canCancel: Boolean, canEditPayments: Boolean,
});

const allMethodLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };
const enabledMethods = computed(() =>
    (props.paymentMethods || ['cash', 'card', 'transfer']).map(id => ({ id, label: allMethodLabels[id] }))
);
const defaultMethod = computed(() => enabledMethods.value[0]?.id || 'cash');

const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value));

// Real-time
const { sales: queuedSales } = useSaleQueue(props.branchId);
watch(queuedSales, () => {
    router.reload({ only: ['sales'], preserveScroll: true });
}, { deep: true });

let saleUpdateChannel = null;
onMounted(() => {
    if (!props.branchId || !window.Echo) return;
    saleUpdateChannel = window.Echo.private(`sucursal.${props.branchId}`);
    saleUpdateChannel.listen('SaleUpdated', () => {
        router.reload({ only: ['sales'], preserveScroll: true });
    });
});
onUnmounted(() => {
    if (saleUpdateChannel) saleUpdateChannel.stopListening('SaleUpdated');
});

// Concurrency lock
const { lockSale, unlockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    route('sucursal.sale.lock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('sucursal.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
        paymentForm.reset();
        paymentForm.method = defaultMethod.value;
    }
};

const showNewSale = ref(false);
const showTicket = ref(false);
const editingPaymentId = ref(null);

// Helpers
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const methodColor = (m) => ({ cash: 'text-green-600', card: 'text-blue-600', transfer: 'text-purple-600' }[m] || 'text-gray-600');
const unitLabel = (t) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[t] || t);
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';

const timeAgo = (date) => {
    const diff = Math.floor((Date.now() - new Date(date)) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `${Math.floor(diff / 60)}m`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
    return new Date(date).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
};

const paidPct = (s) => s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;

// --- Payment form (always ready, no toggle) ---
const paymentForm = useForm({ method: 'cash', amount: '' });

const pendingAmount = computed(() => selected.value ? parseFloat(selected.value.amount_pending) : 0);
const enteredAmount = computed(() => parseFloat(paymentForm.amount) || 0);
const changeAmount = computed(() => Math.max(enteredAmount.value - pendingAmount.value, 0));
const hasPending = computed(() => pendingAmount.value > 0);

const submitPayment = () => {
    if (!selected.value || !hasPending.value) return;
    paymentForm.post(route('sucursal.workbench.payment', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            paymentForm.reset('amount');
            paymentForm.method = defaultMethod.value;
        },
    });
};

// Edit payment
const editPaymentForm = useForm({ method: '', amount: '' });
const startEditPayment = (p) => {
    editingPaymentId.value = p.id;
    editPaymentForm.method = p.method;
    editPaymentForm.amount = parseFloat(p.amount);
};
const submitEditPayment = (paymentId) => {
    editPaymentForm.put(route('sucursal.workbench.payment.update', [props.tenant.slug, selected.value.id, paymentId]), {
        preserveScroll: true,
        onSuccess: () => { editingPaymentId.value = null; },
    });
};

// Delete payment
const deletePayment = (paymentId) => {
    if (confirm('¿Eliminar este pago? Se recalculara el saldo de la venta.')) {
        router.delete(route('sucursal.workbench.payment.destroy', [props.tenant.slug, selected.value.id, paymentId]), { preserveScroll: true });
    }
};

// Cancel sale
const cancelSale = () => {
    const reason = prompt(`¿Cancelar venta ${selected.value.folio}?\n\nEscribe el motivo de cancelacion:`);
    if (reason && reason.trim()) {
        router.patch(route('sucursal.workbench.cancel', [props.tenant.slug, selected.value.id]), {
            cancel_reason: reason.trim(),
        }, { preserveScroll: true });
    }
};

// New sale modal
const cart = ref([]);
const cartCategory = ref('');
const filteredProducts = computed(() => {
    let p = props.products;
    if (cartCategory.value) p = p.filter(pr => pr.category_id == cartCategory.value);
    return p;
});
const addToCart = (product, presentation = null) => {
    const key = presentation ? `${product.id}-${presentation.id}` : `${product.id}`;
    const existing = cart.value.find(c => c.key === key);
    if (existing) { existing.quantity += 1; return; }

    const price = presentation ? parseFloat(presentation.price) : parseFloat(product.price);
    const name = presentation ? `${product.name} - ${presentation.name}` : product.name;

    cart.value.push({
        key,
        product_id: product.id,
        presentation_id: presentation?.id || null,
        name,
        price,
        unit_type: product.unit_type,
        sale_mode: product.sale_mode,
        quantity: product.sale_mode === 'weight' ? 0 : 1,
        image_path: product.image_path,
    });
};
const removeFromCart = (idx) => cart.value.splice(idx, 1);
const cartTotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.quantity, 0));

const newSaleForm = useForm({ items: [] });
const submitNewSale = () => {
    newSaleForm.items = cart.value.map(c => ({ product_id: c.product_id, quantity: c.quantity, presentation_id: c.presentation_id }));
    newSaleForm.post(route('sucursal.workbench.store', props.tenant.slug), {
        onSuccess: () => { cart.value = []; showNewSale.value = false; },
    });
};
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1>
        </template>

        <div class="flex h-[calc(100vh-8rem)] gap-5">
            <!-- LEFT: Active sales queue -->
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <div>
                        <h2 class="text-sm font-bold text-gray-900">Ventas Activas</h2>
                        <p class="text-xs text-gray-400">{{ sales.length }} venta{{ sales.length !== 1 ? 's' : '' }} en operacion</p>
                    </div>
                    <button v-if="canCreate" @click="showNewSale = true" class="flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-red-700">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nueva Venta
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in sales" :key="sale.id" @click="selectSale(sale.id)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all',
                            selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' :
                            isLockedByOther(sale.id) ? 'ring-1 ring-amber-200 bg-amber-50/30 opacity-75' :
                            'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <div class="flex items-center gap-1.5">
                                <span v-if="isLockedByOther(sale.id)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">En uso por {{ lockedByName(sale.id) }}</span>
                                <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                            </div>
                        </div>
                        <div class="mt-2.5 flex items-end justify-between">
                            <div>
                                <p class="text-xl font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                                <p v-if="parseFloat(sale.amount_pending) > 0" class="mt-0.5 text-xs font-semibold text-amber-600">
                                    Pendiente: ${{ parseFloat(sale.amount_pending).toFixed(2) }}
                                </p>
                                <p v-else class="mt-0.5 text-xs font-semibold text-green-600">Pagada</p>
                            </div>
                            <span class="text-xs text-gray-400">{{ timeAgo(sale.created_at) }}</span>
                        </div>
                    </div>

                    <div v-if="sales.length === 0" class="flex flex-col items-center justify-center py-20 text-center">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay ventas activas</p>
                        <p class="mt-1 text-xs text-gray-400">Las ventas nuevas apareceran aqui.</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Sale detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" /></svg>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona una venta</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                                <span :class="[originBadge(selected.origin), 'rounded-full px-2.5 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                    Ticket
                                </button>
                                <span class="text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Scrollable content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Products -->
                        <div>
                            <h3 class="mb-3 text-sm font-bold text-gray-700">Productos</h3>
                            <div class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                                <table class="min-w-full divide-y divide-gray-50">
                                    <thead><tr class="bg-gray-50">
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Cant.</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Precio</th>
                                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                                    </tr></thead>
                                    <tbody class="divide-y divide-gray-50">
                                        <tr v-for="item in selected.items" :key="item.id">
                                            <td class="px-4 py-2.5 text-sm font-medium text-gray-900">{{ item.product_name }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }} {{ unitLabel(item.unit_type) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Payments -->
                        <div v-if="selected.payments && selected.payments.length > 0">
                            <h3 class="mb-3 text-sm font-bold text-gray-700">Pagos Registrados</h3>
                            <div class="space-y-2">
                                <div v-for="p in selected.payments" :key="p.id" class="rounded-lg bg-gray-50 px-4 py-3">
                                    <!-- Edit mode -->
                                    <form v-if="editingPaymentId === p.id" @submit.prevent="submitEditPayment(p.id)" class="flex items-center gap-3">
                                        <select v-model="editPaymentForm.method" class="rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                            <option v-for="m in enabledMethods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                        </select>
                                        <div class="relative flex-1">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                            <input v-model="editPaymentForm.amount" type="number" step="0.01" min="0.01" required class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                                        </div>
                                        <button type="submit" :disabled="editPaymentForm.processing" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                                        <button type="button" @click="editingPaymentId = null" class="text-xs text-gray-400 hover:text-gray-600">Cancelar</button>
                                    </form>

                                    <!-- Display mode -->
                                    <div v-else class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span :class="methodColor(p.method)" class="text-sm font-semibold">{{ methodLabel(p.method) }}</span>
                                            <span class="text-xs text-gray-400">{{ new Date(p.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) }}</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                            <template v-if="canEditPayments">
                                                <button @click="startEditPayment(p)" class="text-xs font-semibold text-orange-600 hover:text-orange-700">Editar</button>
                                                <button @click="deletePayment(p.id)" class="text-xs text-gray-400 hover:text-red-600">Eliminar</button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Progress -->
                        <div class="rounded-xl bg-gray-50 p-5">
                            <div class="flex items-center justify-between text-sm mb-2">
                                <span class="font-medium text-gray-500">Progreso de cobro</span>
                                <span class="font-bold text-gray-900">{{ Math.round(paidPct(selected)) }}%</span>
                            </div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                                <div class="h-full rounded-full transition-all duration-500" :class="paidPct(selected) >= 100 ? 'bg-green-500' : 'bg-red-500'" :style="{ width: Math.max(paidPct(selected), 2) + '%' }" />
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-4">
                                <div><p class="text-xs text-gray-400">Total</p><p class="text-lg font-bold text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pagado</p><p class="text-lg font-bold text-green-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pendiente</p><p class="text-lg font-bold" :class="hasPending ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p></div>
                            </div>
                        </div>
                    </div>

                    <!-- STICKY FOOTER: Payment form always visible -->
                    <div v-if="hasPending" class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                        <form @submit.prevent="submitPayment" class="space-y-3">
                            <!-- Row 1: method + amount + cobrar -->
                            <div class="flex items-end gap-3">
                                <div class="w-36">
                                    <label class="text-xs font-medium text-gray-500">Metodo</label>
                                    <select v-model="paymentForm.method" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option v-for="m in enabledMethods" :key="m.id" :value="m.id">{{ m.label }}</option>
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <label class="text-xs font-medium text-gray-500">{{ paymentForm.method === 'cash' ? 'Monto recibido' : 'Monto' }}</label>
                                    <div class="relative mt-1">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                        <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" required
                                            :placeholder="pendingAmount.toFixed(2)"
                                            class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                                    </div>
                                </div>
                                <button type="submit" :disabled="paymentForm.processing"
                                    class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                                    Cobrar
                                </button>
                            </div>

                            <!-- Row 2: change calculation (cash only) + cancel -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <span class="text-xs text-gray-400">Pendiente: <span class="font-bold text-amber-600">${{ pendingAmount.toFixed(2) }}</span></span>
                                    <span v-if="paymentForm.method === 'cash' && enteredAmount > pendingAmount" class="text-xs font-bold text-green-600">
                                        Cambio: ${{ changeAmount.toFixed(2) }}
                                    </span>
                                </div>
                                <button v-if="canCancel" type="button" @click="cancelSale" class="text-xs font-semibold text-red-600 transition hover:text-red-700">
                                    Cancelar venta
                                </button>
                            </div>

                            <!-- Errors -->
                            <p v-if="paymentForm.errors.method" class="text-xs text-red-600">{{ paymentForm.errors.method }}</p>
                            <p v-if="paymentForm.errors.amount" class="text-xs text-red-600">{{ paymentForm.errors.amount }}</p>
                        </form>
                    </div>
                </template>
            </div>
        </div>

        <!-- New Sale Modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="showNewSale" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showNewSale = false">
                    <div class="flex h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl" @click.stop>
                        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                            <h2 class="text-base font-bold text-gray-900">Nueva Venta</h2>
                            <button @click="showNewSale = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
                        </div>
                        <div class="flex flex-1 overflow-hidden">
                            <!-- Products -->
                            <div class="flex w-1/2 flex-col border-r border-gray-100">
                                <div class="flex gap-1.5 overflow-x-auto border-b border-gray-100 px-4 py-3">
                                    <button @click="cartCategory = ''" :class="['shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition', !cartCategory ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">Todos</button>
                                    <button v-for="cat in categories" :key="cat.id" @click="cartCategory = cat.id" :class="['shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition', cartCategory == cat.id ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">{{ cat.name }}</button>
                                </div>
                                <div class="flex-1 overflow-y-auto p-4">
                                    <div class="grid grid-cols-2 gap-3">
                                        <template v-for="p in filteredProducts" :key="p.id">
                                            <button v-if="p.sale_mode === 'weight'" @click="addToCart(p)" class="flex items-center gap-3 rounded-xl p-3 text-left ring-1 ring-gray-100 transition hover:bg-gray-50 hover:ring-gray-200">
                                                <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100">
                                                    <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" />
                                                    <svg v-else class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5" /></svg>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-semibold text-gray-900">{{ p.name }}</p>
                                                    <p class="text-xs text-gray-500">${{ parseFloat(p.price).toFixed(2) }}/kg</p>
                                                </div>
                                            </button>
                                            <template v-else>
                                                <button v-for="pres in p.presentations" :key="pres.id" @click="addToCart(p, pres)" class="flex items-center gap-3 rounded-xl p-3 text-left ring-1 ring-orange-100 transition hover:bg-orange-50/50 hover:ring-orange-200">
                                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-orange-50">
                                                        <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" />
                                                        <svg v-else class="h-6 w-6 text-orange-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4" /></svg>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-semibold text-gray-900">{{ p.name }}</p>
                                                        <p class="text-xs text-orange-600">{{ pres.name }} · ${{ parseFloat(pres.price).toFixed(2) }}</p>
                                                    </div>
                                                </button>
                                            </template>
                                        </template>
                                    </div>
                                    <div v-if="filteredProducts.length === 0" class="py-10 text-center text-sm text-gray-400">No hay productos.</div>
                                </div>
                            </div>
                            <!-- Cart -->
                            <div class="flex w-1/2 flex-col">
                                <div class="flex-1 overflow-y-auto p-4">
                                    <p class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">Carrito</p>
                                    <div v-if="cart.length === 0" class="py-10 text-center text-sm text-gray-400">Agrega productos.</div>
                                    <div v-else class="space-y-2">
                                        <div v-for="(item, idx) in cart" :key="idx" class="flex items-center gap-3 rounded-lg bg-gray-50 px-4 py-3">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold text-gray-900">{{ item.name }}</p>
                                                <p class="text-xs text-gray-400">${{ item.price.toFixed(2) }}</p>
                                            </div>
                                            <input v-model.number="item.quantity" type="number" min="0.01" step="0.01" class="w-20 rounded-lg border-gray-200 text-center text-sm focus:border-red-400 focus:ring-red-300" />
                                            <span class="w-20 text-right text-sm font-bold text-gray-900">${{ (item.price * item.quantity).toFixed(2) }}</span>
                                            <button @click="removeFromCart(idx)" class="rounded p-1 text-gray-400 hover:text-red-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="border-t border-gray-100 px-6 py-4">
                                    <div class="flex items-center justify-between mb-4">
                                        <span class="text-sm font-medium text-gray-500">Total</span>
                                        <span class="text-2xl font-bold text-gray-900">${{ cartTotal.toFixed(2) }}</span>
                                    </div>
                                    <button @click="submitNewSale" :disabled="cart.length === 0 || newSaleForm.processing" class="w-full rounded-lg bg-red-600 py-3 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Crear Venta</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name" @close="showTicket = false" />
        <FlashToast />
    </SucursalLayout>
</template>
