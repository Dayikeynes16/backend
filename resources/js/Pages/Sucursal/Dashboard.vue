<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    totals: Object,
    topProducts: Array,
    recentShifts: Array,
    pendingCount: Number,
    productCount: Number,
    cajeroCount: Number,
    tenant: Object,
});

const formatDateTime = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head title="Dashboard Sucursal" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Dashboard Sucursal</h2>
        </template>
        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Stats -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Ventas hoy</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">${{ totals.total_sales.toFixed(2) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Transacciones</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ totals.sale_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Pendientes</p>
                        <p class="mt-1 text-2xl font-bold text-orange-600 dark:text-orange-400">{{ pendingCount }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Productos</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ productCount }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Cajeros</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ cajeroCount }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Top products -->
                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div class="p-6">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Productos mas vendidos (hoy)</h3>
                            <div v-if="topProducts.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin ventas hoy.</div>
                            <div v-for="(p, i) in topProducts" :key="i" class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0 dark:border-gray-700">
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-200">{{ p.product_name }}</span>
                                    <span class="ml-2 text-xs text-gray-400">{{ Number(p.total_qty).toFixed(1) }} uds</span>
                                </div>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">${{ Number(p.total_revenue).toFixed(2) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Recent shifts -->
                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Cortes recientes</h3>
                                <Link :href="route('sucursal.cortes.index', tenant.slug)" class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Ver todos</Link>
                            </div>
                            <div v-if="recentShifts.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin cortes registrados.</div>
                            <div v-for="s in recentShifts" :key="s.id" class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0 dark:border-gray-700">
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-200">{{ s.user?.name }}</span>
                                    <span class="ml-2 text-xs text-gray-400">{{ formatDateTime(s.closed_at) }}</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">${{ Number(s.total_sales).toFixed(2) }}</span>
                                    <span class="ml-1 text-xs text-gray-400">({{ s.sale_count }} ventas)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick links -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Link :href="route('sucursal.productos.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Productos</p>
                    </Link>
                    <Link :href="route('sucursal.usuarios.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Cajeros</p>
                    </Link>
                    <Link :href="route('sucursal.api-keys.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">API Keys</p>
                    </Link>
                    <Link :href="route('sucursal.cortes.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Historial Cortes</p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
