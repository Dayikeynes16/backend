<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';
import { formatAbsoluteRange } from '@/composables/useDateRange';

const props = defineProps({
    data: Object,
    compare: Boolean,
    scope: { type: String, required: true }, // 'sucursal' | 'empresa'
});

const page = usePage();
const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);
const range = computed(() => page.props.range || null);

const currentRangeText = computed(() => {
    if (!range.value) return '';
    return formatAbsoluteRange(range.value.from, range.value.to);
});
const previousRangeText = computed(() => {
    if (!range.value?.previous) return '';
    return formatAbsoluteRange(range.value.previous.from, range.value.previous.to);
});

// Subtítulo dinámico para "Tendencia de ingresos" — explica qué se compara
// con fechas absolutas en lugar del genérico "Comparado con el periodo previo".
const trendSubtitle = computed(() => {
    if (props.compare && previousRangeText.value) {
        return `Ventas generadas por día · ${currentRangeText.value} vs. ${previousRangeText.value}`;
    }
    return `Ventas generadas por día · ${currentRangeText.value}`;
});

const salesCurrent = computed(() => props.data?.sales?.current ?? {});
const salesPrevious = computed(() => props.data?.sales?.previous ?? {});
const marginCurrent = computed(() => props.data?.margin?.current ?? {});
const marginPrevious = computed(() => props.data?.margin?.previous ?? {});
const collection = computed(() => props.data?.collection ?? {});

const pct = (a, b) => {
    if (!b || b === 0) return null;
    return ((a - b) / b) * 100;
};
const deltaIf = (a, b) => (props.compare ? pct(a, b) : null);

const timeSeries = computed(() => ({
    current: (props.data?.daily_series ?? []).map(d => ({ x: d.day, y: d.total })),
    previous: (props.data?.previous_daily_series ?? []).map(d => ({ x: d.day, y: d.total })),
}));

// Con zero-fill backend, `current` siempre trae ≥1 punto. Mostrar EmptyState
// solo cuando ningun dia tuvo ventas reales (todos los totales = 0).
const hasAnySales = computed(() => timeSeries.value.current.some(d => Number(d.y) > 0));

const salesSeries = computed(() => {
    const currentName = currentRangeText.value || 'Periodo actual';
    const series = [{ name: currentName, data: timeSeries.value.current }];
    if (props.compare && timeSeries.value.previous.length) {
        const offset = timeSeries.value.previous.map((d, i) => ({
            x: timeSeries.value.current[i]?.x ?? d.x,
            y: d.y,
        }));
        series.push({ name: previousRangeText.value || 'Periodo previo', data: offset });
    }
    return series;
});

const salesChartOptions = {
    chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
    colors: ['#dc2626', '#9ca3af'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: [3, 2] },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.2, opacityFrom: 0.4, opacityTo: 0.02, stops: [0, 90, 100] } },
    markers: { size: 4, strokeWidth: 0, hover: { size: 6 } },
    xaxis: { type: 'datetime', labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v) => formatCurrency(v), style: { fontSize: '11px' } } },
    tooltip: { y: { formatter: (v) => formatCurrency(v) }, x: { format: 'dd MMM' } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 3 },
    legend: { fontSize: '12px', markers: { radius: 3 } },
};

