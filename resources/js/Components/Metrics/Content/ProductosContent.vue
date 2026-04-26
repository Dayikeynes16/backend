<script setup>
import { computed, ref, watch } from 'vue';
import KpiCard from '@/Components/Metrics/KpiCard.vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';
import DataTable from '@/Components/Metrics/DataTable.vue';
import EmptyState from '@/Components/Metrics/EmptyState.vue';
import StatusFilterChips from '@/Components/Metrics/StatusFilterChips.vue';
import MetricLegendCard from '@/Components/Metrics/MetricLegendCard.vue';
import ProductDetailModal from '@/Components/Productos/ProductDetailModal.vue';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({
    data: Object,
    noMovementDays: Number,
    filters: Object,
    tenant: Object,
    snapshotRoute: { type: String, default: null }, // si null, click en fila no hace nada
});

const summary = computed(() => props.data?.summary ?? {});
const allProducts = computed(() => props.data?.all_products ?? []);

const presetLabels = {
    today: 'Hoy', yesterday: 'Ayer', last_7_days: 'Últimos 7 días',
    this_month: 'Este mes', last_month: 'Mes pasado', this_year: 'Este año',
};
const rangeLabel = computed(() => {
    if (!props.filters) return '';
    if (props.filters.isCustom?.value) return `${props.filters.from.value} → ${props.filters.to.value}`;
    return presetLabels[props.filters.preset?.value] || '';
});

// ─── Tabla maestra: sort + search + pagination ────────────────────────
const search = ref('');
const sortKey = ref('revenue'); // Default: Ingreso (decisión de usuario)
const sortDir = ref('desc');
const page = ref(1);
const PAGE_SIZE = 25;

watch([search, () => props.filters?.statuses?.value], () => { page.value = 1; });

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return allProducts.value;
    return allProducts.value.filter(p =>
        (p.product_name || '').toLowerCase().includes(q) ||
        (p.category_name || '').toLowerCase().includes(q)
    );
});

const sorted = computed(() => {
    const arr = [...filtered.value];
    const k = sortKey.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    arr.sort((a, b) => {
        const av = a[k] ?? 0;
        const bv = b[k] ?? 0;
        if (typeof av === 'string' && typeof bv === 'string') return dir * av.localeCompare(bv);
        return dir * ((av || 0) - (bv || 0));
    });
    return arr;
});

const totalPages = computed(() => Math.max(1, Math.ceil(sorted.value.length / PAGE_SIZE)));
const paginated = computed(() => {
    const start = (page.value - 1) * PAGE_SIZE;
    return sorted.value.slice(start, start + PAGE_SIZE);
});

const setSort = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'desc';
    }
};

const sortIcon = (key) => {
    if (sortKey.value !== key) return '';
    return sortDir.value === 'asc' ? '↑' : '↓';
};

// Avatar de producto (color por hash, igual que categorías)
const productColors = [
    'from-rose-500 to-red-600',
    'from-orange-500 to-amber-600',
    'from-amber-400 to-yellow-500',
    'from-emerald-500 to-teal-600',
    'from-sky-500 to-blue-600',
    'from-indigo-500 to-violet-600',
    'from-purple-500 to-fuchsia-600',
    'from-pink-500 to-rose-500',
];
const colorFor = (name) => {
    let hash = 0;
    const s = String(name || '');
    for (let i = 0; i < s.length; i++) { hash = ((hash << 5) - hash) + s.charCodeAt(i); hash |= 0; }
    return productColors[Math.abs(hash) % productColors.length];
};
const initialFor = (name) => String(name || '?').trim().charAt(0).toUpperCase();

// ─── Charts ────────────────────────────────────────────────────────────
const topRevSeries = computed(() => [{
    name: 'Ingreso',
    data: (props.data?.top_by_revenue ?? []).map(p => ({ x: p.product_name, y: p.revenue })),
}]);
const topProfitSeries = computed(() => [{
    name: 'Ganancia',
    data: (props.data?.top_by_profit ?? []).map(p => ({ x: p.product_name, y: p.profit })),
}]);
const categorySeries = computed(() => (props.data?.category_share ?? []).map(c => c.revenue));
const categoryLabels = computed(() => (props.data?.category_share ?? []).map(c => c.category));

