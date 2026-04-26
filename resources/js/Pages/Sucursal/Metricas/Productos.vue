<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ProductosContent from '@/Components/Metrics/Content/ProductosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object, no_movement_days: Number, statuses: Array });
const filters = useMetricsFilters('sucursal.metricas.productos');
</script>

<template>
    <Head title="Métricas · Productos" />
    <MetricsLayout active-axis="productos" axis-label="Productos" header-title="Métricas · Productos">
        <MetricsHeader title="Productos" subtitle="Ingreso, ganancia y unidades vendidas por producto." :filters="filters" />
        <ProductosContent :data="data" :no-movement-days="no_movement_days" :filters="filters" :tenant="tenant" snapshot-route="sucursal.productos.snapshot" />
    </MetricsLayout>
</template>
