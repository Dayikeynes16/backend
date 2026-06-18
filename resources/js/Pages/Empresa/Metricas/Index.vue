<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MetricsHubGrid from '@/Components/Metrics/MetricsHubGrid.vue';
import ResumenContent from '@/Components/Metrics/Content/ResumenContent.vue';
import { Head } from '@inertiajs/vue3';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({
    tenant: Object,
    branches: { type: Array, default: () => [] },
    data: { type: Object, default: null },
});

const filters = useMetricsFilters('empresa.metricas.index');
</script>

<template>
    <Head title="Métricas · Resumen" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas</h2></template>
        <MetricsHeader
            title="Resumen"
            subtitle="Panorama del negocio"
            :filters="filters"
            :branches="branches"
            :show-branch-selector="true"
            show-status-chip
        />
        <ResumenContent :data="data" scope="empresa" />

        <div class="mt-8">
            <p class="mb-3 text-xs font-bold uppercase tracking-[0.12em] text-gray-400">Explorar a fondo</p>
            <MetricsHubGrid scope="empresa" />
        </div>
    </EmpresaLayout>
</template>
