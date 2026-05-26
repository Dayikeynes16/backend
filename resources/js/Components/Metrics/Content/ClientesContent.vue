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

// --- Top customers chart ---
const topCustomers = computed(() => props.data?.top_customers ?? []);
const hasEnoughForChart = computed(() => topCustomers.value.length > 3);
const topSeries = computed(() => [{
    name: 'Comprado',
    data: topCustomers.value.map(c => ({ x: c.name, y: c.total })),
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

// --- Aging chart ---
const aging = computed(() => props.data?.aging ?? {});
const agingSeries = computed(() => [aging.value['0-30'] ?? 0, aging.value['31-60'] ?? 0, aging.value['61-plus'] ?? 0]);
const agingTotal = computed(() => aging.value.total ?? 0);
const agingRiskPct = computed(() => aging.value.risk_pct ?? 0);
const agingOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#10b981', '#f59e0b', '#dc2626'],
    plotOptions: { bar: { borderRadius: 4, distributed: true, columnWidth: '50%' } },
    xaxis: { categories: ['0–30 días', '31–60 días', '61+ días'] },
    yaxis: { labels: { formatter: v => formatCurrency(v) } },
    tooltip: { y: { formatter: v => formatCurrency(v) } },
    dataLabels: {
        enabled: true,
        formatter: (v) => {
            if (!agingTotal.value || !v) return '';
            return `${Math.round((v / agingTotal.value) * 100)}%`;
        },
        style: { fontSize: '11px', fontWeight: 700, colors: ['#374151'] },
        offsetY: -6,
    },
    legend: { show: false },
    grid: { borderColor: '#f3f4f6' },
}));

// --- Table configs ---
const topColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'tickets', label: 'Tickets', format: 'number', align: 'right' },
    { key: 'avg_ticket', label: 'Ticket prom.', format: 'currency', align: 'right' },
    { key: 'last_sale', label: 'Última compra', format: 'date', align: 'right' },
    { key: 'total', label: 'Total', format: 'currency', align: 'right', strong: true },
];
const balanceColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'pending_sales', label: 'Ventas', format: 'number', align: 'right' },
    { key: 'days_oldest', label: 'Antigüedad', align: 'right' },
    { key: 'balance', label: 'Saldo', format: 'currency', align: 'right', strong: true },
    { key: 'actions', label: '', align: 'right', width: '50px' },
];
const newColumns = [
    { key: 'name', label: 'Cliente', strong: true },
    { key: 'phone', label: 'Teléfono' },
    { key: 'created_at', label: 'Registrado', format: 'datetime', align: 'right' },
];

const tabs = computed(() => [
    { key: 'top', label: 'Top compradores', count: topCustomers.value.length },
    { key: 'balance', label: 'Con saldo', count: (props.data?.with_balance ?? []).length },
    { key: 'new', label: 'Nuevos', count: (props.data?.new_customers ?? []).length },
    { key: 'inactive', label: `Inactivos (${props.inactiveDays ?? 30}d)`, count: (props.data?.inactive ?? []).length },
]);

