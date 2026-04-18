<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CobranzaContent from '@/Components/Metrics/Content/CobranzaContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object, range: Object, compare: Boolean, tenant: Object,
    branches: Array, selected_branch_id: [Number, null],
});
const filters = useMetricsFilters('empresa.metricas.cobranza');
</script>

<template>
    <Head title="Métricas · Cobranza" />
    <EmpresaLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Cobranza</h2></template>
        <MetricsHeader title="Cobranza" subtitle="Pagos y cuentas por cobrar" :filters="filters" :branches="branches" :show-branch-selector="true" />
        <CobranzaContent :data="data" />
    </EmpresaLayout>
</template>
