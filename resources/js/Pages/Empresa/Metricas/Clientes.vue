<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ClientesContent from '@/Components/Metrics/Content/ClientesContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object, inactive_days: Number,
    branches: Array, selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.clientes');
</script>

<template>
    <Head title="Métricas · Clientes" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Clientes</h2></template>
        <MetricsHeader title="Clientes" subtitle="Compradores, saldos e inactivos" :filters="filters" :branches="branches" :show-branch-selector="true" show-status-chip />
        <ClientesContent :data="data" :inactive-days="inactive_days" />
    </EmpresaLayout>
</template>
