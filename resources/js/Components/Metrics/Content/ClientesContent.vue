<script setup>
import { computed, ref } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object, inactiveDays: Number });

const summary = computed(() => props.data?.summary ?? {});
const tab = ref('top');

const topSeries = computed(() => [{
    name: 'Comprado',
    data: (props.data?.top_customers ?? []).map(c => ({ x: c.name, y: c.total })),
}]);
const topOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: { bar: { borderRadius: 4, horizontal: true } },
    dataLabels: { enabled: false },
    xaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    grid: { borderColor: '#f3f4f6' },
};

const agingSeries = computed(() => {
    const a = props.data?.aging ?? {};
    return [a['0-30'] ?? 0, a['31-60'] ?? 0, a['61-plus'] ?? 0];
});
const agingOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#10b981', '#f59e0b', '#dc2626'],
    plotOptions: { bar: { borderRadius: 4, distributed: true, columnWidth: '50%' } },
    xaxis: { categories: ['0-30 días', '31-60 días', '61+ días'] },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    dataLabels: { enabled: false },
    legend: { show: false },
    grid: { borderColor: '#f3f4f6' },
};

const topColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'tickets', label: 'Tickets', format: 'number', align: 'right' },
    { key: 'total', label: 'Total', format: 'currency', align: 'right', strong: true },
];
const balanceColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'pending_sales', label: 'Ventas pdtes.', format: 'number', align: 'right' },
    { key: 'balance', label: 'Saldo', format: 'currency', align: 'right', strong: true },
    { key: 'last_sale', label: 'Última compra', format: 'date', align: 'right' },
];
const newColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'created_at', label: 'Registrado', format: 'datetime', align: 'right' },
];
const inactiveColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'last_sale', label: 'Última compra', format: 'date', align: 'right' },
];

const tabs = [
    { key: 'top', label: 'Top compradores', count: (props.data?.top_customers ?? []).length },
    { key: 'balance', label: 'Con saldo', count: (props.data?.with_balance ?? []).length },
    { key: 'new', label: 'Nuevos', count: (props.data?.new_customers ?? []).length },
    { key: 'inactive', label: `Inactivos (${props.inactiveDays ?? 30}d)`, count: (props.data?.inactive ?? []).length },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <KpiCard label="Que compraron" tone="blue">{{ formatNumber(summary.buying_customers) }}</KpiCard>
            <KpiCard label="Nuevos" tone="green">{{ formatNumber(summary.new_customers) }}</KpiCard>
            <KpiCard label="Con saldo" tone="amber">{{ formatNumber(summary.customers_with_balance) }}</KpiCard>
            <KpiCard label="Saldo total" tone="red">{{ formatCurrency(summary.total_pending_balance) }}</KpiCard>
            <KpiCard label="Ticket promedio">{{ formatCurrency(summary.avg_ticket_per_customer) }}</KpiCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Top 10 clientes por monto">
                <apexchart v-if="topSeries[0].data.length" type="bar" :height="50 + topSeries[0].data.length * 30" :options="topOptions" :series="topSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
            <ChartCard title="Antigüedad de saldos" subtitle="Cuentas por cobrar por bucket">
                <apexchart type="bar" height="280" :options="agingOptions" :series="[{ name: 'Saldo', data: agingSeries }]" />
            </ChartCard>
        </div>

        <div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                    :class="['rounded-full px-4 py-1.5 text-xs font-semibold transition',
                        tab === t.key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                    {{ t.label }} <span class="ml-1 opacity-70">({{ t.count }})</span>
                </button>
            </div>
            <DataTable v-if="tab === 'top'" :columns="topColumns" :rows="data.top_customers ?? []" />
            <DataTable v-else-if="tab === 'balance'" :columns="balanceColumns" :rows="data.with_balance ?? []" />
            <DataTable v-else-if="tab === 'new'" :columns="newColumns" :rows="data.new_customers ?? []" />
            <DataTable v-else-if="tab === 'inactive'" :columns="inactiveColumns" :rows="data.inactive ?? []" />
        </div>
    </div>
</template>
