<script setup>
import { computed, nextTick, ref } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import MarginCoverageBanner from '@/Components/Metrics/MarginCoverageBanner.vue';
import TimeSeriesCard from '@/Components/Metrics/TimeSeriesCard.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({ data: Object, compare: Boolean });

const current = computed(() => props.data?.summary?.current ?? {});
const previous = computed(() => props.data?.summary?.previous ?? {});
const pct = (a, b) => (!b ? null : ((a - b) / b) * 100);
const d = (a, b) => (props.compare ? pct(a, b) : null);

const itemsWithCost = computed(() => current.value.items_with_cost ?? 0);
const itemsWithoutCost = computed(() => current.value.items_without_cost ?? 0);

const profitHint = computed(() => {
    const withCost = formatNumber(itemsWithCost.value);
    if (itemsWithoutCost.value > 0) {
        return `Basado en ${withCost} items con costo · ${formatNumber(itemsWithoutCost.value)} excluidos`;
    }
    return `Basado en ${withCost} items con costo registrado`;
});

const profitCurrent = computed(() => (props.data?.daily_gross_profit ?? []).map(r => ({ day: r.day, value: Number(r.gross_profit ?? 0) })));
const profitPrevious = computed(() => (props.data?.previous_daily_gross_profit ?? []).map(r => ({ day: r.day, value: Number(r.gross_profit ?? 0) })));

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

const onlyUncosted = ref(false);
const productsTableRef = ref(null);

const filteredProducts = computed(() => {
    const rows = props.data?.by_product ?? [];
    return onlyUncosted.value ? rows.filter(r => r.has_missing_cost) : rows;
});

const focusUncostedTable = async () => {
    onlyUncosted.value = true;
    await nextTick();
    productsTableRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <MarginCoverageBanner :items-without-cost="itemsWithoutCost" @filter-uncosted="focusUncostedTable" />

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Ganancia bruta" tone="green"
                :delta="d(current.gross_profit, previous.gross_profit)"
                :hint="profitHint">
                {{ formatCurrency(current.gross_profit) }}
            </KpiCard>
            <KpiCard label="% Margen global" tone="green"
                :delta="d(current.margin_pct, previous.margin_pct)"
                hint="Ganancia ÷ ingreso (items con costo)">
                {{ current.margin_pct ?? 0 }}%
            </KpiCard>
            <KpiCard label="Margen por ticket"
                :delta="d(current.avg_margin_per_ticket, previous.avg_margin_per_ticket)"
                hint="Ganancia bruta entre tickets del rango">
                {{ formatCurrency(current.avg_margin_per_ticket) }}
            </KpiCard>
            <KpiCard label="Items sin costo" tone="amber" hint="No entran al cálculo de margen">
                {{ formatNumber(itemsWithoutCost) }}
            </KpiCard>
        </div>

        <TimeSeriesCard
            title="Ganancia bruta diaria"
            subtitle="Ingresos − costo al momento de venta. Solo items con costo registrado."
            :current="profitCurrent"
            :previous="profitPrevious"
            value-label="Ganancia"
            :format-value="formatCurrency"
            color="#059669"
            :compare="props.compare"
        />

        <ChartCard title="Margen % por categoría"
            subtitle="(Ganancia ÷ ingreso) por categoría. Solo items con costo.">
            <apexchart v-if="categorySeries[0].data.length" type="bar" :height="50 + (categorySeries[0].data.length * 36)" :options="categoryOptions" :series="categorySeries" />
            <div v-else class="py-8"><EmptyState /></div>
        </ChartCard>

        <div ref="productsTableRef">
            <ChartCard title="Rentabilidad por producto"
                subtitle="Los 100 más rentables del rango. Solo items con costo registrado.">
                <template #actions>
                    <label v-if="data.by_product?.some(r => r.has_missing_cost)"
                        class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-gray-600">
                        <input type="checkbox" v-model="onlyUncosted"
                            class="rounded border-gray-300 text-red-600 focus:ring-red-500" />
                        Ver solo productos con items sin costo
                    </label>
                </template>
                <DataTable :columns="productColumns" :rows="filteredProducts"
                    empty-message="Sin productos en este rango">
                    <template #cell-product_name="{ row }">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-900">{{ row.product_name }}</span>
                            <span v-if="row.has_missing_cost"
                                :title="'Algunas ventas de este producto no tenían costo registrado al momento de la venta. Esas ventas no entraron en el cálculo de ganancia.'"
                                class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800">
                                sin costo
                            </span>
                        </div>
                    </template>
                </DataTable>
            </ChartCard>
        </div>
    </div>
</template>
