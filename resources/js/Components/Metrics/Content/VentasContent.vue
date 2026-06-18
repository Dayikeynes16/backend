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

// Card "Ventas por hora": una sola barra por hora (suma de todos los días del
// rango), con la hora pico resaltada y una frase de insight. Recorta las horas
// sin actividad para una lectura limpia. Sin datos: empty state.
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

const hh = (h) => `${String(h).padStart(2, '0')}h`;

// Abreviador del Y-axis.
function abbreviated(v) {
    const n = Number(v ?? 0);
    if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000) return `$${Math.round(n / 1_000)}k`;
    return `$${Math.round(n)}`;
}

// Ventana de horas con actividad (con 1h de padding a cada lado para contexto).
const activeHours = computed(() => {
    const t = hourlyTotals.value;
    const first = t.findIndex(v => v > 0);
    if (first === -1) return [];
    const last = 23 - [...t].reverse().findIndex(v => v > 0);
    const out = [];
    for (let h = Math.max(0, first - 1); h <= Math.min(23, last + 1); h++) out.push(h);
    return out;
});

const peakHour = computed(() => {
    if (totalHourAmount.value <= 0) return null;
    const t = hourlyTotals.value;
    let idx = 0;
    t.forEach((v, h) => { if (v > t[idx]) idx = h; });
    return idx;
});

// Franja pico: ventana contigua de 3h con mayor suma, y su % del total.
const peakWindow = computed(() => {
    if (totalHourAmount.value <= 0) return null;
    const t = hourlyTotals.value;
    const W = 3;
    let bestStart = 0, bestSum = -1;
    for (let s = 0; s <= 24 - W; s++) {
        let sum = 0;
        for (let h = s; h < s + W; h++) sum += t[h];
        if (sum > bestSum) { bestSum = sum; bestStart = s; }
    }
    return { from: bestStart, to: bestStart + W - 1, share: Math.round((bestSum / totalHourAmount.value) * 100) };
});

const insight = computed(() => {
    if (peakHour.value === null || !peakWindow.value) return null;
    const w = peakWindow.value;
    return `Concentras el ${w.share}% de tus ventas entre las ${hh(w.from)} y las ${hh(w.to)}. Tu hora más fuerte es las ${hh(peakHour.value)}.`;
});

const barSeries = computed(() => [
    { name: 'Ventas', data: activeHours.value.map(h => hourlyTotals.value[h]) },
]);

const barOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    plotOptions: { bar: { columnWidth: '62%', borderRadius: 5, distributed: true } },
    colors: activeHours.value.map(h => h === peakHour.value ? '#dc2626' : '#fecaca'),
    dataLabels: { enabled: false },
    legend: { show: false },
    xaxis: {
        categories: activeHours.value.map(hh),
        labels: { style: { fontSize: '11px', colors: '#6b7280' }, rotate: 0 },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { labels: { formatter: abbreviated, style: { fontSize: '11px', colors: '#9ca3af' } } },
    tooltip: { y: { formatter: (v) => formatCurrency(v) } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 4 },
}));

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
                <ChartCard title="Ventas por hora" subtitle="Suma de ventas por hora del día en el rango seleccionado. La hora pico va resaltada.">
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
                    <template v-else>
                        <p v-if="insight" class="mb-4 flex items-start gap-2 rounded-xl border border-orange-100 bg-orange-50 px-3.5 py-2.5 text-sm font-medium text-orange-800">
                            <span class="text-base leading-none">💡</span><span>{{ insight }}</span>
                        </p>
                        <apexchart type="bar" height="280" :options="barOptions" :series="barSeries" />
                    </template>
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
