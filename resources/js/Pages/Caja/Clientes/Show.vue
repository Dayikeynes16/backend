<script setup>
import { computed, onMounted, ref, toRef } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CustomerHero from '@/Components/Clientes/CustomerHero.vue';
import CustomerPaymentModal from '@/Components/Clientes/CustomerPaymentModal.vue';
import CustomerFinancesTab from '@/Components/Clientes/CustomerFinancesTab.vue';
import { useCustomerStats } from '@/composables/useCustomerStats';

/**
 * Ficha del cliente vista desde Caja. Misma pantalla que la de sucursal menos
 * lo que no le toca al cajero:
 *  - sin sección de precios preferenciales (los descuentos son del admin),
 *  - sin edición de items en el detalle de venta,
 *  - sin cancelar cobros ni dar de baja al cliente.
 */
const props = defineProps({
    customer: { type: Object, required: true },
    statsSeed: { type: Object, required: true },
    tenant: { type: Object, required: true },
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    paymentReceiptsEnabled: { type: Boolean, default: false },
    paymentReceiptsRequired: { type: Boolean, default: false },
});

const customerRef = toRef(props, 'customer');

const {
    stats, payments,
    loading, errors,
    loadStats, loadPayments,
} = useCustomerStats(customerRef, props.tenant.slug, 'caja');

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
const financesTab = ref(null);
const onPaymentSuccess = () => {
    showPaymentModal.value = false;
    // Recargar sólo las props de Inertia no basta: la deuda, los pagos y las
    // ventas de la ficha salen de peticiones propias (`useCustomerStats` y la
    // pestaña), y el cliente sigue siendo el mismo, así que nada las repetía.
    financesTab.value?.refresh();
    router.reload({ only: ['customer', 'statsSeed'], preserveScroll: true });
};

// --- Edición de datos de contacto ---
const showEdit = ref(false);
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
        route('caja.clientes.update', [props.tenant.slug, props.customer.id]),
        { ...editForm.value },
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
    <CajeroLayout>
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
                route-prefix="caja"
                :can-manage-status="false"
                @register-payment="onRegisterPayment"
                @edit="openEdit" />

            <CustomerFinancesTab
                ref="financesTab"
                :customer-id="customer.id"
                :tenant-slug="tenant.slug"
                :payments="payments"
                :stats="stats"
                :stats-seed="statsSeed"
                :loading="loading.payments"
                :error="errors.payments"
                :can-register-payment="canRegisterPayment"
                :payment-disabled-reason="paymentDisabledReason"
                :allowed-payment-methods="allowedPaymentMethods"
                :payment-receipts-enabled="paymentReceiptsEnabled"
                route-prefix="caja"
                :allow-item-edits="false"
                :can-cancel-payment="false"
                @load="() => loadPayments()"
                @changed="() => loadStats()"
                @register-payment="onRegisterPayment" />
        </div>

        <!-- Modal de cobro global -->
        <CustomerPaymentModal
            :show="showPaymentModal"
            :tenant-slug="tenant.slug"
            :customer="customer"
            :pending-sales="payments?.pending_sales || []"
            :allowed-methods="allowedPaymentMethods"
            :shift-open="stats?.current_user_shift_open ?? true"
            :receipts-enabled="paymentReceiptsEnabled"
            :receipts-required="paymentReceiptsRequired"
            route-prefix="caja"
            @close="showPaymentModal = false"
            @success="onPaymentSuccess" />

        <!-- Modal de edición de datos de contacto -->
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

        <FlashToast />
    </CajeroLayout>
</template>
