<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object });

const summary = computed(() => props.data?.summary ?? {});

const diffSeries = computed(() => [{
    name: 'Diferencia',
    data: (props.data?.daily_differences ?? []).map(r => ({ x: r.day, y: r.difference })),
}]);
const diffOptions = {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#dc2626'],
    plotOptions: {
        bar: {
            borderRadius: 3,
            columnWidth: '50%',
            colors: { ranges: [{ from: -999999, to: -0.01, color: '#dc2626' }, { from: 0, to: 999999, color: '#10b981' }] },
        },
    },
    dataLabels: { enabled: false },
    xaxis: { type: 'datetime' },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) }, x: { format: 'dd MMM' } },
    grid: { borderColor: '#f3f4f6' },
};

const columns = [
    { key: 'closed_at', label: 'Cerrado', format: 'datetime', strong: true },
    { key: 'cashier', label: 'Cajero' },
    { key: 'opening_amount', label: 'Apertura', format: 'currency', align: 'right' },
    { key: 'expected_amount', label: 'Esperado', format: 'currency', align: 'right' },
    { key: 'declared_amount', label: 'Declarado', format: 'currency', align: 'right' },
    { key: 'difference', label: 'Dif. efectivo', format: 'currency', align: 'right', strong: true },
    { key: 'difference_card', label: 'Dif. tarjeta', format: 'currency', align: 'right' },
    { key: 'difference_transfer', label: 'Dif. transfer.', format: 'currency', align: 'right' },
    { key: 'withdrawals', label: 'Retiros', format: 'currency', align: 'right' },
];
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Turnos cerrados" tone="blue">{{ formatNumber(summary.closed_count) }}</KpiCard>
            <KpiCard label="Diferencia total" :tone="summary.total_difference < 0 ? 'red' : 'green'">
                {{ formatCurrency(summary.total_difference) }}
            </KpiCard>
            <KpiCard label="Retiros" tone="amber" :hint="`${summary.withdrawal_count ?? 0} retiros`">
                {{ formatCurrency(summary.withdrawal_total) }}
            </KpiCard>
            <KpiCard label="Mayor diferencia" :tone="summary.biggest_difference_shift?.difference < 0 ? 'red' : 'neutral'"
                :hint="summary.biggest_difference_shift?.cashier ?? ''">
                {{ formatCurrency(summary.biggest_difference_shift?.difference) }}
            </KpiCard>
        </div>

        <ChartCard title="Diferencia diaria (efectivo + tarjeta + transferencia)" subtitle="Declarado − esperado al cierre de turno. Solo turnos cerrados.">
            <apexchart v-if="diffSeries[0].data.length" type="bar" height="260" :options="diffOptions" :series="diffSeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <ChartCard title="Detalle de turnos" subtitle="Turno por turno: apertura, esperado, declarado, diferencia y retiros.">
            <DataTable :columns="columns" :rows="data.shifts ?? []" />
        </ChartCard>
    </div>
</template>
