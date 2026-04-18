<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import TurnosContent from '@/Components/Metrics/Content/TurnosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.turnos');
</script>

<template>
    <Head title="Métricas · Turnos" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Turnos</h2></template>
        <MetricsHeader title="Cortes de caja" subtitle="Diferencias y retiros" :filters="filters" :branches="branches" :show-branch-selector="true" />
        <TurnosContent :data="data" />
    </EmpresaLayout>
</template>
