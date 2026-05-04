<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import VentasContent from '@/Components/Metrics/Content/VentasContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.ventas');
</script>

<template>
    <Head title="Métricas · Ventas" />
    <MetricsLayout active-axis="ventas" axis-label="Ventas" header-title="Métricas · Ventas">
        <MetricsHeader title="Ventas" subtitle="Ingresos, tickets y métodos de pago en el rango." :filters="filters" show-status-chip />
        <VentasContent :data="data" :compare="compare" />
    </MetricsLayout>
</template>
