<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import ProductoCompraFormModal from '@/Components/Compras/ProductoCompraFormModal.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const search = ref(props.filters?.q || '');
const statusFilter = ref(props.filters?.status || 'active');

let t;
const navigate = () => {
    router.get(route('empresa.productos-compra.index', slug.value), {
        q: search.value || undefined,
        status: statusFilter.value !== 'active' ? statusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
watch(search, () => { clearTimeout(t); t = setTimeout(navigate, 300); });
const setStatus = (k) => { statusFilter.value = k; navigate(); };

const formOpen = ref(false);
const editing = ref(null);
const openCreate = () => { editing.value = null; formOpen.value = true; };
const openEdit = (p) => { editing.value = { ...p }; formOpen.value = true; };
const remove = (p) => {
    if (!confirm(`¿Eliminar "${p.name}"?`)) return;
    router.delete(route('empresa.productos-compra.destroy', { tenant: slug.value, producto_compra: p.id }), { preserveScroll: true });
};
const flash = computed(() => page.props.flash || {});
</script>

<template>
    <Head title="Productos de compra" />
    <EmpresaLayout>
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <div class="space-y-5">
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <input v-model="search" type="text" placeholder="Buscar producto…"
                        class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 sm:max-w-sm" />
                    <div class="flex gap-1">
                        <button v-for="k in ['active','inactive','all']" :key="k" @click="setStatus(k)"
                            :class="['rounded-lg px-3 py-2 text-xs font-semibold transition', statusFilter === k ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                            {{ k === 'active' ? 'Activos' : k === 'inactive' ? 'Inactivos' : 'Todos' }}
                        </button>
                    </div>
                </div>
                <button @click="openCreate" class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">+ Nuevo producto</button>
            </div>

            <div v-if="flash.success" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flash.success }}</div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Producto</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Unidad</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Categoría</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Compras</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in products" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="font-semibold text-gray-900">{{ p.name }}</span>
                                <span v-if="p.status === 'inactive'" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">Inactivo</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.unit }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.category_label || '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-gray-900">{{ p.purchase_items_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="openEdit(p)" class="text-sm font-medium text-orange-700 hover:text-orange-900">Editar</button>
                                <button @click="remove(p)" class="ml-3 text-sm font-medium text-red-600 hover:text-red-800">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!products.length">
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500">Sin productos. <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Agregar el primero</button>.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ProductoCompraFormModal :open="formOpen" :product="editing" :categories="categories" @close="formOpen = false" />
    </EmpresaLayout>
</template>