const barOptions = (color = '#dc2626') => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: [color],
    plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '70%' } },
    dataLabels: { enabled: false },
    xaxis: { labels: { formatter: v => formatCurrency(v) } },
    yaxis: { labels: { style: { fontSize: '11px' } } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 3 },
});

const donutOptions = computed(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: categoryLabels.value,
    legend: { position: 'bottom', fontSize: '11px' },
    plotOptions: { pie: { donut: { size: '65%' } } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    dataLabels: { enabled: false },
    colors: ['#dc2626', '#f97316', '#eab308', '#10b981', '#0ea5e9', '#6366f1', '#a855f7', '#ec4899'],
}));

// ─── Modal de detalle (lazy fetch del producto completo) ──────────────
const detailOpen = ref(false);
const selectedProductFull = ref(null);
const selectedRangeStats = ref(null);
const fetchingDetail = ref(false);

const openDetail = async (row) => {
    if (!props.snapshotRoute || !props.tenant) return; // empresa: deshabilitado
    selectedRangeStats.value = {
        revenue: row.revenue,
        gross_profit: row.gross_profit,
        margin_pct: row.margin_pct,
        quantity_kg: row.quantity_kg,
        quantity_units: row.quantity_units,
        ticket_count: row.ticket_count,
        range_label: rangeLabel.value,
    };
    detailOpen.value = true;
    fetchingDetail.value = true;
    selectedProductFull.value = null;
    try {
        const url = route(props.snapshotRoute, [props.tenant.slug, row.product_id]);
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.ok) selectedProductFull.value = await res.json();
    } finally {
        fetchingDetail.value = false;
    }
};

const closeDetail = () => {
    detailOpen.value = false;
    setTimeout(() => {
        selectedProductFull.value = null;
        selectedRangeStats.value = null;
    }, 300);
};

// ─── Tabs auxiliares (sin movimiento + precio≤costo) ───────────────────
const auxTab = ref('noMove');
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
const auxTabs = computed(() => [
    { key: 'noMove', label: `Sin movimiento (${props.noMovementDays ?? 30}d)`, count: (props.data?.no_movement ?? []).length },
    { key: 'alert', label: 'Precio ≤ Costo', count: (props.data?.price_below_cost ?? []).length, alert: true },
]);

// ─── Leyendas ──────────────────────────────────────────────────────────
const legendItems = [
    { label: 'Ingreso', formula: 'Σ subtotal', description: 'Suma de subtotales (precio × cantidad) de cada línea de venta.' },
    { label: 'Costo', formula: 'Σ costo × cantidad', description: 'Costo unitario congelado al momento de la venta. Si la línea no tiene costo registrado, se cuenta como 0.' },
    { label: 'Ganancia', formula: 'Ingreso − Costo', description: 'Ganancia bruta (antes de gastos generales).' },
    { label: 'Margen %', formula: 'Ganancia ÷ Ingreso', description: 'Porcentaje del ingreso que queda como ganancia bruta.' },
    { label: 'Kilos', formula: 'Σ qty (peso)', description: 'Suma cuando la unidad es kg/g/l/ml. Para presentaciones, se cuenta el contenido real (ej. "medio queso" suma 0.5 kg).' },
    { label: 'Unidades', formula: 'Σ qty (piezas)', description: 'Suma cuando la unidad es pieza, corte o "número de presentaciones".' },
    { label: 'Tickets', formula: 'COUNT DISTINCT', description: 'Número de ventas distintas en las que apareció el producto.' },
    { label: 'Estados', formula: 'configurable', description: 'Solo se cuentan ventas con los estados activos en los chips superiores. Por defecto, solo Completadas.' },
];

