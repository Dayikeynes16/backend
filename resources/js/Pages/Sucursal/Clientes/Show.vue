<script setup>
import { computed, onMounted, ref, toRef } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CustomerHero from '@/Components/Clientes/CustomerHero.vue';
import CustomerPreferentialPrices from '@/Components/Clientes/CustomerPreferentialPrices.vue';
import CustomerPaymentModal from '@/Components/Clientes/CustomerPaymentModal.vue';
import CustomerPurchasesTab from '@/Components/Clientes/CustomerPurchasesTab.vue';
import CustomerProductsTab from '@/Components/Clientes/CustomerProductsTab.vue';
import CustomerFinancesTab from '@/Components/Clientes/CustomerFinancesTab.vue';
import { useCustomerStats } from '@/composables/useCustomerStats';

const props = defineProps({
    customer: { type: Object, required: true },
    statsSeed: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    tenant: { type: Object, required: true },
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

const customerRef = toRef(props, 'customer');

const {
    stats, history, topProducts, payments,
    loading, errors,
    loadStats, loadHistory, loadTopProducts, loadPayments,
} = useCustomerStats(customerRef, props.tenant.slug);

// Tabs
const activeTab = ref('purchases'); // 'purchases' | 'products' | 'finances'
const tabs = [
    { id: 'purchases', label: 'Compras' },
    { id: 'products', label: 'Productos' },
    { id: 'finances', label: 'Finanzas' },
];

onMounted(() => {
    // Carga stats reales en background; el seed mantiene la pantalla útil mientras.
    loadStats();
    // Pagos también: los necesitamos para saber si hay deuda y mostrar el modal de cobro.
    loadPayments();
});

// --- Cobro global ---
const showPaymentModal = ref(false);
const canRegisterPayment = computed(() => {
    const pendingCount = stats.value?.pending_sales_count
        ?? props.statsSeed.pending_sales_count
        ?? 0;
    const shiftOpen = stats.value?.current_user_shift_open ?? false;

    return pendingCount > 0 && shiftOpen;
});
const paymentDisabledReason = computed(() => {
    const pendingCount = stats.value?.pending_sales_count
        ?? props.statsSeed.pending_sales_count
        ?? 0;
    if (pendingCount === 0) return 'Este cliente no tiene ventas pendientes.';
    const shiftOpen = stats.value?.current_user_shift_open ?? false;
    if (!shiftOpen) return 'Necesitas un turno abierto para registrar cobros.';

    return '';
});

const onRegisterPayment = () => {
    if (!canRegisterPayment.value) return;
    showPaymentModal.value = true;
};
const onPaymentSuccess = () => {
    showPaymentModal.value = false;
    // Recarga stats y la página entera (para que sales del customer reflejen).
    router.reload({ only: ['customer', 'statsSeed'], preserveScroll: true });
};

// --- Editar / status / eliminar (delegan en el modal global de Index, o al endpoint) ---
const showEdit = ref(false);
const deleteConfirm = ref(false);

const onEdit = () => { showEdit.value = true; };
const onToggleStatus = () => {
    const next = props.customer.status === 'active' ? 'inactive' : 'active';
    router.put(
        route('sucursal.clientes.update', [props.tenant.slug, props.customer.id]),
        { name: props.customer.name, phone: props.customer.phone, notes: props.customer.notes, status: next },
        { preserveScroll: true, preserveState: false },
    );
};
const onDestroy = () => { deleteConfirm.value = true; };
const confirmDestroy = () => {
    router.delete(
        route('sucursal.clientes.destroy', [props.tenant.slug, props.customer.id]),
        {
            onFinish: () => { deleteConfirm.value = false; },
            onSuccess: () => router.visit(route('sucursal.clientes.index', props.tenant.slug)),
        },
    );
};

// --- Edición simple inline ---
const editForm = ref({ name: '', phone: '', notes: '' });
const editError = ref('');
const editProcessing = ref(false);
const openEdit = () => {
    editForm.value = { name: props.customer.name, phone: props.customer.phone, notes: props.customer.notes || '' };
    editError.value = '';
    showEdit.value = true;
};
const submitEdit = () => {
    editProcessing.value = true;
    editError.value = '';
    router.put(
        route('sucursal.clientes.update', [props.tenant.slug, props.customer.id]),
        { ...editForm.value, status: props.customer.status },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { showEdit.value = false; },
            onError: (errs) => { editError.value = errs.name || errs.phone || errs.notes || 'No se pudo actualizar.'; },
            onFinish: () => { editProcessing.value = false; },
        },
    );
};
</script>

<template>
    <Head :title="customer.name" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Cliente</h1>
            </div>
        </template>

        <div class="space-y-5">
            <CustomerHero
                :customer="customer"
                :tenant-slug="tenant.slug"
                :stats-seed="statsSeed"
                :stats="stats"
                :can-register-payment="canRegisterPayment"
                :payment-disabled-reason="paymentDisabledReason"
                @register-payment="onRegisterPayment"
                @edit="openEdit"
                @toggle-status="onToggleStatus"
                @destroy="onDestroy" />

            <CustomerPreferentialPrices
                :tenant-slug="tenant.slug"
                :customer="customer"
                :products="products" />

            <!-- Tabs -->
            <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-2">
                    <nav class="flex gap-1 overflow-x-auto" role="tablist">
                        <button v-for="tab in tabs" :key="tab.id" type="button" @click="activeTab = tab.id"
                            :class="['relative whitespace-nowrap px-4 py-3 text-sm font-semibold transition',
                                activeTab === tab.id ? 'text-red-600' : 'text-gray-500 hover:text-gray-700']">
                            {{ tab.label }}
                            <span v-if="activeTab === tab.id" class="absolute bottom-0 left-0 right-0 h-0.5 rounded-full bg-red-600"></span>
                        </button>
                    </nav>
                </div>
                <div class="p-5">
                    <CustomerPurchasesTab v-if="activeTab === 'purchases'"
                        :customer-id="customer.id"
                        :tenant-slug="tenant.slug"
                        :history="history"
                        :loading="loading.history"
                        :error="errors.history"
                        @load="(params) => loadHistory(params)" />
                    <CustomerProductsTab v-else-if="activeTab === 'products'"
                        :top-products="topProducts"
                        :loading="loading.topProducts"
                        :error="errors.topProducts"
                        @load="(limit) => loadTopProducts(limit)" />
                    <CustomerFinancesTab v-else-if="activeTab === 'finances'"
                        :customer-id="customer.id"
                        :tenant-slug="tenant.slug"
                        :payments="payments"
                        :loading="loading.payments"
                        :error="errors.payments"
                        :can-register-payment="canRegisterPayment"
                        :payment-disabled-reason="paymentDisabledReason"
                        @load="() => loadPayments()"
                        @register-payment="onRegisterPayment" />
                </div>
            </section>
        </div>

        <!-- Modal de cobro global -->
        <CustomerPaymentModal
            :show="showPaymentModal"
            :tenant-slug="tenant.slug"
            :customer="customer"
            :pending-sales="payments?.pending_sales || []"
            :allowed-methods="allowedPaymentMethods"
            :shift-open="stats?.current_user_shift_open ?? true"
            @close="showPaymentModal = false"
            @success="onPaymentSuccess" />

        <!-- Modal de edición simple -->
        <div v-if="showEdit" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="showEdit = false">
            <div class="w-full max-w-md rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">Editar cliente</h3>
                    <button @click="showEdit = false" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <form @submit.prevent="submitEdit" class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre</label>
                        <input v-model="editForm.name" type="text" required maxlength="255"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Teléfono</label>
                        <input v-model="editForm.phone" type="tel" required maxlength="20"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Notas</label>
                        <textarea v-model="editForm.notes" rows="2" maxlength="1000"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <p v-if="editError" class="text-xs text-red-600">{{ editError }}</p>
                </form>
                <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                    <button type="button" @click="showEdit = false" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                    <button type="button" @click="submitEdit" :disabled="editProcessing" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                        {{ editProcessing ? 'Guardando…' : 'Guardar cambios' }}
                    </button>
                </div>
            </div>
        </div>

        <ConfirmDialog v-if="deleteConfirm"
            title="Eliminar cliente"
            :message="`Vas a eliminar a ${customer.name}. Si tiene ventas registradas se marcará como inactivo en su lugar.`"
            confirm-label="Eliminar"
            variant="danger"
            @confirm="confirmDestroy"
            @cancel="deleteConfirm = false" />

        <FlashToast />
    </SucursalLayout>
</template>
