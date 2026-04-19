<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ProductosContent from '@/Components/Metrics/Content/ProductosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object, no_movement_days: Number });
const filters = useMetricsFilters('sucursal.metricas.productos');
</script>

<template>
    <Head title="Métricas · Productos" />
    <MetricsLayout active-axis="productos" axis-label="Productos" header-title="Métricas · Productos">
        <MetricsHeader title="Productos" subtitle="Top, menos vendidos y alertas del catálogo." :filters="filters" />
        <ProductosContent :data="data" :no-movement-days="no_movement_days" />
    </MetricsLayout>
</template>
