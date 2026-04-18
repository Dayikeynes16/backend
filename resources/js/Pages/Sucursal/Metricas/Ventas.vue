<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import VentasContent from '@/Components/Metrics/Content/VentasContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.ventas');
</script>

<template>
    <Head title="Métricas · Ventas" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Ventas</h2></template>
        <MetricsHeader title="Ventas" :subtitle="range?.label" :filters="filters" />
        <VentasContent :data="data" :compare="compare" />
    </SucursalLayout>
</template>
