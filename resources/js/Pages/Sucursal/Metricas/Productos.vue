<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ProductosContent from '@/Components/Metrics/Content/ProductosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object, no_movement_days: Number });
const filters = useMetricsFilters('sucursal.metricas.productos');
</script>

<template>
    <Head title="Métricas · Productos" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Productos</h2></template>
        <MetricsHeader title="Productos" subtitle="Top, menos vendidos y alertas" :filters="filters" />
        <ProductosContent :data="data" :no-movement-days="no_movement_days" />
    </SucursalLayout>
</template>
