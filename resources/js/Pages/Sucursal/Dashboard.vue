<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { localToday } from '@/utils/date';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    totals: Object,
    topProducts: Array,
    recentShifts: Array,
    pendingCount: Number,
    cancelRequestCount: Number,
    productCount: Number,
    cajeroCount: Number,
    selectedDate: String,
    tenant: Object,
});

const date = ref(props.selectedDate || localToday());

watch(date, (v) => {
    router.get(route('sucursal.dashboard', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});

const formatDateTime = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head title="Dashboard" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
                <DatePicker v-model="date" />
            </div>
        </template>

        <div class="space-y-8">
            <div class="grid grid-cols-2 gap-5 lg:grid-cols-5">
                <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Ventas</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">${{ totals.total_sales.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-orange-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Transacciones</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ totals.sale_count }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-amber-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Pendientes</p>
                    <p class="mt-1 text-2xl font-bold text-orange-600">{{ pendingCount }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-green-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Productos</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ productCount }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-blue-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Cajeros</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ cajeroCount }}</p>
                </div>
            </div>

            <!-- Cancel requests alert -->
            <div v-if="cancelRequestCount > 0" class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                <svg class="h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-amber-800">{{ cancelRequestCount }} solicitud{{ cancelRequestCount > 1 ? 'es' : '' }} de cancelacion pendiente{{ cancelRequestCount > 1 ? 's' : '' }}</p>
                </div>
                <Link :href="route('sucursal.cancelaciones.index', tenant.slug)" class="text-sm font-semibold text-amber-700 underline hover:text-amber-800">Revisar</Link>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-bold text-gray-900">Productos mas vendidos</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="topProducts.length === 0" class="text-sm text-gray-400">Sin ventas en esta fecha.</div>
                        <div v-for="(p, i) in topProducts" :key="i" class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                            <div>
                                <span class="text-sm font-medium text-gray-900">{{ p.product_name }}</span>
                                <span class="ml-2 text-xs text-gray-400">{{ Number(p.total_qty).toFixed(1) }} uds</span>
                            </div>
                            <span class="text-sm font-semibold text-gray-900">${{ Number(p.total_revenue).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-bold text-gray-900">Cortes recientes</h3>
                        <Link :href="route('sucursal.cortes.index', tenant.slug)" class="text-xs font-semibold text-red-600 hover:text-red-700">Ver todos</Link>
                    </div>
                    <div class="p-6">
                        <div v-if="recentShifts.length === 0" class="text-sm text-gray-400">Sin cortes registrados.</div>
                        <div v-for="s in recentShifts" :key="s.id" class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                            <div>
                                <span class="text-sm font-medium text-gray-900">{{ s.user?.name }}</span>
                                <span class="ml-2 text-xs text-gray-400">{{ formatDateTime(s.closed_at) }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-semibold text-gray-900">${{ Number(s.total_sales).toFixed(2) }}</span>
                                <span class="ml-1 text-xs text-gray-400">({{ s.sale_count }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Link :href="route('sucursal.productos.index', tenant.slug)" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100 text-center transition hover:bg-gray-50">
                    <p class="text-sm font-medium text-red-600">Productos</p>
                </Link>
                <Link :href="route('sucursal.usuarios.index', tenant.slug)" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100 text-center transition hover:bg-gray-50">
                    <p class="text-sm font-medium text-red-600">Cajeros</p>
                </Link>
                <Link :href="route('sucursal.configuracion', tenant.slug)" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100 text-center transition hover:bg-gray-50">
                    <p class="text-sm font-medium text-red-600">Configuracion</p>
                </Link>
                <Link :href="route('sucursal.cortes.index', tenant.slug)" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100 text-center transition hover:bg-gray-50">
                    <p class="text-sm font-medium text-red-600">Cortes</p>
                </Link>
            </div>
        </div>
    </SucursalLayout>
</template>
