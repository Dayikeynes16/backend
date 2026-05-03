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
    compare: Boolean,
});

const page = usePage();
const range = computed(() => page.props.range || null);
const currentRangeText = computed(() => range.value ? formatAbsoluteRange(range.value.from, range.value.to) : '');
const previousRangeText = computed(() => range.value?.previous ? formatAbsoluteRange(range.value.previous.from, range.value.previous.to) : '');

const trendSubtitle = computed(() => {
    const base = 'Cuenta cada venta el día que se creó/completó. Excluye canceladas.';
    if (props.compare && previousRangeText.value) {
        return `${base} · ${currentRangeText.value} vs. ${previousRangeText.value}`;
    }
    return `${base} · ${currentRangeText.value}`;
});

const current = computed(() => props.data?.summary?.current ?? {});
const previous = computed(() => props.data?.summary?.previous ?? {});

const pct = (a, b) => (!b ? null : ((a - b) / b) * 100);
const d = (a, b) => (props.compare ? pct(a, b) : null);

const trendCurrent = computed(() => (props.data?.daily_series ?? []).map(r => ({ day: r.day, value: Number(r.total ?? 0) })));
const trendPrevious = computed(() => (props.data?.previous_daily_series ?? []).map(r => ({ day: r.day, value: Number(r.total ?? 0) })));

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
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <KpiCard label="Ventas netas" tone="red"
                :delta="d(current.net_sales, previous.net_sales)"
                hint="Ventas generadas (excluye canceladas)"
                tooltip="Suma de ventas creadas o completadas en el período. Incluye pendientes y a crédito. Excluye canceladas. La fecha que cuenta es cuándo se generó la venta, no cuándo se cobró.">
                {{ formatCurrency(current.net_sales) }}
            </KpiCard>
            <KpiCard label="# Tickets"
                :delta="d(current.ticket_count, previous.ticket_count)"
                hint="Ventas no canceladas"
                tooltip="Cantidad de ventas creadas o completadas en el período (excluye canceladas).">
                {{ formatNumber(current.ticket_count) }}
            </KpiCard>
            <KpiCard label="Ticket promedio"
                :delta="d(current.avg_ticket, previous.avg_ticket)"
                hint="Ventas netas ÷ # Tickets"
                tooltip="Promedio de las ventas no canceladas del período. Incluye pendientes y a crédito.">
                {{ formatCurrency(current.avg_ticket) }}
            </KpiCard>
            <KpiCard label="# Canceladas" tone="amber"
                :delta="d(current.cancelled_count, previous.cancelled_count)"
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
            :previous="trendPrevious"
            value-label="Ventas"
            :format-value="formatCurrency"
            color="#dc2626"
            :compare="props.compare"
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <ChartCard title="Ventas por hora y día de la semana" subtitle="Color = monto vendido. Más oscuro = más ventas. Identifica horas pico para planear staff y producción.">
                    <apexchart type="heatmap" height="280" :options="heatmapOptions" :series="heatmapSeries" />
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
