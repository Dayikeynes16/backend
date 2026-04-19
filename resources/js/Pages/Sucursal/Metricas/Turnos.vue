<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import TurnosContent from '@/Components/Metrics/Content/TurnosContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.turnos');
</script>

<template>
    <Head title="Métricas · Turnos" />
    <MetricsLayout active-axis="turnos" axis-label="Turnos" header-title="Métricas · Turnos">
        <MetricsHeader title="Cortes de caja" subtitle="Diferencias y retiros al cierre de cada turno." :filters="filters" />
        <TurnosContent :data="data" />
    </MetricsLayout>
</template>
