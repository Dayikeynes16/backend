<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import VentasContent from '@/Components/Metrics/Content/VentasContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.ventas');
</script>

<template>
    <Head title="Métricas · Ventas" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Ventas</h2></template>
        <MetricsHeader title="Ventas" :subtitle="range?.label" :filters="filters" :branches="branches" :show-branch-selector="true" show-status-chip />
        <VentasContent :data="data" :compare="compare" />
    </EmpresaLayout>
</template>
