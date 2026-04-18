<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
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
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Margen</h2></template>
        <MetricsHeader title="Rentabilidad" subtitle="Ingresos − costos por producto" :filters="filters" />
        <BackfillBanner :date="backfill_run_at" :range="range" />
        <MargenContent :data="data" :compare="compare" />
    </SucursalLayout>
</template>
