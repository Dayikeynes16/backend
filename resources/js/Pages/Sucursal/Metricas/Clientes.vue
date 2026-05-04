<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ClientesContent from '@/Components/Metrics/Content/ClientesContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object, inactive_days: Number });
const filters = useMetricsFilters('sucursal.metricas.clientes');
</script>

<template>
    <Head title="Métricas · Clientes" />
    <MetricsLayout active-axis="clientes" axis-label="Clientes" header-title="Métricas · Clientes">
        <MetricsHeader title="Clientes" subtitle="Compradores, saldos pendientes y aging de cuentas." :filters="filters" show-status-chip />
        <ClientesContent :data="data" :inactive-days="inactive_days" />
    </MetricsLayout>
</template>
