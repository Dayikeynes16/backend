<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MetricsHubGrid from '@/Components/Metrics/MetricsHubGrid.vue';
import { Head } from '@inertiajs/vue3';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({
    tenant: Object,
    branches: Array,
});

const filters = useMetricsFilters('empresa.metricas.index');
</script>

<template>
    <Head title="Métricas" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas</h2></template>
        <MetricsHeader
            title="Métricas"
            subtitle="Elegí un eje para explorar."
            :filters="filters"
            :branches="branches"
            :show-branch-selector="true"
            show-status-chip
        />
        <MetricsHubGrid scope="empresa" />
    </EmpresaLayout>
</template>
