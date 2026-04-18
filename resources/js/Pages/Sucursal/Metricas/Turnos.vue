<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import TurnosContent from '@/Components/Metrics/Content/TurnosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.turnos');
</script>

<template>
    <Head title="Métricas · Turnos" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Turnos</h2></template>
        <MetricsHeader title="Cortes de caja" subtitle="Diferencias y retiros por turno" :filters="filters" />
        <TurnosContent :data="data" />
    </SucursalLayout>
</template>
