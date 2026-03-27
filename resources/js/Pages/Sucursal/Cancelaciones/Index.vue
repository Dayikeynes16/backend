<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ requests: Array, tenant: Object });

const cancelReasons = ['Venta duplicada', 'Producto equivocado', 'Cliente no quiso', 'Error de captura'];
const approvingId = ref(null);
const approveForm = useForm({ cancel_reason: '' });
const selectedReason = ref('');

const startApprove = (id) => { approvingId.value = id; selectedReason.value = ''; approveForm.cancel_reason = ''; };
const submitApprove = (saleId) => {
    approveForm.cancel_reason = selectedReason.value;
    approveForm.patch(route('sucursal.cancelaciones.approve', [props.tenant.slug, saleId]), {
        preserveScroll: true,
        onSuccess: () => { approvingId.value = null; },
    });
};

const rejectSale = (saleId) => {
    if (confirm('¿Rechazar esta solicitud de cancelacion?')) {
        approveForm.patch(route('sucursal.cancelaciones.reject', [props.tenant.slug, saleId]), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Solicitudes de Cancelacion" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Solicitudes de Cancelacion</h1>
        </template>

        <div class="mx-auto max-w-4xl space-y-4">
            <div v-if="requests.length === 0" class="rounded-xl bg-white px-6 py-16 text-center shadow-sm ring-1 ring-gray-100">
                <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                <p class="mt-3 text-sm font-medium text-gray-400">No hay solicitudes pendientes.</p>
            </div>

            <div v-for="sale in requests" :key="sale.id" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <span class="text-base font-bold text-gray-900">{{ sale.folio }}</span>
                            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Cancelacion solicitada</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">Total: ${{ parseFloat(sale.total).toFixed(2) }} · {{ new Date(sale.cancel_requested_at).toLocaleString('es-MX') }}</p>
                    </div>
                    <p class="text-sm text-gray-500">Solicitada por: <span class="font-semibold text-gray-900">{{ sale.cancel_requested_by_user?.name || 'Desconocido' }}</span></p>
                </div>

                <div class="px-6 py-4">
                    <p class="text-sm text-gray-700"><span class="font-medium text-gray-500">Motivo:</span> {{ sale.cancel_request_reason }}</p>

                    <!-- Items preview -->
                    <div class="mt-3 space-y-1">
                        <div v-for="item in sale.items" :key="item.id" class="flex justify-between text-xs text-gray-500">
                            <span>{{ item.product_name }} x{{ parseFloat(item.quantity) }}</span>
                            <span>${{ parseFloat(item.subtotal).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Approve form -->
                <div v-if="approvingId === sale.id" class="border-t border-gray-100 px-6 py-4">
                    <p class="mb-3 text-sm font-semibold text-gray-900">Motivo de cancelacion (administrador)</p>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <button v-for="r in cancelReasons" :key="r" type="button" @click="selectedReason = r"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-medium transition', selectedReason === r ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ r }}
                        </button>
                    </div>
                    <div class="flex gap-3">
                        <button @click="submitApprove(sale.id)" :disabled="!selectedReason || approveForm.processing"
                            class="rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Aprobar cancelacion</button>
                        <button @click="approvingId = null" class="text-sm text-gray-500 hover:text-gray-700">Descartar</button>
                    </div>
                </div>

                <!-- Actions -->
                <div v-else class="flex gap-3 border-t border-gray-100 px-6 py-4">
                    <button @click="startApprove(sale.id)" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">Aprobar</button>
                    <button @click="rejectSale(sale.id)" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">Rechazar</button>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
