<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CajerosContent from '@/Components/Metrics/Content/CajerosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.cajeros');
</script>

<template>
    <Head title="Métricas · Cajeros" />
    <MetricsLayout active-axis="cajeros" axis-label="Cajeros" header-title="Métricas · Cajeros">
        <MetricsHeader title="Cajeros" subtitle="Ventas, cancelaciones y descuentos por cajero." :filters="filters" />
        <CajerosContent :data="data" />
    </MetricsLayout>
</template>
