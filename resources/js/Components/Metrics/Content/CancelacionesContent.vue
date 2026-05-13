<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import { formatCurrency, formatNumber, formatPercent } from '@/composables/useCurrency';

const props = defineProps({
    data: Object,
    history: Object,
    range: Object,
    /** 'sucursal' | 'empresa' — controla si se muestra la tabla "por sucursal". */
    scope: { type: String, default: 'sucursal' },
});

// --- KPIs (con deltas vs periodo previo) ---
const current = computed(() => props.data?.summary?.current ?? {});
const previous = computed(() => props.data?.summary?.previous ?? {});

const delta = (a, b) => {
    if (!b || b === 0) return null;
    return Number((((a ?? 0) - b) / b * 100).toFixed(1));
};

// --- Chart: cancelaciones por día (actual vs previo) ---
const daily = computed(() => props.data?.daily ?? []);
const previousDaily = computed(() => props.data?.previous_daily ?? []);

const chartMetric = ref('amount'); // 'amount' | 'count'

const chartSeries = computed(() => [
    { name: 'Periodo actual', data: daily.value.map(d => d[chartMetric.value]) },
    { name: 'Periodo previo', data: previousDaily.value.map(d => d[chartMetric.value]) },
]);

const chartOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', stacked: false },
    colors: ['#dc2626', '#fca5a5'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    stroke: { width: [0, 0] },
    xaxis: {
        categories: daily.value.map(d => d.day.slice(5)),
        labels: { style: { fontSize: '10px' } },
    },
    yaxis: {
        labels: {
            formatter: v => chartMetric.value === 'amount' ? formatCurrency(v) : formatNumber(v),
        },
    },
    tooltip: {
        y: { formatter: v => chartMetric.value === 'amount' ? formatCurrency(v) : formatNumber(v) },
    },
    legend: { position: 'top', horizontalAlign: 'right' },
    grid: { borderColor: '#f3f4f6' },
}));

// --- Tablas ---
const reasonColumns = [
    { key: 'reason', label: 'Motivo', strong: true },
    { key: 'count', label: '#', format: 'number', align: 'right' },
    { key: 'amount', label: 'Monto', format: 'currency', align: 'right' },
    { key: 'pct_of_count', label: '% del total', format: 'percent', align: 'right' },
];

const cashierColumns = [
    { key: 'name', label: 'Usuario', strong: true },
    { key: 'cancelled_count', label: 'Canceladas', format: 'number', align: 'right' },
    { key: 'cancelled_amount', label: 'Monto', format: 'currency', align: 'right' },
    { key: 'requested_count', label: 'Solicitadas', format: 'number', align: 'right' },
];

const branchColumns = [
    { key: 'name', label: 'Sucursal', strong: true },
    { key: 'cancelled_count', label: '#', format: 'number', align: 'right' },
    { key: 'cancelled_amount', label: 'Monto', format: 'currency', align: 'right' },
    { key: 'pct_of_sales', label: '% sobre sus ventas', format: 'percent', align: 'right' },
];

// --- Historial: cursor + cargar más (mismo patrón que /sucursal/pagos) ---
const items = ref([...(props.history?.data ?? [])]);
const nextCursor = ref(props.history?.next_cursor ?? null);
const loadingMore = ref(false);
const expanded = ref(new Set());

const toggle = (id) => {
    if (expanded.value.has(id)) {
        expanded.value.delete(id);
    } else {
        expanded.value.add(id);
    }
    expanded.value = new Set(expanded.value);
};

const loadMore = () => {
    if (!nextCursor.value || loadingMore.value) return;
    loadingMore.value = true;
    // Reusa la ruta actual añadiendo el cursor; preserveState evita
    // perder los filtros del rango/branch.
    router.reload({
        only: ['history'],
        data: { cursor: nextCursor.value },
        onSuccess: (page) => {
            const incoming = page.props.history;
            if (incoming?.data) {
                const existing = new Set(items.value.map(s => s.id));
                items.value.push(...incoming.data.filter(s => !existing.has(s.id)));
                nextCursor.value = incoming.next_cursor ?? null;
            }
            loadingMore.value = false;
        },
        onError: () => { loadingMore.value = false; },
    });
};

