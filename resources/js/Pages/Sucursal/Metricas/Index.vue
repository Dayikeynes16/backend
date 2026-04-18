<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import IndexContent from '@/Components/Metrics/Content/IndexContent.vue';
import BackfillBanner from '@/Components/Metrics/BackfillBanner.vue';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

const props = defineProps({
    data: Object,
    range: Object,
    compare: Boolean,
    tenant: Object,
    backfill_run_at: { type: [String, null], default: null },
});

const filters = useMetricsFilters('sucursal.metricas.index');
</script>

<template>
    <Head title="Métricas" />
    <SucursalLayout>
        <template #header><h2 class="text-lg font-bold text-gray-900">Métricas</h2></template>

        <MetricsHeader title="Resumen" :subtitle="range?.label" :filters="filters" />
        <BackfillBanner :date="backfill_run_at" :range="range" />
        <IndexContent :data="data" :compare="compare" scope="sucursal" />
    </SucursalLayout>
</template>
