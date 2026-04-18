<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import IndexContent from '@/Components/Metrics/Content/IndexContent.vue';
import BackfillBanner from '@/Components/Metrics/BackfillBanner.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
    backfill_run_at: { type: [String, null], default: null },
});
const filters = useMetricsFilters('empresa.metricas.index');
</script>

<template>
    <Head title="Métricas" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas</h2></template>
        <MetricsHeader title="Resumen" :subtitle="range?.label" :filters="filters" :branches="branches" :show-branch-selector="true" />
        <BackfillBanner :date="backfill_run_at" :range="range" />
        <IndexContent :data="data" :compare="compare" scope="empresa" />
    </EmpresaLayout>
</template>
