<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CajerosContent from '@/Components/Metrics/Content/CajerosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.cajeros');
</script>

<template>
    <Head title="Métricas · Cajeros" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Cajeros</h2></template>
        <MetricsHeader title="Cajeros" subtitle="Desempeño por operador" :filters="filters" :branches="branches" :show-branch-selector="true" />
        <CajerosContent :data="data" />
    </EmpresaLayout>
</template>