const noStatuses = computed(() => (props.filters?.statuses?.value || []).length === 0);
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <!-- Filtro de estados (chips) -->
        <StatusFilterChips v-if="filters" :filters="filters" />

        <!-- Aviso si no hay statuses seleccionados -->
        <div v-if="noStatuses" class="rounded-2xl bg-amber-50 px-5 py-4 ring-1 ring-amber-200">
            <p class="text-sm font-semibold text-amber-800">No hay estados seleccionados.</p>
            <p class="mt-0.5 text-xs text-amber-700">Selecciona al menos uno (Completadas, Pendientes, Canceladas) para ver datos.</p>
        </div>

        <!-- Hero KPIs -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <KpiCard label="Ingreso" tone="blue"
                tooltip="Suma de subtotales (precio × cantidad) de las líneas de venta del rango.">
                {{ formatCurrency(summary.revenue) }}
            </KpiCard>
            <KpiCard label="Ganancia" tone="green"
                tooltip="Ingreso − Costo. El costo viene congelado de cada línea al momento de la venta.">
                {{ formatCurrency(summary.gross_profit) }}
            </KpiCard>
            <KpiCard label="Margen" tone="green"
                tooltip="Ganancia ÷ Ingreso × 100. Porcentaje del ingreso que queda como ganancia bruta.">
                {{ Number(summary.margin_pct ?? 0).toFixed(1) }}%
            </KpiCard>
            <KpiCard label="Kilos vendidos" tone="amber"
                tooltip="Cantidad cuando la línea fue vendida en kg/g/l/ml. Para presentaciones (ej. medio queso 500 g), se acumula el contenido real.">
                {{ formatNumber(summary.quantity_kg, 3) }} kg
            </KpiCard>
            <KpiCard label="Unidades vendidas" tone="red"
                tooltip="Cantidad cuando la línea fue vendida por pieza, corte o como presentación (× N).">
                × {{ formatNumber(summary.quantity_units, 0) }}
            </KpiCard>
        </div>

        <!-- Tabla maestra -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <!-- Toolbar de la tabla -->
            <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-bold text-gray-900">Todos los productos</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ sorted.length }} {{ sorted.length === 1 ? 'producto vendido' : 'productos vendidos' }} en el rango.</p>
                </div>
                <div class="relative sm:w-72">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    <input v-model="search" type="text" placeholder="Buscar producto o categoría..." class="w-full rounded-xl border-gray-200 bg-white py-2 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                </div>
            </div>

            <!-- Tabla -->
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50/60">
                        <tr>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('product_name')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Producto <span class="text-red-600">{{ sortIcon('product_name') }}</span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('gross_profit')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Ganancia <span class="text-red-600">{{ sortIcon('gross_profit') }}</span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('revenue')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Ingreso <span class="text-red-600">{{ sortIcon('revenue') }}</span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('quantity_kg')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Vendido <span class="text-red-600">{{ sortIcon('quantity_kg') }}</span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('cost')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Costo <span class="text-red-600">{{ sortIcon('cost') }}</span>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">
                                <button type="button" @click="setSort('ticket_count')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Tickets <span class="text-red-600">{{ sortIcon('ticket_count') }}</span>
                                </button>
                            </th>
                            <th v-if="snapshotRoute" class="w-8 px-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="p in paginated" :key="p.product_id"
                            :class="['transition', snapshotRoute ? 'cursor-pointer hover:bg-gray-50/80' : '']"
                            @click="openDetail(p)">
                            <!-- Producto -->
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div :class="['flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl ring-1 ring-gray-200/60', !p.image_url && `bg-gradient-to-br ${colorFor(p.product_name)}`]">
                                        <img v-if="p.image_url" :src="p.image_url" :alt="p.product_name" class="h-full w-full object-cover" />
                                        <span v-else class="text-sm font-bold text-white">{{ initialFor(p.product_name) }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-gray-900">{{ p.product_name }}</p>
                                        <p v-if="p.category_name" class="truncate text-[11px] text-orange-700">{{ p.category_name }}</p>
                                    </div>
                                </div>
                            </td>
                            <!-- Ganancia -->
                            <td class="px-4 py-3 text-right">
                                <p class="font-mono text-sm font-bold tabular-nums text-emerald-700">{{ formatCurrency(p.gross_profit) }}</p>
                                <p v-if="p.has_missing_cost" class="text-[10px] font-medium text-amber-600" title="Algunas líneas no tenían costo registrado">⚠ costo parcial</p>
                                <p v-else-if="p.revenue > 0" class="text-[10px] text-emerald-700/70">{{ Number(p.margin_pct).toFixed(1) }}% margen</p>
                            </td>
                            <!-- Ingreso -->
                            <td class="px-4 py-3 text-right font-mono text-sm font-semibold tabular-nums text-gray-900">{{ formatCurrency(p.revenue) }}</td>
                            <!-- Vendido (kg + unidades stack) -->
                            <td class="px-4 py-3 text-right">
                                <p v-if="p.quantity_kg > 0" class="font-mono text-sm font-semibold tabular-nums text-amber-700">{{ Number(p.quantity_kg).toFixed(3) }} kg</p>
                                <p v-if="p.quantity_units > 0" :class="['font-mono text-sm tabular-nums text-violet-700', p.quantity_kg > 0 ? 'text-xs' : 'font-semibold']">× {{ p.quantity_units }}</p>
                                <p v-if="p.quantity_kg === 0 && p.quantity_units === 0" class="text-xs text-gray-300">—</p>
                            </td>
                            <!-- Costo -->
                            <td class="px-4 py-3 text-right font-mono text-sm tabular-nums text-gray-500">{{ formatCurrency(p.cost) }}</td>
                            <!-- Tickets -->
                            <td class="px-4 py-3 text-right font-mono text-sm tabular-nums text-gray-700">{{ p.ticket_count }}</td>
                            <!-- Chevron (solo si hay modal) -->
                            <td v-if="snapshotRoute" class="px-2 py-3 text-right">
                                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty -->
                <div v-if="sorted.length === 0" class="px-6 py-16 text-center">
                    <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                    <p class="mt-3 text-sm font-medium text-gray-400">{{ search ? `Ningún producto coincide con "${search}".` : 'Sin ventas en el rango con los estados seleccionados.' }}</p>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-between border-t border-gray-100 px-5 py-3">
                <p class="text-xs text-gray-500">Página {{ page }} de {{ totalPages }} · {{ sorted.length }} productos</p>
                <div class="flex gap-1">
                    <button type="button" @click="page = Math.max(1, page - 1)" :disabled="page === 1"
                        class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">Anterior</button>
                    <button type="button" @click="page = Math.min(totalPages, page + 1)" :disabled="page === totalPages"
                        class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40">Siguiente</button>
                </div>
            </div>
        </div>

        <!-- Mini gráficos: 3 columnas -->
        <div class="grid gap-6 lg:grid-cols-3">
            <ChartCard title="Top 10 por ingreso" subtitle="Los más facturados.">
                <apexchart v-if="topRevSeries[0].data.length" type="bar" :height="50 + topRevSeries[0].data.length * 32" :options="barOptions('#2563eb')" :series="topRevSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
            <ChartCard title="Top 10 por ganancia" subtitle="Los más rentables del rango.">
                <apexchart v-if="topProfitSeries[0].data.length" type="bar" :height="50 + topProfitSeries[0].data.length * 32" :options="barOptions('#059669')" :series="topProfitSeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
            <ChartCard title="Reparto por categoría" subtitle="Participación en el ingreso.">
                <apexchart v-if="categorySeries.length" type="donut" height="320" :options="donutOptions" :series="categorySeries" />
                <div v-else class="py-8"><EmptyState /></div>
            </ChartCard>
        </div>

        <!-- Tabs auxiliares: sin movimiento + precio≤costo -->
        <div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button v-for="t in auxTabs" :key="t.key" @click="auxTab = t.key"
                    :class="['rounded-full px-4 py-1.5 text-xs font-semibold transition',
                        auxTab === t.key
                            ? (t.alert ? 'bg-amber-600 text-white' : 'bg-gray-900 text-white')
                            : (t.alert ? 'bg-amber-100 text-amber-700 hover:bg-amber-200' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')]">
                    {{ t.label }} <span class="ml-1 opacity-70">({{ t.count }})</span>
                </button>
            </div>
            <DataTable v-if="auxTab === 'noMove'" :columns="noMoveColumns" :rows="data.no_movement ?? []" empty-message="Todos los productos han tenido movimiento" />
            <DataTable v-else-if="auxTab === 'alert'" :columns="alertColumns" :rows="data.price_below_cost ?? []" empty-message="Sin productos con precio ≤ costo" />
        </div>

        <!-- Leyenda explicativa -->
        <MetricLegendCard :items="legendItems" />

        <!-- Modal de detalle (solo si snapshotRoute está disponible) -->
        <ProductDetailModal v-if="snapshotRoute"
            :show="detailOpen"
            :product="selectedProductFull"
            :tenant="tenant"
            :range-stats="selectedRangeStats"
            @close="closeDetail" />
    </div>
</template>
