<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CancelacionesContent from '@/Components/Metrics/Content/CancelacionesContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object,
    history: Object,
    range: Object,
    compare: Boolean,
    tenant: Object,
    branches: Array,
    selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.cancelaciones');
</script>

<template>
    <Head title="Métricas · Cancelaciones" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Cancelaciones</h2></template>
        <MetricsHeader title="Cancelaciones"
            subtitle="Cancelaciones por sucursal, motivos y usuarios en el rango."
            :filters="filters" :branches="branches" :show-branch-selector="true" />
        <CancelacionesContent :data="data" :history="history" :range="range" scope="empresa" />
    </EmpresaLayout>
</template>
