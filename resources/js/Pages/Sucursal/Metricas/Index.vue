<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MetricsHubGrid from '@/Components/Metrics/MetricsHubGrid.vue';
import { Head } from '@inertiajs/vue3';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({ tenant: Object });

const filters = useMetricsFilters('sucursal.metricas.index');
</script>

<template>
    <Head title="Métricas" />
    <MetricsLayout>
        <MetricsHeader
            title="Métricas"
            subtitle="Elegí un eje para explorar."
            :filters="filters"
            show-status-chip
        />
        <MetricsHubGrid scope="sucursal" />
    </MetricsLayout>
</template>
