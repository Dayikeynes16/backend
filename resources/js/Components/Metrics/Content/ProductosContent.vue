<script setup>
import { computed, ref } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object, noMovementDays: Number });

const summary = computed(() => props.data?.summary ?? {});
const tab = ref('top');

const topRevSeries = computed(() => [{
    name: 'Ingreso',
    data: (props.data?.top_by_revenue ?? []).map(p => ({ x: p.product_name, y: p.revenue })),
}]);
const topQtySeries = computed(() => [{
    name: 'Cantidad',
    data: (props.data?.top_by_quantity ?? []).map(p => ({ x: p.product_name, y: p.quantity })),
}]);
const categorySeries = computed(() => (props.data?.category_share ?? []).map(c => c.revenue));
const categoryLabels = computed(() => (props.data?.category_share ?? []).map(c => c.category));

const barOptions = (currency = true) => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: { bar: { borderRadius: 4, horizontal: true } },
    dataLabels: { enabled: false },
    xaxis: { labels: { formatter: v => currency ? formatCurrency(v) : formatNumber(v, 2) } },
    tooltip: { y: { formatter: v => currency ? formatCurrency(v) : `${formatNumber(v, 3)}` } },
    grid: { borderColor: '#f3f4f6' },
});

const donutOptions = {
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: categoryLabels,
    legend: { position: 'bottom', fontSize: '12px' },
    plotOptions: { pie: { donut: { size: '60%' } } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    dataLabels: { enabled: false },
};

const topColumns = [
    { key: 'product_name', label: 'Producto', strong: true },
    { key: 'quantity', label: 'Cantidad', format: 'decimal', align: 'right' },
    { key: 'revenue', label: 'Ingreso', format: 'currency', align: 'right', strong: true },
];
const leastColumns = topColumns;
const noMoveColumns = [
    { key: 'name', label: 'Producto', strong: true },
    { key: 'price', label: 'Precio', format: 'currency', align: 'right' },
    { key: 'cost_price', label: 'Costo', format: 'currency', align: 'right' },
    { key: 'last_sold', label: 'Última venta', format: 'date', align: 'right' },
];
const alertColumns = [
    { key: 'name', label: 'Producto', strong: true },
    { key: 'price', label: 'Precio', format: 'currency', align: 'right' },
    { key: 'cost_price', label: 'Costo', format: 'currency', align: 'right' },
];

const tabs = [
    { key: 'top', label: 'Top vendidos', count: (props.data?.top_by_revenue ?? []).length },
    { key: 'least', label: 'Menos vendidos', count: (props.data?.least_sold ?? []).length },
    { key: 'noMove', label: `Sin movimiento (${props.noMovementDays ?? 30}d)`, count: (props.data?.no_movement ?? []).length },
    { key: 'alert', label: 'Precio ≤ Costo', count: (props.data?.price_below_cost ?? []).length, alert: true },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Productos únicos vendidos" tone="blue">
                {{ formatNumber(summary.unique_products_sold) }}
            </KpiCard>
            <KpiCard label="Top producto" tone="red" :hint="summary.top_product?.name">
                {{ formatCurrency(summary.top_product?.revenue) }}
            </KpiCard>
            <KpiCard label="Más rentable" tone="green" :hint="summary.most_profitable_product?.name">
                {{ formatCurrency(summary.most_profitable_product?.profit) }}
            </KpiCard>
            <KpiCard label="Sin movimiento" tone="amber" :hint="`Último vendido > ${noMovementDays ?? 30}d`">
                {{ formatNumber(summary.no_movement_count) }}
            </KpiCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Top 10 por ingreso" subtitle="Los 10 productos que más facturaron en el rango.">
                <apexchart v-if="topRevSeries[0].data.length" type="bar" :height="50 + topRevSeries[0].data.length * 30" :options="barOptions(true)" :series="topRevSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
            <ChartCard title="Top 10 por cantidad" subtitle="Los 10 productos con más unidades vendidas en el rango.">
                <apexchart v-if="topQtySeries[0].data.length" type="bar" :height="50 + topQtySeries[0].data.length * 30" :options="barOptions(false)" :series="topQtySeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
        </div>

        <ChartCard title="Distribución por categoría" subtitle="Participación de cada categoría en el ingreso del rango.">
            <apexchart v-if="categorySeries.length" type="donut" height="300" :options="donutOptions" :series="categorySeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                    :class="['rounded-full px-4 py-1.5 text-xs font-semibold transition',
                        tab === t.key
                            ? (t.alert ? 'bg-amber-600 text-white' : 'bg-gray-900 text-white')
                            : (t.alert ? 'bg-amber-100 text-amber-700 hover:bg-amber-200' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')]">
                    {{ t.label }} <span class="ml-1 opacity-70">({{ t.count }})</span>
                </button>
            </div>
            <DataTable v-if="tab === 'top'" :columns="topColumns" :rows="data.top_by_revenue ?? []" />
            <DataTable v-else-if="tab === 'least'" :columns="leastColumns" :rows="data.least_sold ?? []" />
            <DataTable v-else-if="tab === 'noMove'" :columns="noMoveColumns" :rows="data.no_movement ?? []" empty-message="Todos los productos han tenido movimiento" />
            <DataTable v-else-if="tab === 'alert'" :columns="alertColumns" :rows="data.price_below_cost ?? []" empty-message="Sin productos con precio ≤ costo" />
        </div>
    </div>
</template>
