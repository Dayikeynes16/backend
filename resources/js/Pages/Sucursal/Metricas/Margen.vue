<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MargenContent from '@/Components/Metrics/Content/MargenContent.vue';
import BackfillBanner from '@/Components/Metrics/BackfillBanner.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    backfill_run_at: { type: [String, null], default: null },
});
const filters = useMetricsFilters('sucursal.metricas.margen');
</script>

<template>
    <Head title="Métricas · Margen" />
    <MetricsLayout active-axis="margen" axis-label="Margen" header-title="Métricas · Margen">
        <MetricsHeader title="Rentabilidad"
            subtitle="Rentabilidad calculada con costo registrado al momento de cada venta."
            :filters="filters" />
        <BackfillBanner :date="backfill_run_at" :range="range" />
        <MargenContent :data="data" :compare="compare" />
    </MetricsLayout>
</template>
