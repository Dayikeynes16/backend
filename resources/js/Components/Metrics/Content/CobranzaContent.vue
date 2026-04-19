<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object });

const summary = computed(() => props.data?.summary ?? {});

const dailySeries = computed(() => [{
    name: 'Cobrado',
    data: (props.data?.daily_collection ?? []).map(r => ({ x: r.day, y: r.amount })),
}]);
const dailyOptions = {
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#2563eb'],
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    xaxis: { type: 'datetime' },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) }, x: { format: 'dd MMM' } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 3 },
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

const columns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'pending_sales', label: 'Ventas pdtes.', format: 'number', align: 'right' },
    { key: 'balance', label: 'Saldo', format: 'currency', align: 'right', strong: true },
    { key: 'age_days', label: 'Antigüedad (días)', format: 'number', align: 'right' },
    { key: 'last_sale', label: 'Última venta', format: 'date', align: 'right' },
    { key: 'last_payment', label: 'Último pago', format: 'date', align: 'right' },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Total cobrado" tone="blue">{{ formatCurrency(summary.total_collected) }}</KpiCard>
            <KpiCard label="# Pagos" :hint="`${summary.payment_count ?? 0} registros`">
                {{ formatNumber(summary.payment_count) }}
            </KpiCard>
            <KpiCard label="Saldo pendiente" tone="red">{{ formatCurrency(summary.total_pending_balance) }}</KpiCard>
            <KpiCard label="Días prom. de cobro" tone="amber">
                {{ summary.avg_days_to_collect !== null ? `${summary.avg_days_to_collect} d` : '—' }}
            </KpiCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <ChartCard title="Cobranza diaria" subtitle="Pagos aplicados por día (todos los métodos).">
                    <apexchart v-if="dailySeries[0].data.length" type="area" height="280" :options="dailyOptions" :series="dailySeries" />
                    <div v-else class="py-8"><EmptyState /></div>
                </ChartCard>
            </div>
            <ChartCard title="Antigüedad de saldos" subtitle="Saldos por cobrar por antigüedad. Requiere cliente asignado a la venta.">
                <apexchart type="bar" height="280" :options="agingOptions" :series="[{ name: 'Saldo', data: agingSeries }]" />
            </ChartCard>
        </div>

        <ChartCard title="Cuentas por cobrar" subtitle="Clientes con saldo pendiente: última compra, último pago, antigüedad.">
            <DataTable :columns="columns" :rows="data.receivables ?? []" />
        </ChartCard>
    </div>
</template>
