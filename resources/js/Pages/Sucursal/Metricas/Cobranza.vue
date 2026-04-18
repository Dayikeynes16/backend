<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import CobranzaContent from '@/Components/Metrics/Content/CobranzaContent.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({ data: Object, range: Object, compare: Boolean, tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.cobranza');
</script>

<template>
    <Head title="Métricas · Cobranza" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas · Cobranza</h2></template>
        <MetricsHeader title="Cobranza" subtitle="Pagos recibidos y cuentas por cobrar" :filters="filters" />
        <CobranzaContent :data="data" />
    </SucursalLayout>
</template>
