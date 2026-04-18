<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MargenContent from '@/Components/Metrics/Content/MargenContent.vue';
import BackfillBanner from '@/Components/Metrics/BackfillBanner.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
    backfill_run_at: { type: [String, null], default: null },
});
const filters = useMetricsFilters('empresa.metricas.margen');
</script>

<template>
    <Head title="Métricas · Margen" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Margen</h2></template>
        <MetricsHeader title="Rentabilidad" subtitle="Ingresos − costos por producto" :filters="filters" :branches="branches" :show-branch-selector="true" />
        <BackfillBanner :date="backfill_run_at" :range="range" />
        <MargenContent :data="data" :compare="compare" />
    </EmpresaLayout>
</template>
