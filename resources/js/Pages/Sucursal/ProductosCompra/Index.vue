<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import PurchaseProductsManager from '@/Components/Compras/PurchaseProductsManager.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    products: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    categoryRows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Productos de compra" />
    <SucursalLayout>
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <PurchaseProductsManager
            :products="products"
            :filters="filters"
            :categories="categories"
            :category-rows="categoryRows"
            :stats="stats"
            route-prefix="sucursal"
            :can-delete="false"
        />
    </SucursalLayout>
</template>
