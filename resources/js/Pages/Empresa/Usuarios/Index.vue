<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ usuarios: Object, sucursales: Array, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');

watch(search, (value) => {
    router.get(route('empresa.usuarios.index', props.tenant.slug), { search: value }, { preserveState: true, replace: true });
});

const roleName = (user) => {
    const role = user.roles?.[0]?.name;
    return { 'admin-sucursal': 'Admin Sucursal', 'cajero': 'Cajero', 'admin-empresa': 'Admin Empresa' }[role] || role;
};
</script>

<template>
    <Head title="Usuarios" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Usuarios</h2>
                <Link :href="route('empresa.usuarios.create', tenant.slug)" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Nuevo Usuario</Link>
            </div>
        </template>
        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <input v-model="search" type="text" placeholder="Buscar usuario..."
                            class="mb-4 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 sm:w-1/3" />
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Nombre</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Email</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Rol</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Sucursal</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="u in usuarios.data" :key="u.id">
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-200">{{ u.name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ u.email }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ roleName(u) }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ u.branch?.name || '—' }}</td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <Link :href="route('empresa.usuarios.edit', [tenant.slug, u.id])" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</Link>
                                    </td>
                                </tr>
                                <tr v-if="usuarios.data.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No se encontraron usuarios.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
