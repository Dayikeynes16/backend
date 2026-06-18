<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MetricsHubGrid from '@/Components/Metrics/MetricsHubGrid.vue';
import ResumenContent from '@/Components/Metrics/Content/ResumenContent.vue';
import { Head } from '@inertiajs/vue3';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({
    tenant: Object,
    data: { type: Object, default: null },
});

const filters = useMetricsFilters('sucursal.metricas.index');
</script>

<template>
    <Head title="Métricas · Resumen" />
    <MetricsLayout>
        <MetricsHeader
            title="Resumen"
            subtitle="Panorama de tu sucursal"
            :filters="filters"
            show-status-chip
        />
        <ResumenContent :data="data" scope="sucursal" />

        <div class="mt-8">
            <p class="mb-3 text-xs font-bold uppercase tracking-[0.12em] text-gray-400">Explorar a fondo</p>
            <MetricsHubGrid scope="sucursal" />
        </div>
    </MetricsLayout>
</template>
