<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ProductosContent from '@/Components/Metrics/Content/ProductosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object, no_movement_days: Number,
    branches: Array, selected_branch_id: [Number, null], statuses: Array,
});
const filters = useMetricsFilters('empresa.metricas.productos');
</script>

<template>
    <Head title="Métricas · Productos" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Productos</h2></template>
        <MetricsHeader title="Productos" subtitle="Ingreso, ganancia y unidades vendidas por producto." :filters="filters" :branches="branches" :show-branch-selector="true" />
        <!-- Empresa: sin snapshot-route (modal deshabilitado, gestión de productos vive en Sucursal). -->
        <ProductosContent :data="data" :no-movement-days="no_movement_days" :filters="filters" :tenant="tenant" />
    </EmpresaLayout>
</template>
