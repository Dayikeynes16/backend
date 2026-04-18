<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    empresa: Object,
    kpis: Object,
    dailySeries: Array,
    branches: Array,
    byOrigin: Array,
    topProducts: Array,
    byPaymentMethod: Array,
});

const fmtMoney = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n || 0).toLocaleString('es-MX');
const fmtDate = (d) => d ? new Date(d).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const fmtDay = (d) => new Date(d + 'T00:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });

// Growth vs previous month
const growth = computed(() => {
    const curr = props.kpis.month.revenue;
    const prev = props.kpis.prev_month.revenue;
    if (prev === 0) return curr > 0 ? { pct: 100, up: true } : { pct: 0, up: null };
    const pct = ((curr - prev) / prev) * 100;
    return { pct: Math.abs(pct), up: pct >= 0 };
});

// Chart dimensions
const chartW = 900;
const chartH = 220;
const padL = 50;
const padR = 20;
const padT = 20;
const padB = 30;
const innerW = chartW - padL - padR;
const innerH = chartH - padT - padB;

const maxRevenue = computed(() => Math.max(...props.dailySeries.map(d => d.revenue), 1));

const xPos = (i) => padL + (i * innerW) / (props.dailySeries.length - 1 || 1);
const yPos = (v) => padT + innerH - (v / maxRevenue.value) * innerH;

const chartPath = computed(() => {
    if (props.dailySeries.length === 0) return '';
    return props.dailySeries
        .map((d, i) => `${i === 0 ? 'M' : 'L'} ${xPos(i).toFixed(1)} ${yPos(d.revenue).toFixed(1)}`)
        .join(' ');
});

const areaPath = computed(() => {
    if (props.dailySeries.length === 0) return '';
    const last = props.dailySeries.length - 1;
    return (
        chartPath.value +
        ` L ${xPos(last).toFixed(1)} ${(padT + innerH).toFixed(1)}` +
        ` L ${xPos(0).toFixed(1)} ${(padT + innerH).toFixed(1)} Z`
    );
});

const yTicks = computed(() => {
    const steps = 4;
    const out = [];
    for (let i = 0; i <= steps; i++) {
        const v = (maxRevenue.value * i) / steps;
        out.push({ value: v, y: yPos(v) });
    }
    return out;
});

const originLabel = (o) => ({
    manual: 'Manual (POS)',
    api: 'API / Báscula',
    web: 'Web',
}[o] || o);

const paymentLabel = (m) => ({
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    mixed: 'Mixto',
}[m] || m);

const paymentColor = (m) => ({
    cash: 'bg-green-500',
    card: 'bg-blue-500',
    transfer: 'bg-violet-500',
    mixed: 'bg-amber-500',
}[m] || 'bg-gray-400');

const totalRevenue30d = computed(() => props.byPaymentMethod.reduce((s, p) => s + p.revenue, 0));
const pctOfTotal = (v) => totalRevenue30d.value > 0 ? (v / totalRevenue30d.value) * 100 : 0;
</script>

<template>
    <Head :title="`Métricas: ${empresa.name}`" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('admin.empresas.index')" class="text-gray-400 transition hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">{{ empresa.name }}</span>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Header card -->
            <section class="rounded-2xl bg-gradient-to-br from-red-600 via-red-500 to-orange-500 p-6 text-white shadow-lg">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-bold">{{ empresa.name }}</h1>
                            <span :class="empresa.status === 'active' ? 'bg-white/20 text-white' : 'bg-red-900/40 text-red-100'" class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-white/30">
                                {{ empresa.status === 'active' ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-white/80">
                            /{{ empresa.slug }}<span v-if="empresa.rfc"> · RFC: {{ empresa.rfc }}</span>
                        </p>
                        <p class="mt-1 text-xs text-white/70">
                            {{ empresa.branches_count }} sucursales · {{ empresa.users_count }} usuarios · creada el {{ new Date(empresa.created_at).toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }) }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Link :href="route('admin.empresas.edit', empresa.id)" class="inline-flex items-center gap-2 rounded-lg bg-white/20 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/30">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                            Editar empresa
                        </Link>
                    </div>
                </div>
            </section>

            <!-- KPIs -->
            <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Hoy</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ fmtMoney(kpis.today.revenue) }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ fmtInt(kpis.today.count) }} ventas</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Últimos 7 días</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ fmtMoney(kpis.last7.revenue) }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ fmtInt(kpis.last7.count) }} ventas</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Últimos 30 días</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ fmtMoney(kpis.last30.revenue) }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ fmtInt(kpis.last30.count) }} ventas · Ticket prom. {{ fmtMoney(kpis.avg_ticket_30d) }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Este mes</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ fmtMoney(kpis.month.revenue) }}</p>
                    <p class="mt-0.5 flex items-center gap-1 text-xs">
                        <span class="text-gray-500">{{ fmtInt(kpis.month.count) }} ventas</span>
                        <span v-if="growth.up !== null" :class="growth.up ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                            {{ growth.up ? '↑' : '↓' }} {{ growth.pct.toFixed(1) }}% vs mes anterior
                        </span>
                    </p>
                </div>
            </section>

            <!-- Chart -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Ingresos últimos 30 días</h2>
                        <p class="mt-0.5 text-xs text-gray-400">Ventas completadas por día.</p>
                    </div>
                </div>
                <div v-if="maxRevenue <= 1" class="flex h-40 items-center justify-center rounded-xl border-2 border-dashed border-gray-200">
                    <p class="text-sm text-gray-400">Sin ventas completadas en los últimos 30 días.</p>
                </div>
                <svg v-else :viewBox="`0 0 ${chartW} ${chartH}`" class="h-56 w-full">
                    <defs>
                        <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#ef4444" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#ef4444" stop-opacity="0" />
                        </linearGradient>
                    </defs>

                    <!-- Y grid lines & labels -->
                    <g>
                        <line v-for="t in yTicks" :key="`g-${t.value}`" :x1="padL" :x2="chartW - padR" :y1="t.y" :y2="t.y" stroke="#f3f4f6" stroke-width="1" />
                        <text v-for="t in yTicks" :key="`l-${t.value}`" :x="padL - 8" :y="t.y + 4" text-anchor="end" class="text-[10px] fill-gray-400">{{ fmtMoney(t.value) }}</text>
                    </g>

                    <!-- Area + line -->
                    <path :d="areaPath" fill="url(#areaGrad)" />
                    <path :d="chartPath" fill="none" stroke="#ef4444" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                    <!-- Dots -->
                    <g>
                        <circle v-for="(d, i) in dailySeries" :key="d.date" :cx="xPos(i)" :cy="yPos(d.revenue)" r="2.5" fill="#ef4444">
                            <title>{{ fmtDay(d.date) }}: {{ fmtMoney(d.revenue) }} ({{ d.count }} ventas)</title>
                        </circle>
                    </g>

                    <!-- X labels (every 5 days) -->
                    <g>
                        <text v-for="(d, i) in dailySeries" :key="`x-${d.date}`" v-show="i % 5 === 0 || i === dailySeries.length - 1" :x="xPos(i)" :y="chartH - 8" text-anchor="middle" class="text-[10px] fill-gray-400">{{ fmtDay(d.date) }}</text>
                    </g>
                </svg>
            </section>

            <!-- Branches + right column -->
            <section class="grid gap-6 lg:grid-cols-3">
                <!-- Branches -->
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100 lg:col-span-2">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-5">
                        <div>
                            <h2 class="text-base font-bold text-gray-900">Sucursales</h2>
                            <p class="mt-0.5 text-xs text-gray-400">Actividad de los últimos 30 días.</p>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="b in branches" :key="b.id" class="flex items-center gap-4 px-6 py-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-50">
                                <svg class="h-5 w-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349" /></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-bold text-gray-900">{{ b.name }}</p>
                                    <span :class="b.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset">
                                        {{ b.status === 'active' ? 'Activa' : 'Inactiva' }}
                                    </span>
                                    <span v-if="b.open_shift" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 ring-1 ring-blue-200">
                                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500"></span>
                                        Turno abierto · {{ b.open_shift.user_name || 'sin operador' }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400">
                                    {{ b.users_count }} usuarios · Última venta: {{ fmtDate(b.last_sale_at) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-gray-900">{{ fmtMoney(b.revenue_30d) }}</p>
                                <p class="text-xs text-gray-400">{{ fmtInt(b.sale_count_30d) }} ventas / 30d</p>
                            </div>
                        </div>
                        <div v-if="branches.length === 0" class="px-6 py-10 text-center text-sm text-gray-400">Esta empresa aún no tiene sucursales.</div>
                    </div>
                </div>

                <!-- Right column: Origin + Payment methods -->
                <div class="space-y-6">
                    <!-- By origin -->
                    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="border-b border-gray-100 px-6 py-5">
                            <h2 class="text-base font-bold text-gray-900">Origen de ventas</h2>
                            <p class="mt-0.5 text-xs text-gray-400">Últimos 30 días.</p>
                        </div>
                        <div class="p-6">
                            <div v-if="byOrigin.length === 0" class="text-center text-sm text-gray-400">Sin datos.</div>
                            <ul v-else class="space-y-3">
                                <li v-for="o in byOrigin" :key="o.origin" class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-gray-700">{{ originLabel(o.origin) }}</p>
                                        <p class="text-xs text-gray-400">{{ fmtInt(o.count) }} ventas</p>
                                    </div>
                                    <p class="text-sm font-bold text-gray-900">{{ fmtMoney(o.revenue) }}</p>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- By payment method -->
                    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                        <div class="border-b border-gray-100 px-6 py-5">
                            <h2 class="text-base font-bold text-gray-900">Métodos de pago</h2>
                            <p class="mt-0.5 text-xs text-gray-400">Distribución últimos 30 días.</p>
                        </div>
                        <div class="space-y-3 p-6">
                            <div v-if="byPaymentMethod.length === 0" class="text-center text-sm text-gray-400">Sin datos.</div>
                            <div v-for="p in byPaymentMethod" :key="p.method">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium text-gray-700">{{ paymentLabel(p.method) }}</span>
                                    <span class="font-semibold text-gray-900">{{ fmtMoney(p.revenue) }}</span>
                                </div>
                                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                    <div class="h-full rounded-full transition-all duration-500" :class="paymentColor(p.method)" :style="{ width: Math.max(pctOfTotal(p.revenue), 2) + '%' }" />
                                </div>
                                <p class="mt-1 text-[10px] text-gray-400">{{ fmtInt(p.count) }} ventas · {{ pctOfTotal(p.revenue).toFixed(1) }}%</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Top products -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Productos más vendidos</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Top 5 por ingresos, últimos 30 días.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Producto</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Cantidad</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Ventas</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Ingresos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(p, i) in topProducts" :key="p.name" class="transition hover:bg-gray-50">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-red-100 text-xs font-bold text-red-700">{{ i + 1 }}</span>
                                        <p class="text-sm font-medium text-gray-900">{{ p.name }}</p>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-right text-sm text-gray-700">{{ p.qty.toLocaleString('es-MX') }}</td>
                                <td class="px-6 py-3 text-right text-sm text-gray-500">{{ fmtInt(p.sale_count) }}</td>
                                <td class="px-6 py-3 text-right text-sm font-semibold text-gray-900">{{ fmtMoney(p.revenue) }}</td>
                            </tr>
                            <tr v-if="topProducts.length === 0">
                                <td colspan="4" class="px-6 py-10 text-center text-sm text-gray-400">Sin productos vendidos en los últimos 30 días.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
