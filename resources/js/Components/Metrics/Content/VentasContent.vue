<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import TimeSeriesCard from '@/Components/Metrics/TimeSeriesCard.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';
import { formatAbsoluteRange } from '@/composables/useDateRange';

const props = defineProps({
    data: Object,
});

const page = usePage();
const range = computed(() => page.props.range || null);
const currentRangeText = computed(() => range.value ? formatAbsoluteRange(range.value.from, range.value.to) : '');

const trendSubtitle = computed(() =>
    `Cuenta cada venta el día que se creó/completó. Excluye canceladas. · ${currentRangeText.value}`
);

const current = computed(() => props.data?.summary?.current ?? {});

const trendCurrent = computed(() => (props.data?.daily_series ?? []).map(r => ({ day: r.day, value: Number(r.total ?? 0) })));

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

// Card "Ventas por hora" — adaptativa según rango.
//   ≤7 días: bar chart 0h-23h (sumando todos los días del rango).
//   >7 días: heatmap día-de-semana × hora.
// Sin datos: empty state.
const daysInRange = computed(() => Number(page.props.range?.days ?? 1));
const useBarMode = computed(() => daysInRange.value <= 7);

const hourlyTotals = computed(() => {
    const m = props.data?.heatmap ?? {};
    return Array.from({ length: 24 }, (_, h) => {
        let total = 0;
        for (let d = 1; d <= 7; d++) {
            total += Number(m[d]?.[h]?.total ?? 0);
        }
        return total;
    });
});

const totalHourAmount = computed(() => hourlyTotals.value.reduce((s, v) => s + v, 0));
const isHourEmpty = computed(() => totalHourAmount.value === 0);

const hourCardTitle = computed(() =>
    useBarMode.value ? 'Ventas por hora' : 'Ventas por hora y día de la semana'
);
const hourCardSubtitle = computed(() =>
    useBarMode.value
        ? 'Suma de ventas por hora del día en el rango seleccionado.'
        : 'Color = monto vendido. Más oscuro = más ventas. Identifica horas pico para planear staff y producción.'
);

// Abreviador del Y-axis. Mismo helper que TimeSeriesCard usa (todavía no
// vive en useCurrency).
function abbreviated(v) {
    const n = Number(v ?? 0);
    if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000) return `$${Math.round(n / 1_000)}k`;
    return `$${Math.round(n)}`;
}

const barSeries = computed(() => [
    { name: 'Ventas', data: hourlyTotals.value },
]);

const barOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: { bar: { columnWidth: '60%', borderRadius: 4, dataLabels: { position: 'top' } } },
    dataLabels: {
        enabled: true,
        formatter: (v) => v > 0 ? formatCurrency(v) : '',
        style: { fontSize: '10px', fontWeight: 700, colors: ['#111827'] },
        offsetY: -20,
    },
    xaxis: {
        categories: Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}h`),
        labels: { style: { fontSize: '11px', colors: '#6b7280' }, rotate: 0 },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { labels: { formatter: abbreviated, style: { fontSize: '11px', colors: '#9ca3af' } } },
    tooltip: { y: { formatter: (v) => formatCurrency(v) } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 30 } },
    legend: { show: false },
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
        { from: 0,    to: 0,      color: '#f9fafb', name: 'Sin ventas' },
        { from: 1,    to: 500,    color: '#fecaca', name: 'Hasta $500' },
        { from: 501,  to: 2000,   color: '#fca5a5', name: '$500 – $2k' },
        { from: 2001, to: 5000,   color: '#ef4444', name: '$2k – $5k' },
        { from: 5001, to: 999999, color: '#991b1b', name: 'Más de $5k' },
    ] } } },
    xaxis: {
        labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '10px', colors: '#6b7280' } },
    },
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
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <KpiCard label="Ventas netas" tone="red"
                hint="Ventas generadas (excluye canceladas)"
                tooltip="Suma de ventas creadas o completadas en el período. Incluye pendientes y a crédito. Excluye canceladas. La fecha que cuenta es cuándo se generó la venta, no cuándo se cobró.">
                {{ formatCurrency(current.net_sales) }}
            </KpiCard>
            <KpiCard label="# Tickets"
                hint="Ventas no canceladas"
                tooltip="Cantidad de ventas creadas o completadas en el período (excluye canceladas).">
                {{ formatNumber(current.ticket_count) }}
            </KpiCard>
            <KpiCard label="Ticket promedio"
                hint="Ventas netas ÷ # Tickets"
                tooltip="Promedio de las ventas no canceladas del período. Incluye pendientes y a crédito.">
                {{ formatCurrency(current.avg_ticket) }}
            </KpiCard>
            <KpiCard label="# Canceladas" tone="amber"
                hint="Por fecha de cancelación"
                tooltip="Cantidad de ventas canceladas dentro del período (por fecha de cancelación, no de creación).">
                {{ formatNumber(current.cancelled_count) }}
            </KpiCard>
            <KpiCard label="Monto cancelado" tone="amber"
                hint="Total cancelado en el período"
                tooltip="Suma de los totales de las ventas canceladas en el período. Esta cifra NO se descuenta de las ventas netas para evitar doble conteo.">
                {{ formatCurrency(current.cancelled_amount) }}
            </KpiCard>
        </div>

        <TimeSeriesCard
            title="Ventas generadas por día"
            :subtitle="trendSubtitle"
            :current="trendCurrent"
            value-label="Ventas"
            :format-value="formatCurrency"
            color="#dc2626"
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <ChartCard :title="hourCardTitle" :subtitle="hourCardSubtitle">
                    <div v-if="isHourEmpty"
                        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-12 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-white shadow-sm">
                            <svg class="h-7 w-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </span>
                        <p class="mt-3 text-base font-semibold text-gray-700">No hubo ventas en este periodo</p>
                        <p class="mt-1 max-w-sm text-sm text-gray-500">
                            Cuando registres ventas verás aquí en qué horas se concentran.
                        </p>
                    </div>
                    <apexchart v-else-if="useBarMode" type="bar" height="280" :options="barOptions" :series="barSeries" />
                    <apexchart v-else type="heatmap" height="320" :options="heatmapOptions" :series="heatmapSeries" />
                </ChartCard>
            </div>
            <ChartCard title="Cobranza por método de pago" subtitle="Distribución de pagos recibidos en el período. Puede incluir pagos de ventas de días anteriores.">
                <apexchart v-if="methodPieSeries.length" type="donut" height="280" :options="methodPieOptions" :series="methodPieSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
        </div>

        <ChartCard title="Detalle por día" subtitle="Cada fila es un día del período. Click en la cabecera para ordenar.">
            <DataTable :columns="tableColumns" :rows="data.daily_table ?? []" />
        </ChartCard>
    </div>
</template>
