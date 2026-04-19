<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object });

const summary = computed(() => props.data?.summary ?? {});
const byCashier = computed(() => props.data?.by_cashier ?? []);

const salesSeries = computed(() => [{
    name: 'Ventas',
    data: byCashier.value.map(c => ({ x: c.name, y: c.total })),
}]);
const salesOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '40%' } },
    dataLabels: { enabled: false },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    grid: { borderColor: '#f3f4f6' },
};

const cancelSeries = computed(() => [{
    name: '% Cancelación',
    data: byCashier.value.map(c => ({ x: c.name, y: c.cancel_pct })),
}]);
const cancelOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#f59e0b'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '40%' } },
    dataLabels: { enabled: false },
    yaxis: { labels: { formatter: v => `${v}%` } },
    tooltip: { y: { formatter: v => `${v}%` } },
    grid: { borderColor: '#f3f4f6' },
};

const columns = [
    { key: 'name', label: 'Cajero', strong: true },
    { key: 'tickets', label: 'Tickets', format: 'number', align: 'right' },
    { key: 'total', label: 'Total vendido', format: 'currency', align: 'right', strong: true },
    { key: 'avg_ticket', label: 'Promedio', format: 'currency', align: 'right' },
    { key: 'cancelled', label: 'Canceladas', format: 'number', align: 'right' },
    { key: 'cancel_pct', label: '% Cancel.', format: 'percent', align: 'right' },
    { key: 'discount_total', label: 'Descuentos', format: 'currency', align: 'right' },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Cajeros activos" tone="blue">{{ formatNumber(summary.active_cashiers) }}</KpiCard>
            <KpiCard label="Cajero top" tone="green" :hint="summary.top_cashier?.name">
                {{ formatCurrency(summary.top_cashier?.total) }}
            </KpiCard>
            <KpiCard label="% Cancel. promedio" tone="amber">
                {{ summary.avg_cancel_ratio_pct ?? 0 }}%
            </KpiCard>
            <KpiCard label="Descuentos aplicados" tone="red">
                {{ formatCurrency(summary.total_discounts) }}
            </KpiCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Ventas por cajero" subtitle="Total cobrado por cajero en el rango.">
                <apexchart v-if="salesSeries[0].data.length" type="bar" height="300" :options="salesOptions" :series="salesSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
            <ChartCard title="% de cancelación por cajero" subtitle="Porcentaje de tickets cancelados sobre el total de tickets del cajero.">
                <apexchart v-if="cancelSeries[0].data.length" type="bar" height="300" :options="cancelOptions" :series="cancelSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
        </div>

        <ChartCard title="Detalle por cajero" subtitle="Desempeño por cajero: tickets, total, % cancelación, descuentos.">
            <DataTable :columns="columns" :rows="byCashier" />
        </ChartCard>
    </div>
</template>
