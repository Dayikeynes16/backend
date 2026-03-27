<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({ productos: Object, filters: Object, tenant: Object });

const search = ref(props.filters?.search || '');
const unitType = ref(props.filters?.unit_type || '');

const applyFilters = () => {
    router.get(route('sucursal.productos.index', props.tenant.slug), {
        search: search.value,
        unit_type: unitType.value,
    }, { preserveState: true, replace: true });
};

watch(search, applyFilters);
watch(unitType, applyFilters);

const unitLabel = (type) => ({ kg: 'Kilogramo', piece: 'Pieza', cut: 'Corte' }[type] || type);
</script>

<template>
    <Head title="Productos" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Productos</h2>
                <Link :href="route('sucursal.productos.create', tenant.slug)" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Nuevo Producto
                </Link>
            </div>
        </template>
        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <div class="mb-4 flex gap-4">
                            <input v-model="search" type="text" placeholder="Buscar producto..."
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 sm:w-1/3" />
                            <select v-model="unitType"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                <option value="">Todos los tipos</option>
                                <option value="kg">Kilogramo</option>
                                <option value="piece">Pieza</option>
                                <option value="cut">Corte</option>
                            </select>
                        </div>

                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Nombre</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Tipo</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Precio</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Estado</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="p in productos.data" :key="p.id">
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-200">{{ p.name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ unitLabel(p.unit_type) }}</td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-200">${{ Number(p.price).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span :class="p.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold">
                                            {{ p.status === 'active' ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <Link :href="route('sucursal.productos.edit', [tenant.slug, p.id])" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Editar</Link>
                                    </td>
                                </tr>
                                <tr v-if="productos.data.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No se encontraron productos.</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="productos.last_page > 1" class="mt-4 flex justify-center gap-2">
                            <Link v-for="link in productos.links" :key="link.label" :href="link.url || '#'"
                                :class="[
                                    'rounded px-3 py-1 text-sm',
                                    link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700',
                                    !link.url && 'pointer-events-none opacity-50',
                                ]" v-html="link.label" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
