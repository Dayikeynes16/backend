<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import SaleContextMenu from '@/Components/SaleContextMenu.vue';
import CancelSaleDialog from '@/Components/CancelSaleDialog.vue';
import SaleDetail from '@/Components/Caja/SaleDetail.vue';
import SaleDetailModalShell from '@/Components/SaleDetailModalShell.vue';
import { useSaleLock } from '@/composables/useSaleLock';
import { useSaleQueue } from '@/composables/useSaleQueue';
import { useSaleActions } from '@/composables/useSaleActions';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({ sales: Array, tenant: Object, branchId: Number, branchInfo: Object, paymentMethods: Array, customers: Array });

const statusFilter = ref('active');
const filteredSales = computed(() => {
    if (statusFilter.value === 'all') return props.sales;
    return props.sales.filter(s => s.status === statusFilter.value);
});
const counts = computed(() => ({
    active: props.sales.filter(s => s.status === 'active').length,
    pending: props.sales.filter(s => s.status === 'pending').length,
    all: props.sales.length,
}));

// Venta seleccionada → su detalle se abre en un modal grande.
const selectedId = ref(null);
const selected = computed(() => props.sales.find(s => s.id === selectedId.value) || null);
const showDetail = computed(() => !!selected.value);
const showCancelRequest = ref(false);
const detailDirty = ref(false); // monto capturado sin cobrar → confirmar antes de cerrar el modal

// Real-time updates (same as Sucursal)
const { sales: queuedSales } = useSaleQueue(props.branchId);
watch(queuedSales, () => router.reload({ only: ['sales'], preserveScroll: true }), { deep: true });

let saleUpdateChannel = null;
onMounted(() => {
    if (!props.branchId || !window.Echo) return;
    saleUpdateChannel = window.Echo.private(`sucursal.${props.branchId}`);
    saleUpdateChannel.listen('SaleUpdated', () => router.reload({ only: ['sales'], preserveScroll: true }));
});
onUnmounted(() => { if (saleUpdateChannel) saleUpdateChannel.stopListening('SaleUpdated'); });

// Concurrency lock
const { lockSale, unlockSale, isLockedByOther, lockedByName } = useSaleLock(
    props.branchId,
    usePage().props.auth.user.id,
    route('caja.sale.lock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.unlock', [props.tenant.slug, '__SALE__']),
    route('caja.sale.heartbeat', [props.tenant.slug, '__SALE__']),
);

// Status actions (pause/reactivate only)
const { pauseSale, reactivateSale } = useSaleActions(
    route('caja.update-status', [props.tenant.slug, '__SALE__']),
);

// Seleccionar una venta: la bloquea y abre el modal de detalle.
const selectSale = async (saleId) => {
    const ok = await lockSale(saleId);
    if (ok) {
        selectedId.value = saleId;
        showCancelRequest.value = false;
    }
};

// Cerrar el modal: libera el bloqueo y limpia la selección.
const closeDetail = () => {
    unlockSale();
    selectedId.value = null;
    showCancelRequest.value = false;
};

// Acciones rápidas desde la lista: bloquean la venta pero no abren el modal.
const handlePause = async (saleId) => {
    const ok = await lockSale(saleId);
    if (!ok) return;
    pauseSale(saleId, { onSuccess: () => { statusFilter.value = 'pending'; } });
};
const handleReactivate = async (saleId) => {
    const ok = await lockSale(saleId);
    if (!ok) return;
    reactivateSale(saleId, { onSuccess: () => { statusFilter.value = 'active'; } });
};
// Solicitar cancelación desde la lista: abre el modal + el diálogo de motivo.
const requestCancelFromList = async (saleId) => {
    await selectSale(saleId);
    showCancelRequest.value = true;
};

// Recargar ventas tras una mutación dentro del modal (cobro, cliente, vínculo, teléfono…).
const reloadSales = () => router.reload({ only: ['sales'], preserveScroll: true });

// Helpers (lista)
const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const timeAgo = (d) => { const diff = Math.floor((Date.now() - new Date(d)) / 1000); if (diff < 60) return 'ahora'; if (diff < 3600) return `${Math.floor(diff / 60)}m`; return `${Math.floor(diff / 3600)}h`; };

// Cancel request
const cancelProcessing = ref(false);
const submitCancelRequest = (reason) => {
    if (!selected.value) return;
    cancelProcessing.value = true;
    router.post(route('caja.request-cancel', [props.tenant.slug, selected.value.id]), { cancel_request_reason: reason }, {
        preserveScroll: true,
        onSuccess: () => { showCancelRequest.value = false; },
        onFinish: () => { cancelProcessing.value = false; },
    });
};
</script>

