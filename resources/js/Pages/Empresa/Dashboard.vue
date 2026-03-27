<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    totals: Object,
    branches: Array,
    branchCount: Number,
    userCount: Number,
    pendingCount: Number,
    tenant: Object,
});
</script>

<template>
    <Head title="Dashboard Empresa" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Dashboard Empresa</h2>
        </template>
        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Stats -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Ventas hoy (total)</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">${{ totals.total_sales.toFixed(2) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Transacciones</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ totals.sale_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Sucursales</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ branchCount }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Usuarios</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ userCount }}</p>
                    </div>
                </div>

                <!-- Desglose por método -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Desglose del dia</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                                <p class="text-sm text-green-600 dark:text-green-400">Efectivo</p>
                                <p class="mt-1 text-xl font-bold text-green-700 dark:text-green-300">${{ totals.total_cash.toFixed(2) }}</p>
                            </div>
                            <div class="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                                <p class="text-sm text-blue-600 dark:text-blue-400">Tarjeta</p>
                                <p class="mt-1 text-xl font-bold text-blue-700 dark:text-blue-300">${{ totals.total_card.toFixed(2) }}</p>
                            </div>
                            <div class="rounded-lg bg-purple-50 p-4 dark:bg-purple-900/20">
                                <p class="text-sm text-purple-600 dark:text-purple-400">Transferencia</p>
                                <p class="mt-1 text-xl font-bold text-purple-700 dark:text-purple-300">${{ totals.total_transfer.toFixed(2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sales by branch -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Ventas por sucursal (hoy)</h3>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Sucursal</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Estado</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Ventas</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="b in branches" :key="b.id">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-200">{{ b.name }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span :class="b.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold">
                                            {{ b.status === 'active' ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-200">{{ b.sale_count }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">${{ b.total.toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick links -->
                <div class="grid grid-cols-2 gap-4">
                    <Link :href="route('empresa.sucursales.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Gestionar Sucursales</p>
                    </Link>
                    <Link :href="route('empresa.usuarios.index', tenant.slug)" class="rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700 text-center">
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Gestionar Usuarios</p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
