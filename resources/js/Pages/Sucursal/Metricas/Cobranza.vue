<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CobranzaContent from '@/Components/Metrics/Content/CobranzaContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.cobranza');
</script>

<template>
    <Head title="Métricas · Cobranza" />
    <MetricsLayout active-axis="cobranza" axis-label="Cobranza" header-title="Métricas · Cobranza">
        <MetricsHeader title="Cobranza" subtitle="Pagos recibidos y cuentas por cobrar pendientes." :filters="filters" />
        <CobranzaContent :data="data" />
    </MetricsLayout>
</template>
