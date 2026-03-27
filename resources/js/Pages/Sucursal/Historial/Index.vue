<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ sales: Object, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const date = ref(props.filters?.date || '');

let debounce;
const applyFilters = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('sucursal.historial.index', props.tenant.slug), {
            search: search.value || undefined,
            status: status.value || undefined,
            date: date.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};

watch(search, applyFilters);
watch(status, () => { clearTimeout(debounce); applyFilters(); });
watch(date, () => { clearTimeout(debounce); applyFilters(); });

const statusBadge = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada', cls: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });

const originBadge = (o) => o === 'admin' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700';
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const selectedId = ref(null);
const selected = ref(null);

const selectSale = (sale) => {
    selectedId.value = sale.id;
    selected.value = sale;
};
</script>

<template>
    <Head title="Historial de Ventas" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Historial de Ventas</h1>
        </template>

        <div class="flex h-[calc(100vh-8rem)] gap-5">
            <!-- LEFT: Sales list -->
            <div class="flex w-[420px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <!-- Filters -->
                <div class="space-y-3 border-b border-gray-100 px-5 py-4">
                    <div class="flex gap-3">
                        <div class="relative flex-1">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <input v-model="search" type="text" placeholder="Buscar folio..." class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <DatePicker v-model="date" />
                    </div>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'',l:'Todas'},{v:'active',l:'Activas'},{v:'pending',l:'Pendientes'},{v:'completed',l:'Cobradas'},{v:'cancelled',l:'Canceladas'}]"
                            :key="f.v" @click="status = f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', status === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }}
                        </button>
                    </div>
                </div>

                <!-- Sales list -->
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in sales.data" :key="sale.id" @click="selectSale(sale)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all', selectedId === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <span :class="[statusBadge(sale.status).cls, 'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(sale.status).label }}</span>
                        </div>
                        <div class="mt-2 flex items-end justify-between">
                            <div>
                                <p class="text-lg font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                                <span :class="[originBadge(sale.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ sale.origin_name || 'API' }}</span>
                            </div>
                            <span class="text-xs text-gray-400">{{ new Date(sale.created_at).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' }) }}</span>
                        </div>
                    </div>

                    <div v-if="sales.data.length === 0" class="py-16 text-center text-sm text-gray-400">No se encontraron ventas.</div>
                </div>

                <!-- Pagination -->
                <div v-if="sales.last_page > 1" class="flex justify-center border-t border-gray-100 px-4 py-3">
                    <div class="flex gap-1">
                        <Link v-for="link in sales.links" :key="link.label" :href="link.url || '#'"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-medium transition', link.active ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                            v-html="link.label" />
                    </div>
                </div>
            </div>

            <!-- RIGHT: Detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona una venta para ver el detalle</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                            <span :class="[statusBadge(selected.status).cls, 'rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(selected.status).label }}</span>
                            <span :class="[originBadge(selected.origin), 'rounded-full px-2 py-0.5 text-xs font-semibold']">{{ selected.origin_name || 'API' }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX') }}</p>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-6">
                        <!-- Items -->
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
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600">${{ parseFloat(item.unit_price).toFixed(2) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Payments -->
                        <div v-if="selected.payments && selected.payments.length > 0">
                            <h3 class="mb-3 text-sm font-bold text-gray-700">Pagos</h3>
                            <div class="space-y-2">
                                <div v-for="p in selected.payments" :key="p.id" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2.5">
                                    <div>
                                        <span class="text-sm font-semibold" :class="{ 'text-green-600': p.method === 'cash', 'text-blue-600': p.method === 'card', 'text-purple-600': p.method === 'transfer' }">{{ methodLabel(p.method) }}</span>
                                        <span v-if="p.user" class="ml-2 text-xs text-gray-400">por {{ p.user.name }}</span>
                                    </div>
                                    <span class="text-sm font-bold text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="rounded-xl bg-gray-50 p-5">
                            <div class="grid grid-cols-3 gap-4">
                                <div><p class="text-xs text-gray-400">Total</p><p class="text-lg font-bold text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pagado</p><p class="text-lg font-bold text-green-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p></div>
                                <div><p class="text-xs text-gray-400">Pendiente</p><p class="text-lg font-bold" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p></div>
                            </div>
                        </div>

                        <!-- Cancelled info -->
                        <div v-if="selected.status === 'cancelled' && selected.cancelled_at" class="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
                            <p class="text-sm font-semibold text-red-900">Venta cancelada</p>
                            <p class="mt-0.5 text-xs text-red-600/70">{{ new Date(selected.cancelled_at).toLocaleString('es-MX') }}</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </SucursalLayout>
</template>
