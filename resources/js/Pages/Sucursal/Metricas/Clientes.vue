<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import ClientesContent from '@/Components/Metrics/Content/ClientesContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object, inactive_days: Number });
const filters = useMetricsFilters('sucursal.metricas.clientes');
</script>

<template>
    <Head title="Métricas · Clientes" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Clientes</h2></template>
        <MetricsHeader title="Clientes" subtitle="Compradores, saldos e inactivos" :filters="filters" />
        <ClientesContent :data="data" :inactive-days="inactive_days" />
    </SucursalLayout>
</template>