<template>
    <Head title="Mesa de Trabajo" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mesa de Trabajo</h1></template>

        <div class="h-[calc(100vh-7rem)]">
            <!-- Lista de ventas (ancho completo) -->
            <div class="flex h-full flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Ventas</h2>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'active',l:'Activas'},{v:'pending',l:'Pendientes'},{v:'all',l:'Todas'}]"
                            :key="f.v" @click="statusFilter = f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', statusFilter === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }} ({{ counts[f.v] }})
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        <div v-for="sale in filteredSales" :key="sale.id" @click="selectSale(sale.id)"
                            :class="['cursor-pointer rounded-xl p-4 transition-all',
                                selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' :
                                isLockedByOther(sale.id) ? 'ring-1 ring-amber-200 bg-amber-50/30 opacity-75' :
                                sale.status === 'pending' ? 'ring-1 ring-amber-200 bg-amber-50/20' :
                                'ring-1 ring-gray-100 hover:ring-gray-200 hover:shadow-sm']">
                            <div class="flex items-center justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                                    <span v-if="sale.status === 'pending' && sale.origin === 'web'" class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800 ring-1 ring-inset ring-orange-600/30">🛒 Pedido web</span>
                                    <span v-else-if="sale.status === 'pending'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pendiente</span>
                                    <span v-if="sale.linked_order_id && sale.linked_order"
                                        class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/30"
                                        :title="`Vinculada al pedido web ${sale.linked_order.folio}`">
                                        🔗 {{ sale.linked_order.folio }}
                                    </span>
                                    <span v-else-if="sale.origin === 'web' && sale.status === 'fulfilled'"
                                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/30"
                                        :title="sale.fulfilled_by ? `Cumplido por venta ${sale.fulfilled_by.folio}` : 'Pedido cumplido'">
                                        ✓ Cumplido<span v-if="sale.fulfilled_by"> · {{ sale.fulfilled_by.folio }}</span>
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span v-if="isLockedByOther(sale.id)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                        {{ lockedByName(sale.id) || 'En uso' }}
                                    </span>
                                    <span v-else-if="sale.locked_by_user" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">
                                        {{ sale.locked_by_user.name }}
                                    </span>
                                    <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                                    <SaleContextMenu
                                        :sale="sale"
                                        :allowed-actions="['pause', 'reactivate', 'request-cancel']"
                                        :is-locked-by-other="isLockedByOther(sale.id)"
                                        :locked-by-name="lockedByName(sale.id)"
                                        @pause="handlePause(sale.id)"
                                        @reactivate="handleReactivate(sale.id)"
                                        @request-cancel="requestCancelFromList(sale.id)"
                                    />
                                </div>
                            </div>
                            <div class="mt-2.5 flex items-end justify-between">
                                <div>
                                    <p class="text-xl font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                                    <p v-if="parseFloat(sale.amount_pending) > 0" class="mt-0.5 text-xs font-semibold text-amber-600">Pendiente: ${{ parseFloat(sale.amount_pending).toFixed(2) }}</p>
                                    <p v-else class="mt-0.5 text-xs font-semibold text-green-600">Pagada</p>
                                </div>
                                <span class="text-xs text-gray-400">{{ timeAgo(sale.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="filteredSales.length === 0" class="flex flex-col items-center py-24 text-center">
                        <svg class="h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay ventas {{ statusFilter === 'pending' ? 'pendientes' : statusFilter === 'active' ? 'activas' : '' }}</p>
                        <p class="mt-1 text-xs text-gray-400">Las ventas nuevas aparecerán aquí automáticamente.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle de venta en modal grande -->
        <SaleDetailModalShell :show="showDetail" :dirty="detailDirty" @close="closeDetail">
            <template #default="{ requestClose }">
                <SaleDetail v-if="selected"
                    :key="selected.id"
                    :sale="selected"
                    :tenant-slug="tenant.slug"
                    :tenant="tenant"
                    :branch-info="branchInfo"
                    :payment-methods="paymentMethods"
                    :customers="customers"
                    :is-locked-by-other="isLockedByOther(selected.id)"
                    :locked-by-name="lockedByName(selected.id) || ''"
                    @update:dirty="detailDirty = $event"
                    @close="requestClose"
                    @paid="reloadSales"
                    @mutated="reloadSales"
                    @pause="handlePause"
                    @reactivate="handleReactivate"
                    @request-cancel="showCancelRequest = true" />
            </template>
        </SaleDetailModalShell>

        <CancelSaleDialog v-if="showCancelRequest" :folio="selected?.folio" mode="request" :processing="cancelProcessing" @confirm="submitCancelRequest" @cancel="showCancelRequest = false" />

        <FlashToast />
    </CajeroLayout>
</template>
