<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CancelacionesContent from '@/Components/Metrics/Content/CancelacionesContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({
    data: Object,
    history: Object,
    range: Object,
    tenant: Object,
});
const filters = useMetricsFilters('sucursal.metricas.cancelaciones');
</script>

<template>
    <Head title="Métricas · Cancelaciones" />
    <MetricsLayout active-axis="cancelaciones" axis-label="Cancelaciones" header-title="Métricas · Cancelaciones">
        <MetricsHeader title="Cancelaciones"
            subtitle="Ventas canceladas, motivos y tiempo de respuesta en el rango."
            :filters="filters" />
        <CancelacionesContent :data="data" :history="history" :range="range" scope="sucursal" />
    </MetricsLayout>
</template>