const heatmapSeries = computed(() => {
    const matrix = props.data?.heatmap ?? {};
    const days = { 1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue', 5: 'Vie', 6: 'Sáb', 7: 'Dom' };
    return [7, 6, 5, 4, 3, 2, 1].map(d => ({
        name: days[d],
        data: Array.from({ length: 24 }, (_, h) => ({
            x: String(h).padStart(2, '0') + 'h',
            y: Math.round(matrix[d]?.[h]?.total ?? 0),
        })),
    }));
});

const heatmapOptions = {
    chart: { type: 'heatmap', toolbar: { show: false }, fontFamily: 'inherit' },
    dataLabels: { enabled: false },
    colors: ['#dc2626'],
    plotOptions: {
        heatmap: {
            radius: 4,
            shadeIntensity: 0.6,
            colorScale: {
                ranges: [
                    { from: 0, to: 0, color: '#f9fafb', name: 'Sin ventas' },
                    { from: 1, to: 500, color: '#fecaca' },
                    { from: 501, to: 2000, color: '#fca5a5' },
                    { from: 2001, to: 5000, color: '#ef4444' },
                    { from: 5001, to: 999999, color: '#991b1b' },
                ],
            },
        },
    },
    xaxis: { labels: { style: { fontSize: '10px' } } },
    tooltip: { y: { formatter: (v) => formatCurrency(v) } },
    grid: { padding: { right: 20 } },
};

const topByMargin = computed(() => props.data?.top_products_by_margin ?? []);

const subpages = computed(() => {
    const prefix = props.scope === 'empresa' ? 'empresa' : 'sucursal';
    return [
        { key: 'ventas', label: 'Ventas', hint: 'Volumen y tendencias', icon: 'trend' },
        { key: 'margen', label: 'Margen', hint: 'Rentabilidad', icon: 'chart' },
        { key: 'productos', label: 'Productos', hint: 'Top y sin movimiento', icon: 'box' },
        { key: 'clientes', label: 'Clientes', hint: 'Top y saldos', icon: 'user' },
        { key: 'cajeros', label: 'Cajeros', hint: 'Desempeño', icon: 'badge' },
        { key: 'turnos', label: 'Turnos', hint: 'Diferencias de caja', icon: 'shift' },
        { key: 'cobranza', label: 'Cobranza', hint: 'Cuentas por cobrar', icon: 'money' },
    ].map(s => ({ ...s, route: `${prefix}.metricas.${s.key}` }));
});

const iconPaths = {
    trend: 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941',
    chart: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75Z',
    box: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0',
    badge: 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818',
    shift: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    money: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12',
};
</script>

<template>
    <div v-if="!data">
        <EmptyState />
    </div>
    <div v-else class="space-y-6">
        <!-- GRUPO 1 · Ventas generadas en el período -->
        <section>
            <header class="mb-2 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-red-50 text-red-600">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>
                </span>
                <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-gray-700">Ventas generadas</h2>
                <span class="text-xs text-gray-400">· lo que se vendió en el período (no lo cobrado)</span>
            </header>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <KpiCard label="Ventas netas" tone="red"
                    :delta="deltaIf(salesCurrent.net_sales, salesPrevious.net_sales)"
                    hint="Excluye canceladas"
                    tooltip="Suma de ventas creadas o completadas dentro del período. Incluye pendientes y a crédito. Excluye canceladas. La fecha que cuenta es cuándo se generó la venta, no cuándo se pagó.">
                    {{ formatCurrency(salesCurrent.net_sales) }}
                </KpiCard>
                <KpiCard label="# Tickets" tone="neutral"
                    :delta="deltaIf(salesCurrent.ticket_count, salesPrevious.ticket_count)"
                    hint="Ventas no canceladas"
                    tooltip="Cantidad de ventas creadas o completadas en el período (excluye canceladas).">
                    {{ formatNumber(salesCurrent.ticket_count) }}
                </KpiCard>
                <KpiCard label="Ticket promedio" tone="neutral"
                    :delta="deltaIf(salesCurrent.avg_ticket, salesPrevious.avg_ticket)"
                    hint="Ventas netas ÷ # Tickets"
                    tooltip="Promedio de las ventas no canceladas del período. Incluye pendientes y a crédito.">
                    {{ formatCurrency(salesCurrent.avg_ticket) }}
                </KpiCard>
                <KpiCard label="Cancelaciones" tone="amber"
                    :hint="salesCurrent.cancelled_count ? `${salesCurrent.cancelled_count} ventas canceladas` : 'Sin canceladas'"
                    tooltip="Total de ventas canceladas en el período (por fecha de cancelación). El monto NO se descuenta de las ventas netas para evitar doble conteo.">
                    {{ formatCurrency(salesCurrent.cancelled_amount) }}
                </KpiCard>
            </div>
        </section>

        <!-- GRUPO 2 · Cobranza recibida en el período -->
        <section>
            <header class="mb-2 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75M2.25 6v9M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                </span>
                <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-gray-700">Cobranza</h2>
                <span class="text-xs text-gray-400">· dinero realmente recibido en el período</span>
            </header>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <KpiCard label="Cobros globales" tone="blue"
                    :hint="collection.payment_count ? `${collection.payment_count} cobros registrados` : 'Sin cobros'"
                    tooltip="Pagos recibidos vía cobros globales (CustomerPayments) dentro del período. Estos son los pagos que aplican a múltiples ventas a la vez. NO incluye pagos individuales hechos directamente en una venta.">
                    {{ formatCurrency(collection.total_collected) }}
                </KpiCard>
                <KpiCard label="Saldo por cobrar" tone="amber"
                    hint="Total adeudado al cierre"
                    tooltip="Suma de amount_pending de todas las ventas a clientes con saldo pendiente, sin importar el período. Es la cartera por cobrar al momento.">
                    {{ formatCurrency(collection.total_pending_balance ?? 0) }}
                </KpiCard>
                <KpiCard label="Días promedio de cobro" tone="neutral"
                    :hint="collection.avg_days_to_collect != null ? 'Desde venta hasta primer pago' : 'Sin datos suficientes'"
                    tooltip="Promedio de días que tarda una venta a crédito en recibir su primer pago. Se calcula sobre ventas con al menos un pago registrado en el período.">
                    {{ collection.avg_days_to_collect != null ? `${collection.avg_days_to_collect} días` : '—' }}
                </KpiCard>
            </div>
        </section>

        <!-- GRUPO 3 · Ganancia y cobertura de costos -->
        <section>
            <header class="mb-2 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                </span>
                <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-gray-700">Ganancia</h2>
                <span class="text-xs text-gray-400">· solo ventas cobradas al 100% con costo registrado</span>
            </header>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <KpiCard label="Ganancia bruta" tone="green"
                    :delta="deltaIf(marginCurrent.gross_profit, marginPrevious.gross_profit)"
                    hint="Ingreso − costo"
                    tooltip="Suma de (precio − costo) × cantidad de cada item vendido. Solo incluye ventas cobradas al 100% y productos con cost_price_at_sale registrado.">
                    {{ formatCurrency(marginCurrent.gross_profit) }}
                </KpiCard>
                <KpiCard label="Margen" tone="green"
                    :delta="deltaIf(marginCurrent.margin_pct, marginPrevious.margin_pct)"
                    hint="Ganancia ÷ Ingreso"
                    tooltip="Porcentaje de ganancia sobre ingreso, solo de las ventas con costo registrado.">
                    {{ marginCurrent.margin_pct != null ? `${marginCurrent.margin_pct}%` : '—' }}
                </KpiCard>
                <KpiCard label="Cobertura de costos" tone="neutral"
                    :hint="marginCurrent.items_without_cost ? `${marginCurrent.items_without_cost} item(s) sin costo` : 'Todos los items con costo'"
                    tooltip="Qué porcentaje de los items vendidos tiene costo registrado. Si es bajo, la ganancia mostrada es solo parcial — la real puede ser mayor.">
                    {{ marginCurrent.items_with_cost && (marginCurrent.items_with_cost + marginCurrent.items_without_cost) > 0
                        ? `${Math.round((marginCurrent.items_with_cost / (marginCurrent.items_with_cost + marginCurrent.items_without_cost)) * 100)}%`
                        : '—' }}
                </KpiCard>
            </div>
        </section>

        <ChartCard title="Tendencia de ingresos" :subtitle="trendSubtitle">
            <div v-if="!hasAnySales" class="py-10"><EmptyState /></div>
            <apexchart v-else type="area" height="300" :options="salesChartOptions" :series="salesSeries" />
        </ChartCard>

        <div class="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Ventas por hora y día de la semana" subtitle="Color = monto vendido. Más oscuro = más ventas. Identifica horas pico para planear staff y producción.">
                <apexchart type="heatmap" height="260" :options="heatmapOptions" :series="heatmapSeries" />
            </ChartCard>

            <ChartCard title="Top productos por ganancia" subtitle="Los más rentables del periodo">
                <div v-if="!topByMargin.length" class="py-6"><EmptyState title="Sin productos con margen registrado" /></div>
                <ul v-else class="space-y-3">
                    <li v-for="(p, i) in topByMargin" :key="p.product_id" class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-xs font-bold text-red-700">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ p.product_name }}</p>
                            <p class="text-xs text-gray-500">{{ formatNumber(p.quantity, 2) }} vendidos · margen {{ p.margin_pct }}%</p>
                        </div>
                        <span class="text-sm font-bold text-emerald-600">{{ formatCurrency(p.gross_profit) }}</span>
                    </li>
                </ul>
            </ChartCard>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-500">Explorar por eje</h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Link v-for="s in subpages" :key="s.key" :href="route(s.route, slug)"
                    class="group flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-red-300 hover:shadow-md">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600 transition group-hover:bg-red-600 group-hover:text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[s.icon]" /></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900">{{ s.label }}</p>
                        <p class="truncate text-xs text-gray-500">{{ s.hint }}</p>
                    </div>
                </Link>
            </div>
        </div>
    </div>
</template>
