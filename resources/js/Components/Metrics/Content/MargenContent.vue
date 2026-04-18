<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object, compare: Boolean });

const current = computed(() => props.data?.summary?.current ?? {});
const previous = computed(() => props.data?.summary?.previous ?? {});
const pct = (a, b) => (!b ? null : ((a - b) / b) * 100);
const d = (a, b) => (props.compare ? pct(a, b) : null);

const profitSeries = computed(() => [{
    name: 'Ganancia',
    data: (props.data?.daily_gross_profit ?? []).map(r => ({ x: r.day, y: r.gross_profit })),
}]);
const profitOptions = {
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#059669'],
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    xaxis: { type: 'datetime' },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) }, x: { format: 'dd MMM' } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 3 },
};

const categorySeries = computed(() => [{
    name: 'Margen %',
    data: (props.data?.by_category ?? []).map(c => ({ x: c.category, y: c.margin_pct })),
}]);
const categoryOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: { bar: { borderRadius: 4, horizontal: true, distributed: false, dataLabels: { position: 'top' } } },
    dataLabels: { enabled: true, formatter: v => `${v}%`, style: { fontSize: '11px', colors: ['#111'] }, offsetX: 30 },
    xaxis: { labels: { formatter: v => `${v}%` } },
    tooltip: { y: { formatter: v => `${v}%` } },
    grid: { borderColor: '#f3f4f6' },
};

const productColumns = [
    { key: 'product_name', label: 'Producto', strong: true },
    { key: 'quantity', label: 'Cantidad', format: 'decimal', align: 'right' },
    { key: 'revenue', label: 'Ingreso', format: 'currency', align: 'right' },
    { key: 'cost', label: 'Costo', format: 'currency', align: 'right' },
    { key: 'gross_profit', label: 'Ganancia', format: 'currency', align: 'right', strong: true },
    { key: 'margin_pct', label: 'Margen', format: 'percent', align: 'right' },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Ganancia bruta" tone="green" :delta="d(current.gross_profit, previous.gross_profit)">
                {{ formatCurrency(current.gross_profit) }}
            </KpiCard>
            <KpiCard label="% Margen global" tone="green" :delta="d(current.margin_pct, previous.margin_pct)">
                {{ current.margin_pct ?? 0 }}%
            </KpiCard>
            <KpiCard label="Margen por ticket" :delta="d(current.avg_margin_per_ticket, previous.avg_margin_per_ticket)">
                {{ formatCurrency(current.avg_margin_per_ticket) }}
            </KpiCard>
            <KpiCard label="Items sin costo" tone="amber" hint="No entran al cálculo de margen">
                {{ formatNumber(current.items_without_cost) }}
            </KpiCard>
        </div>

        <ChartCard title="Ganancia bruta diaria">
            <apexchart v-if="profitSeries[0].data.length" type="area" height="260" :options="profitOptions" :series="profitSeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <ChartCard title="Margen % por categoría">
            <apexchart v-if="categorySeries[0].data.length" type="bar" :height="50 + (categorySeries[0].data.length * 36)" :options="categoryOptions" :series="categorySeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <ChartCard title="Rentabilidad por producto" subtitle="Los 100 más rentables del rango">
            <DataTable :columns="productColumns" :rows="data.by_product ?? []" />
        </ChartCard>
    </div>
</template>
