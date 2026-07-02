<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
import PurchaseProductsManager from '@/Components/Compras/PurchaseProductsManager.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    products: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    categoryRows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head title="Productos de compra" />
    <EmpresaLayout>
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <ComprasTabs prefix="empresa" class="mb-5" />

        <PurchaseProductsManager
            :products="products"
            :filters="filters"
            :categories="categories"
            :category-rows="categoryRows"
            :stats="stats"
            route-prefix="empresa"
            :can-delete="true"
        />
    </EmpresaLayout>
</template>
