<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({
    data: Object,
    compare: Boolean,
});

const current = computed(() => props.data?.summary?.current ?? {});
const previous = computed(() => props.data?.summary?.previous ?? {});

const pct = (a, b) => (!b ? null : ((a - b) / b) * 100);
const d = (a, b) => (props.compare ? pct(a, b) : null);

const salesSeries = computed(() => {
    const currentSeries = (props.data?.daily_series ?? []).map(r => ({ x: r.day, y: r.total }));
    const series = [{ name: 'Actual', data: currentSeries }];
    if (props.compare) {
        const prev = (props.data?.previous_daily_series ?? []).map((r, i) => ({
            x: currentSeries[i]?.x ?? r.day,
            y: r.total,
        }));
        if (prev.length) series.push({ name: 'Previo', data: prev });
    }
    return series;
});

const salesChartOptions = {
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626', '#9ca3af'],
    stroke: { curve: 'smooth', width: [3, 2] },
    dataLabels: { enabled: false },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
    xaxis: { type: 'datetime' },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) }, x: { format: 'dd MMM' } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 3 },
    legend: { fontSize: '12px' },
};

const paymentMethods = computed(() => props.data?.by_payment_method ?? []);
const methodPieSeries = computed(() => paymentMethods.value.map(m => m.total));
const methodPieLabels = computed(() => paymentMethods.value.map(m => m.label));
const methodPieTotal = computed(() => paymentMethods.value.reduce((sum, m) => sum + m.total, 0));

const methodPieOptions = computed(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: methodPieLabels.value,
    colors: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7'],
    legend: { position: 'bottom', fontSize: '12px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total cobrado', formatter: () => formatCurrency(methodPieTotal.value) } } } } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    dataLabels: { enabled: false },
}));

const heatmapSeries = computed(() => {
    const matrix = props.data?.heatmap ?? {};
    const days = { 1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue', 5: 'Vie', 6: 'Sáb', 7: 'Dom' };
    return [7, 6, 5, 4, 3, 2, 1].map(dn => ({
        name: days[dn],
        data: Array.from({ length: 24 }, (_, h) => ({
            x: String(h).padStart(2, '0') + 'h',
            y: Math.round(matrix[dn]?.[h]?.total ?? 0),
        })),
    }));
});
const heatmapOptions = {
    chart: { type: 'heatmap', toolbar: { show: false }, fontFamily: 'inherit' },
    dataLabels: { enabled: false },
    plotOptions: { heatmap: { radius: 4, colorScale: { ranges: [
        { from: 0, to: 0, color: '#f9fafb' },
        { from: 1, to: 500, color: '#fecaca' },
        { from: 501, to: 2000, color: '#fca5a5' },
        { from: 2001, to: 5000, color: '#ef4444' },
        { from: 5001, to: 999999, color: '#991b1b' },
    ] } } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
};

const tableColumns = [
    { key: 'day', label: 'Día', format: 'date', strong: true },
    { key: 'tickets', label: 'Tickets', format: 'number', align: 'right' },
    { key: 'total', label: 'Total', format: 'currency', align: 'right', strong: true },
    { key: 'avg_ticket', label: 'Promedio', format: 'currency', align: 'right' },
    { key: 'cancelled', label: 'Canceladas', format: 'number', align: 'right' },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <KpiCard label="Ventas netas" tone="red" :delta="d(current.net_sales, previous.net_sales)" hint="Excluye canceladas">
                {{ formatCurrency(current.net_sales) }}
            </KpiCard>
            <KpiCard label="# Tickets" :delta="d(current.ticket_count, previous.ticket_count)">
                {{ formatNumber(current.ticket_count) }}
            </KpiCard>
            <KpiCard label="Ticket promedio" :delta="d(current.avg_ticket, previous.avg_ticket)">
                {{ formatCurrency(current.avg_ticket) }}
            </KpiCard>
            <KpiCard label="# Canceladas" tone="amber" :delta="d(current.cancelled_count, previous.cancelled_count)">
                {{ formatNumber(current.cancelled_count) }}
            </KpiCard>
            <KpiCard label="Monto cancelado" tone="amber">
                {{ formatCurrency(current.cancelled_amount) }}
            </KpiCard>
        </div>

        <ChartCard title="Ventas brutas por día" subtitle="Ventas entregadas por día (incluye crédito). Excluye canceladas.">
            <apexchart v-if="salesSeries[0].data.length" type="area" height="300" :options="salesChartOptions" :series="salesSeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <ChartCard title="Ventas por hora × día" subtitle="Concentración de ventas por hora del día. Color = monto vendido.">
                    <apexchart type="heatmap" height="280" :options="heatmapOptions" :series="heatmapSeries" />
                </ChartCard>
            </div>
            <ChartCard title="Métodos de pago" subtitle="Distribución del cobrado por forma de pago.">
                <apexchart v-if="methodPieSeries.length" type="donut" height="280" :options="methodPieOptions" :series="methodPieSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
        </div>

        <ChartCard title="Detalle por día" subtitle="Ordena haciendo click en la cabecera">
            <DataTable :columns="tableColumns" :rows="data.daily_table ?? []" />
        </ChartCard>
    </div>
</template>