// --- Inactive risk helpers ---
const inactiveCustomers = computed(() => props.data?.inactive ?? []);
const daysSince = (dateStr) => {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    const now = new Date();
    return Math.floor((now - d) / (1000 * 60 * 60 * 24));
};
const riskLevel = (dateStr) => {
    const days = daysSince(dateStr);
    if (days === null) return 'unknown';
    if (days > 90) return 'critical';
    if (days > 60) return 'high';
    if (days > 30) return 'medium';
    return 'low';
};
const riskColors = {
    critical: { bg: 'bg-red-50', ring: 'ring-red-200', text: 'text-red-700', badge: 'bg-red-100 text-red-800', bar: 'bg-red-500' },
    high: { bg: 'bg-orange-50', ring: 'ring-orange-200', text: 'text-orange-700', badge: 'bg-orange-100 text-orange-800', bar: 'bg-orange-500' },
    medium: { bg: 'bg-amber-50', ring: 'ring-amber-200', text: 'text-amber-700', badge: 'bg-amber-100 text-amber-800', bar: 'bg-amber-400' },
    low: { bg: 'bg-gray-50', ring: 'ring-gray-200', text: 'text-gray-600', badge: 'bg-gray-100 text-gray-700', bar: 'bg-gray-300' },
    unknown: { bg: 'bg-gray-50', ring: 'ring-gray-200', text: 'text-gray-500', badge: 'bg-gray-100 text-gray-600', bar: 'bg-gray-300' },
};
const riskLabel = (level) => ({
    critical: 'Crítico',
    high: 'Alto',
    medium: 'Medio',
    low: 'Bajo',
    unknown: 'Sin compras',
}[level] || 'Desconocido');

const fmtDate = (v) => {
    if (!v) return 'Nunca';
    return new Date(v).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};

// --- WhatsApp helper ---
const buildWhatsappUrl = (phone, name, balance) => {
    if (!phone) return null;
    // Normalize phone: remove spaces, dashes, etc.
    let num = phone.replace(/[\s\-()]/g, '');
    // If starts with a digit and is 10 chars, assume MX number
    if (/^\d{10}$/.test(num)) num = '52' + num;
    const msg = `Hola ${name}, te recordamos que tienes un saldo pendiente de ${formatCurrency(balance)}. ¿Cuándo puedes pasar a saldar? Gracias.`;
    return `https://wa.me/${num}?text=${encodeURIComponent(msg)}`;
};

// --- Days oldest badge ---
const daysOldestBadge = (days) => {
    if (days <= 15) return { cls: 'bg-green-50 text-green-700 ring-green-200', label: `${days}d` };
    if (days <= 30) return { cls: 'bg-amber-50 text-amber-700 ring-amber-200', label: `${days}d` };
    if (days <= 60) return { cls: 'bg-orange-50 text-orange-700 ring-orange-200', label: `${days}d` };
    return { cls: 'bg-red-50 text-red-700 ring-red-200', label: `${days}d` };
};
</script>

