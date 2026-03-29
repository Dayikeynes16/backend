<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    requests: Array,
    stats: Object,
    topReasons: Array,
    history: Array,
    filters: Object,
    tenant: Object,
});

const date = ref(props.filters?.date || '');
watch(date, (v) => {
    router.get(route('sucursal.cancelaciones.index', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});

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

const formatDT = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const formatTime = (iso) => iso ? new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '—';

// Active tab
const activeTab = ref('requests');
</script>

<template>
    <Head title="Cancelaciones" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Cancelaciones</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <!-- Stats cards -->
            <div class="grid grid-cols-2 gap-5 lg:grid-cols-3">
                <div class="rounded-xl border-l-4 border-amber-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Solicitudes pendientes</p>
                    <p class="mt-1 text-2xl font-bold text-amber-600">{{ requests.length }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Canceladas hoy</p>
                    <p class="mt-1 text-2xl font-bold text-red-600">{{ stats.cancelled_count }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-gray-400 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Total cancelado hoy</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">${{ stats.cancelled_total.toFixed(2) }}</p>
                </div>
            </div>

            <!-- Top reasons (last 30 days) -->
            <div v-if="topReasons.length > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Motivos frecuentes <span class="font-normal text-gray-400">(ultimos 30 dias)</span></h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <div v-for="reason in topReasons" :key="reason.cancel_reason" class="flex items-center justify-between px-6 py-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-red-50 text-xs font-bold text-red-600">{{ reason.count }}</div>
                            <span class="text-sm font-medium text-gray-900">{{ reason.cancel_reason }}</span>
                        </div>
                        <span class="text-sm text-gray-500">${{ parseFloat(reason.total).toFixed(2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Tabs: Pendientes / Historial -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6">
                    <div class="flex gap-6">
                        <button @click="activeTab = 'requests'"
                            :class="['relative py-4 text-sm font-semibold transition', activeTab === 'requests' ? 'text-red-600' : 'text-gray-400 hover:text-gray-600']">
                            Pendientes
                            <span v-if="requests.length > 0" class="ml-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">{{ requests.length }}</span>
                            <div v-if="activeTab === 'requests'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-red-600 rounded-full" />
                        </button>
                        <button @click="activeTab = 'history'"
                            :class="['relative py-4 text-sm font-semibold transition', activeTab === 'history' ? 'text-red-600' : 'text-gray-400 hover:text-gray-600']">
                            Historial
                            <div v-if="activeTab === 'history'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-red-600 rounded-full" />
                        </button>
                    </div>
                    <div v-if="activeTab === 'history'">
                        <DatePicker v-model="date" :allow-future="false" />
                    </div>
                </div>

                <!-- TAB: Pending requests -->
                <div v-if="activeTab === 'requests'">
                    <div v-if="requests.length === 0" class="px-6 py-16 text-center">
                        <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay solicitudes pendientes.</p>
                    </div>

                    <div v-for="sale in requests" :key="sale.id" class="border-b border-gray-50 last:border-0">
                        <div class="flex items-center justify-between px-6 py-4">
                            <div>
                                <div class="flex items-center gap-3">
                                    <span class="text-base font-bold text-gray-900">{{ sale.folio }}</span>
                                    <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Cancelacion solicitada</span>
                            <span v-if="sale.status === 'completed'" class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Venta cobrada</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-400">Total: ${{ parseFloat(sale.total).toFixed(2) }} · {{ formatDT(sale.cancel_requested_at) }}</p>
                            </div>
                            <p class="text-sm text-gray-500">Solicitada por: <span class="font-semibold text-gray-900">{{ sale.cancel_requested_by_user?.name || 'Desconocido' }}</span></p>
                        </div>

                        <div class="px-6 pb-4">
                            <p class="text-sm text-gray-700"><span class="font-medium text-gray-500">Motivo:</span> {{ sale.cancel_request_reason }}</p>
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

                <!-- TAB: History -->
                <div v-if="activeTab === 'history'">
                    <div v-if="history.length === 0" class="px-6 py-16 text-center">
                        <p class="text-sm text-gray-400">No hay cancelaciones para esta fecha.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table v-if="history.length > 0" class="min-w-full divide-y divide-gray-100">
                            <thead><tr class="bg-gray-50">
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Folio</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Hora</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Motivo</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Solicito</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Aprobo</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="sale in history" :key="sale.id" class="transition hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm font-bold text-gray-900">{{ sale.folio }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-500">{{ formatTime(sale.cancelled_at) }}</td>
                                    <td class="px-6 py-3 text-right text-sm font-semibold text-red-600">${{ parseFloat(sale.total).toFixed(2) }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-700">{{ sale.cancel_reason || sale.cancel_request_reason || '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-500">{{ sale.cancel_requested_by_user?.name || '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-500">{{ sale.cancelled_by_user?.name || '—' }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gray-50">
                                    <td class="px-6 py-3 text-sm font-bold text-gray-900" colspan="2">Total del dia</td>
                                    <td class="px-6 py-3 text-right text-sm font-bold text-red-600">${{ stats.cancelled_total.toFixed(2) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