const formatDateTime = (d) => d ? new Date(d).toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true,
}) : '—';
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">

        <!-- KPIs -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="# Canceladas" tone="red"
                :delta="delta(current.cancelled_count, previous.cancelled_count)">
                {{ formatNumber(current.cancelled_count ?? 0) }}
            </KpiCard>
            <KpiCard label="Monto cancelado" tone="red"
                :delta="delta(current.cancelled_amount, previous.cancelled_amount)">
                {{ formatCurrency(current.cancelled_amount ?? 0) }}
            </KpiCard>
            <KpiCard label="% sobre ventas" tone="amber"
                tooltip="Monto cancelado dividido entre las ventas brutas del periodo (completadas + pendientes).">
                {{ current.pct_of_sales !== null ? `${current.pct_of_sales}%` : '—' }}
            </KpiCard>
            <KpiCard label="Tiempo prom. de respuesta" tone="neutral"
                :hint="current.from_request_count
                    ? `${current.from_request_count} de solicitud · ${current.direct_count} directas`
                    : `${current.direct_count ?? 0} directas`"
                tooltip="Minutos promedio entre solicitar la cancelación y aprobarla. Solo cuenta las que pasaron por solicitud.">
                {{ current.avg_response_minutes !== null && current.avg_response_minutes !== undefined
                    ? `${current.avg_response_minutes} min` : '—' }}
            </KpiCard>
        </div>

        <!-- Chart por día -->
        <ChartCard title="Cancelaciones por día"
            :subtitle="chartMetric === 'amount' ? 'Monto cancelado por día del periodo.' : 'Conteo de cancelaciones por día.'">
            <template #actions>
                <div class="flex gap-1">
                    <button type="button" @click="chartMetric = 'amount'"
                        :class="['rounded-lg px-2.5 py-1 text-xs font-semibold transition',
                            chartMetric === 'amount' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                        Monto
                    </button>
                    <button type="button" @click="chartMetric = 'count'"
                        :class="['rounded-lg px-2.5 py-1 text-xs font-semibold transition',
                            chartMetric === 'count' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                        Conteo
                    </button>
                </div>
            </template>
            <apexchart v-if="daily.length" type="bar" height="320" :options="chartOptions" :series="chartSeries" />
            <div v-else class="py-8"><EmptyState message="Sin cancelaciones en el rango." /></div>
        </ChartCard>

        <!-- Motivos -->
        <div>
            <h3 class="mb-2 text-sm font-bold text-gray-900">Motivos del periodo</h3>
            <DataTable :columns="reasonColumns" :rows="data.by_reason ?? []" :page-size="10"
                empty-message="No hay motivos registrados en el rango." />
        </div>

        <!-- Por cajero -->
        <div>
            <h3 class="mb-2 text-sm font-bold text-gray-900">Por usuario</h3>
            <p class="mb-2 text-xs text-gray-500">Cancelaciones aprobadas (admin) y solicitudes (cajero) sobre las ventas canceladas del periodo.</p>
            <DataTable :columns="cashierColumns" :rows="data.by_cashier ?? []" :page-size="10"
                empty-message="Nadie canceló ni solicitó cancelaciones en el rango." />
        </div>

        <!-- Por sucursal (solo en empresa) -->
        <div v-if="scope === 'empresa' && data.by_branch">
            <h3 class="mb-2 text-sm font-bold text-gray-900">Por sucursal</h3>
            <DataTable :columns="branchColumns" :rows="data.by_branch" :page-size="20"
                empty-message="Sin cancelaciones por sucursal en el rango." />
        </div>

        <!-- Historial detallado -->
        <div>
            <h3 class="mb-2 text-sm font-bold text-gray-900">Detalle del periodo</h3>
            <div v-if="items.length === 0" class="rounded-2xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-400">
                Sin cancelaciones en el rango.
            </div>
            <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="divide-y divide-gray-100">
                    <div v-for="sale in items" :key="sale.id">
                        <button type="button" @click="toggle(sale.id)" class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-gray-50">
                            <span class="font-mono text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <span v-if="sale.branch?.name && scope === 'empresa'" class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">{{ sale.branch.name }}</span>
                            <span class="text-xs text-gray-500">{{ formatDateTime(sale.cancelled_at) }}</span>
                            <span class="ml-auto font-mono text-sm font-bold tabular-nums text-gray-900">{{ formatCurrency(sale.total) }}</span>
                            <svg :class="['h-4 w-4 text-gray-400 transition', expanded.has(sale.id) ? 'rotate-180' : '']" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </button>
                        <div v-if="expanded.has(sale.id)" class="bg-gray-50/50 px-4 py-3 text-xs">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="font-semibold text-gray-500">Cancelada por</p>
                                    <p class="text-gray-900">{{ sale.cancelled_by_user?.name ?? '—' }}</p>
                                    <p class="mt-1 font-semibold text-gray-500">Motivo</p>
                                    <p class="text-gray-900">{{ sale.cancel_reason || 'Sin motivo' }}</p>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-500">Solicitada por</p>
                                    <p class="text-gray-900">{{ sale.cancel_requested_by_user?.name ?? '— (cancelación directa)' }}</p>
                                    <p v-if="sale.cancel_request_reason" class="mt-1 font-semibold text-gray-500">Razón de la solicitud</p>
                                    <p v-if="sale.cancel_request_reason" class="text-gray-900">{{ sale.cancel_request_reason }}</p>
                                    <p v-if="sale.customer?.name" class="mt-1 font-semibold text-gray-500">Cliente</p>
                                    <p v-if="sale.customer?.name" class="text-gray-900">{{ sale.customer.name }}</p>
                                </div>
                            </div>
                            <div v-if="sale.items?.length" class="mt-3">
                                <p class="mb-1 font-semibold text-gray-500">Productos</p>
                                <ul class="space-y-0.5 text-gray-700">
                                    <li v-for="(item, i) in sale.items" :key="i" class="flex justify-between">
                                        <span>{{ item.quantity }} × {{ item.product_name }}</span>
                                        <span class="tabular-nums">{{ formatCurrency(item.subtotal) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="nextCursor" class="flex justify-center border-t border-gray-100 bg-gray-50 px-4 py-2.5">
                    <button type="button" @click="loadMore" :disabled="loadingMore"
                        class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100 disabled:opacity-50">
                        {{ loadingMore ? 'Cargando…' : 'Cargar más' }}
                    </button>
                </div>
            </div>
        </div>

    </div>
</template>
