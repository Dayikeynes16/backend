<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    tenants: Array,
    stats: Object,
});
</script>

<template>
    <Head title="Panel Superadmin" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Panel Superadmin</h2>
                <Link :href="route('admin.empresas.create')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Nueva Empresa
                </Link>
            </div>
        </template>
        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Global stats -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Empresas</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ stats.tenant_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Sucursales</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ stats.branch_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Usuarios</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ stats.user_count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Ventas hoy</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ stats.sale_count_today }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Ingresos hoy</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">${{ stats.sales_today.toFixed(2) }}</p>
                    </div>
                </div>

                <!-- Tenants table -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Empresas</h3>
                            <Link :href="route('admin.empresas.index')" class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Ver todas</Link>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Empresa</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Estado</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Sucursales</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Usuarios</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Ventas hoy</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="t in tenants" :key="t.id">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-200">{{ t.name }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span :class="t.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold">
                                            {{ t.status === 'active' ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-200">{{ t.branches_count }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-200">{{ t.users_count }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">${{ t.sales_today.toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <Link :href="route('admin.empresas.edit', t.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</Link>
                                    </td>
                                </tr>
                                <tr v-if="tenants.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No hay empresas registradas.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
