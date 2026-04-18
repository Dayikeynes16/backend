<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CajerosContent from '@/Components/Metrics/Content/CajerosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.cajeros');
</script>

<template>
    <Head title="Métricas · Cajeros" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Cajeros</h2></template>
        <MetricsHeader title="Cajeros" subtitle="Ventas, cancelaciones y descuentos por cajero" :filters="filters" />
        <CajerosContent :data="data" />
    </SucursalLayout>
</template>