<template>
    <div v-if="!data"><EmptyState /></div>
    <div v-else class="space-y-6">
        <!-- KPI Cards -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <KpiCard label="Compraron" tone="blue"
                hint="Clientes distintos con compra en el rango"
                tooltip="Cantidad de clientes distintos que realizaron al menos una compra (completada o pendiente) en el período seleccionado.">
                {{ formatNumber(summary.buying_customers) }}
            </KpiCard>
            <KpiCard label="Nuevos" tone="green"
                hint="Registrados en el rango"
                tooltip="Clientes dados de alta en el sistema durante el período seleccionado.">
                {{ formatNumber(summary.new_customers) }}
            </KpiCard>
            <KpiCard label="Ticket prom." tone="neutral"
                hint="Por visita de cliente"
                tooltip="Promedio del total de cada venta asociada a un cliente en el período. Incluye pendientes y a crédito, excluye canceladas.">
                {{ formatCurrency(summary.avg_ticket_per_customer) }}
            </KpiCard>
            <KpiCard label="Fiados" tone="amber"
                :hint="`${formatNumber(summary.fiados_count)} venta${summary.fiados_count !== 1 ? 's' : ''} · ${formatNumber(summary.fiados_customers)} cliente${summary.fiados_customers !== 1 ? 's' : ''}`"
                tooltip="Total de saldo pendiente de cobro en ventas a crédito (fiado). Incluye ventas activas, pendientes y completadas con saldo por cobrar. Este valor es en tiempo real, no depende del rango de fechas.">
                {{ formatCurrency(summary.fiados_total) }}
            </KpiCard>
            <KpiCard label="Saldo total" :tone="summary.total_pending_balance > 0 ? 'red' : 'neutral'"
                :hint="agingRiskPct > 0 ? `⚠ ${agingRiskPct}% con >30 días` : 'Sin saldos vencidos'"
                tooltip="Suma de todos los saldos pendientes de cobro. El porcentaje de riesgo indica cuánto del saldo tiene más de 30 días de antigüedad.">
                {{ formatCurrency(summary.total_pending_balance) }}
            </KpiCard>
            <KpiCard label="Con saldo" :tone="summary.customers_with_balance > 0 ? 'red' : 'neutral'"
                hint="Clientes que deben"
                tooltip="Cantidad de clientes distintos que tienen al menos una venta con saldo pendiente de cobro.">
                {{ formatNumber(summary.customers_with_balance) }}
            </KpiCard>
        </div>

        <!-- Charts row -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Top 10 — chart or table depending on data volume -->
            <ChartCard title="Top clientes por monto" subtitle="Clientes con mayor compra acumulada en el rango.">
                <template v-if="topCustomers.length === 0">
                    <EmptyState title="Sin clientes en este rango" hint="No hubo compras de clientes registrados." />
                </template>
                <template v-else-if="hasEnoughForChart">
                    <apexchart type="bar" :height="50 + topCustomers.length * 30" :options="topOptions" :series="topSeries" />
                </template>
                <!-- Compact cards for ≤3 clients -->
                <template v-else>
                    <div class="space-y-2">
                        <div v-for="(c, i) in topCustomers" :key="c.id"
                            class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-100 text-xs font-bold text-red-700">{{ i + 1 }}</span>
                                <div>
                                    <p class="text-sm font-bold text-gray-900">{{ c.name }}</p>
                                    <p class="text-xs text-gray-500">{{ c.tickets }} ticket{{ c.tickets !== 1 ? 's' : '' }} · prom. {{ formatCurrency(c.avg_ticket) }}</p>
                                </div>
                            </div>
                            <p class="text-lg font-bold tabular-nums text-gray-900">{{ formatCurrency(c.total) }}</p>
                        </div>
                    </div>
                </template>
            </ChartCard>

            <!-- Aging -->
            <ChartCard title="Antigüedad de saldos"
                :subtitle="`Saldo pendiente por antigüedad. Total: ${formatCurrency(agingTotal)}${agingRiskPct > 0 ? ` · ${agingRiskPct}% en riesgo (>30d)` : ''}`">
                <div v-if="agingTotal === 0" class="py-8">
                    <EmptyState title="Sin saldos pendientes" hint="¡Todos tus clientes están al corriente!" />
                </div>
                <template v-else>
                    <apexchart type="bar" height="260" :options="agingOptions" :series="[{ name: 'Saldo', data: agingSeries }]" />
                    <!-- Risk indicator bar -->
                    <div class="mt-3 flex items-center gap-3">
                        <div class="flex-1">
                            <div class="flex h-2 overflow-hidden rounded-full bg-gray-100">
                                <div v-if="aging['0-30']" class="bg-emerald-400 transition-all" :style="{ width: (aging['0-30'] / agingTotal * 100) + '%' }" />
                                <div v-if="aging['31-60']" class="bg-amber-400 transition-all" :style="{ width: (aging['31-60'] / agingTotal * 100) + '%' }" />
                                <div v-if="aging['61-plus']" class="bg-red-500 transition-all" :style="{ width: (aging['61-plus'] / agingTotal * 100) + '%' }" />
                            </div>
                        </div>
                        <span v-if="agingRiskPct > 30" class="shrink-0 rounded-full bg-red-100 px-2.5 py-0.5 text-[11px] font-bold text-red-700 ring-1 ring-inset ring-red-200">
                            ⚠ {{ agingRiskPct }}% vencido
                        </span>
                    </div>
                </template>
            </ChartCard>
        </div>

        <!-- Tabs -->
        <div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
                    :class="['rounded-full px-4 py-1.5 text-xs font-semibold transition',
                        tab === t.key ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                    {{ t.label }} <span class="ml-1 opacity-70">({{ t.count }})</span>
                </button>
            </div>

            <!-- Tab: Top compradores -->
            <DataTable v-if="tab === 'top'" :columns="topColumns" :rows="data.top_customers ?? []" />

            <!-- Tab: Con saldo (with WhatsApp) -->
            <template v-else-if="tab === 'balance'">
                <DataTable :columns="balanceColumns" :rows="data.with_balance ?? []">
                    <!-- Custom cell: days_oldest badge -->
                    <template #cell-days_oldest="{ row }">
                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset',
                            daysOldestBadge(row.days_oldest).cls]">
                            {{ daysOldestBadge(row.days_oldest).label }}
                        </span>
                    </template>
                    <!-- Custom cell: WhatsApp action -->
                    <template #cell-actions="{ row }">
                        <a v-if="buildWhatsappUrl(row.phone, row.name, row.balance)"
                            :href="buildWhatsappUrl(row.phone, row.name, row.balance)"
                            target="_blank"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-green-600 transition hover:bg-green-50 hover:text-green-700"
                            :title="`Cobrar a ${row.name} por WhatsApp`">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                            </svg>
                        </a>
                        <span v-else class="inline-flex h-8 w-8 items-center justify-center text-gray-300" title="Sin teléfono">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                            </svg>
                        </span>
                    </template>
                </DataTable>
            </template>

            <!-- Tab: Nuevos -->
            <DataTable v-else-if="tab === 'new'" :columns="newColumns" :rows="data.new_customers ?? []" />

            <!-- Tab: Inactivos (visual risk cards) -->
            <template v-else-if="tab === 'inactive'">
                <div v-if="inactiveCustomers.length === 0" class="py-6">
                    <EmptyState title="Sin clientes inactivos" :hint="`Todos compraron en los últimos ${inactiveDays ?? 30} días.`" />
                </div>
                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="c in inactiveCustomers" :key="c.id"
                        :class="['relative overflow-hidden rounded-xl p-4 ring-1 transition hover:shadow-md',
                            riskColors[riskLevel(c.last_sale)].bg,
                            riskColors[riskLevel(c.last_sale)].ring]">
                        <!-- Risk bar top -->
                        <div :class="['absolute inset-x-0 top-0 h-1', riskColors[riskLevel(c.last_sale)].bar]" />

                        <div class="mt-1 flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900">{{ c.name }}</p>
                                <p v-if="c.phone" class="mt-0.5 text-xs text-gray-500">{{ c.phone }}</p>
                            </div>
                            <span :class="['shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold',
                                riskColors[riskLevel(c.last_sale)].badge]">
                                {{ riskLabel(riskLevel(c.last_sale)) }}
                            </span>
                        </div>

                        <div class="mt-3 flex items-center justify-between">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Última compra</p>
                                <p :class="['text-sm font-semibold', riskColors[riskLevel(c.last_sale)].text]">
                                    {{ fmtDate(c.last_sale) }}
                                </p>
                                <p v-if="daysSince(c.last_sale)" class="text-[11px] text-gray-500">
                                    hace {{ daysSince(c.last_sale) }} días
                                </p>
                            </div>
                            <!-- WhatsApp action for inactive with phone -->
                            <a v-if="c.phone"
                                :href="`https://wa.me/${c.phone.replace(/[\\s\\-()]/g, '').replace(/^(\\d{10})$/, '52$1')}?text=${encodeURIComponent(`Hola ${c.name}, ¡te extrañamos! ¿Te preparamos algo? Pasa a visitarnos.`)}`"
                                target="_blank"
                                class="flex h-9 w-9 items-center justify-center rounded-xl bg-green-100 text-green-700 transition hover:bg-green-200"
                                :title="`Contactar a ${c.name}`">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
