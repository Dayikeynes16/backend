<script setup>
/**
 * DashboardOverview — cuerpo del dashboard reutilizable entre Sucursal y Empresa.
 *
 * NO contiene layout (ni SucursalLayout ni EmpresaLayout). El padre lo
 * envuelve en su layout correspondiente y le pasa los datos.
 *
 * Estructura del prop `expenses` (opcional):
 *   { total, total_yesterday, count, delta_pct, hourly: [{h, amount}],
 *     top_categories: [{category, subcategory, total, count}],
 *     recent: [{id, concept, amount, expense_at, category, subcategory, branch, user}] }
 *
 * Cuando `expenses` es null, los bloques de gastos no se renderizan (graceful).
 */
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    totals: { type: Object, required: true },
    hoursData: { type: Array, required: true },
    yesterdayHoursData: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    topProducts: { type: Array, default: () => [] },
    recentShifts: { type: Array, default: () => [] },
    pendingCount: { type: Number, default: 0 },
    cancelRequestCount: { type: Number, default: 0 },
    productCount: { type: Number, default: 0 },
    cajeroCount: { type: Number, default: 0 },
    activeCashierCount: { type: Number, default: 0 },
    expenses: { type: Object, default: null },
    tenant: { type: Object, required: true },
    /** Contexto del usuario que ve el dashboard. 'sucursal' | 'empresa'. */
    context: { type: String, default: 'sucursal' },
    /** Sucursal seleccionada (sólo aplica en context=empresa). null = todas */
    selectedBranchId: { type: [Number, null], default: null },
    /** Lista de sucursales (sólo aplica en context=empresa) — para mostrar nombre. */
    branches: { type: Array, default: () => [] },
    /** Etiqueta del badge en quick actions */
    cancelacionesRouteName: { type: String, default: 'sucursal.cancelaciones.index' },
    productosRouteName: { type: String, default: 'sucursal.productos.index' },
    usuariosRouteName: { type: String, default: 'sucursal.usuarios.index' },
    cortesRouteName: { type: String, default: 'sucursal.cortes.index' },
    configRouteName: { type: String, default: 'sucursal.configuracion' },
    gastosRouteName: { type: String, default: 'sucursal.gastos.index' },
});

// Helpers comunes
const fmt = (n, d = 2) => Number(n ?? 0).toLocaleString('es-MX', { minimumFractionDigits: d, maximumFractionDigits: d });
const fmtK = (n) => `$${(Number(n) / 1000).toFixed(0)}k`;
const formatDateTime = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const formatTime = (iso) => iso ? new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '';

const splitAmount = (n) => {
    const [i, d = '00'] = Number(n ?? 0).toFixed(2).split('.');
    return [Number(i).toLocaleString('en-US'), d];
};

const sparkPath = (values, width = 120, height = 28) => {
    if (!values?.length) return { line: '', area: '' };
    const max = Math.max(...values), min = Math.min(...values);
    const range = max - min || 1;
    const step = width / Math.max(values.length - 1, 1);
    const pts = values.map((v, i) => [i * step, height - ((v - min) / range) * (height - 4) - 2]);
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
        const [x0, y0] = pts[i - 1], [x1, y1] = pts[i];
        const cx = (x0 + x1) / 2;
        d += ` C ${cx} ${y0}, ${cx} ${y1}, ${x1} ${y1}`;
    }
    const area = `${d} L ${pts[pts.length - 1][0]} ${height} L ${pts[0][0]} ${height} Z`;
    return { line: d, area };
};

const salesSpark = computed(() => sparkPath(props.hoursData?.map(d => d.sales) ?? []));
const trxSpark = computed(() => sparkPath(props.hoursData?.map(d => d.trx) ?? []));
const cumulativeCashiers = computed(() => {
    const hrs = props.hoursData?.map(() => props.activeCashierCount ?? 0) ?? [];
    return sparkPath(hrs.length ? hrs : [0, 0, 0]);
});
const expensesSpark = computed(() => sparkPath(props.expenses?.hourly?.map(d => d.amount) ?? []));

// === Ventas por hora chart ===
const chartW = 720, chartH = 240;
const PAD_L = 48, PAD_R = 16, PAD_T = 16, PAD_B = 32;
const innerW = chartW - PAD_L - PAD_R;
const innerH = chartH - PAD_T - PAD_B;

const chartData = computed(() => props.hoursData ?? []);
const yesterdayData = computed(() => props.yesterdayHoursData?.map(d => d.sales) ?? []);

const yMax = computed(() => {
    const all = [...chartData.value.map(d => d.sales), ...yesterdayData.value];
    const m = Math.max(...all, 1);
    return Math.max(Math.ceil(m / 2000) * 2000, 2000);
});

const xStep = computed(() => (chartData.value.length > 1 ? innerW / (chartData.value.length - 1) : innerW));
const xAt = (i) => PAD_L + i * xStep.value;
const yAt = (v) => PAD_T + innerH - (v / yMax.value) * innerH;

const smoothPath = (vals) => {
    if (!vals.length) return '';
    let d = `M ${xAt(0)} ${yAt(vals[0])}`;
    for (let i = 1; i < vals.length; i++) {
        const x0 = xAt(i - 1), y0 = yAt(vals[i - 1]);
        const x1 = xAt(i), y1 = yAt(vals[i]);
        const cx = (x0 + x1) / 2;
        d += ` C ${cx} ${y0}, ${cx} ${y1}, ${x1} ${y1}`;
    }
    return d;
};

const todayVals = computed(() => chartData.value.map(d => d.sales));
const todayLine = computed(() => smoothPath(todayVals.value));
const todayArea = computed(() => {
    if (!todayVals.value.length) return '';
    return `${todayLine.value} L ${xAt(chartData.value.length - 1)} ${PAD_T + innerH} L ${xAt(0)} ${PAD_T + innerH} Z`;
});
const yestLine = computed(() => smoothPath(yesterdayData.value));
const tickValues = computed(() => Array.from({ length: 5 }, (_, i) => (yMax.value / 4) * i));

const peakIdx = computed(() => {
    if (!todayVals.value.length) return 0;
    let idx = 0, max = -Infinity;
    todayVals.value.forEach((v, i) => { if (v > max) { max = v; idx = i; } });
    return idx;
});
const peakHour = computed(() => chartData.value[peakIdx.value]?.h ?? '—');
const peakTrx = computed(() => chartData.value[peakIdx.value]?.trx ?? 0);
const totalTrx = computed(() => chartData.value.reduce((s, d) => s + d.trx, 0));
const avgTicket = computed(() => totalTrx.value > 0 ? props.totals.total_sales / totalTrx.value : 0);

const hover = ref(null);

// === Donut métodos de pago ===
const donutSize = 120, donutStroke = 18;
const donutRadius = (donutSize - donutStroke) / 2;
const donutC = 2 * Math.PI * donutRadius;
const paymentColors = ['#821B29', '#C9374A', '#E85868', '#D97706', '#0E8A5F', '#2563AE', '#8B5CF6'];
const methodsTotal = computed(() => props.paymentMethods?.reduce((s, m) => s + m.total, 0) ?? 0);

const donutSegments = computed(() => {
    const list = props.paymentMethods ?? [];
    const total = methodsTotal.value || 1;
    let offset = 0;
    return list.map((m, i) => {
        const len = (m.total / total) * donutC;
        const seg = {
            ...m,
            color: paymentColors[i % paymentColors.length],
            len,
            offset,
            pct: ((m.total / total) * 100).toFixed(0),
        };
        offset += len;
        return seg;
    });
});

const maxProd = computed(() => {
    const vals = (props.topProducts ?? []).map(p => Number(p.total_revenue ?? 0));
    return Math.max(...vals, 1);
});

const maxExpenseCat = computed(() => {
    const vals = (props.expenses?.top_categories ?? []).map(p => Number(p.total ?? 0));
    return Math.max(...vals, 1);
});

// Utilidad neta = ventas - gastos del día
const netProfit = computed(() => {
    const sales = Number(props.totals?.total_sales ?? 0);
    const exp = Number(props.expenses?.total ?? 0);
    return sales - exp;
});
const netProfitYesterday = computed(() => {
    const sales = Number(props.totals?.total_sales_yesterday ?? 0);
    const exp = Number(props.expenses?.total_yesterday ?? 0);
    return sales - exp;
});
const netProfitDelta = computed(() => {
    const y = netProfitYesterday.value;
    const t = netProfit.value;
    if (y === 0) return null;
    return Number(((t - y) / Math.abs(y)) * 100).toFixed(1);
});

// Etiqueta de scope para chip header
const scopeLabel = computed(() => {
    if (props.context !== 'empresa') return null;
    if (!props.selectedBranchId) return 'Todas las sucursales';
    const b = props.branches?.find(b => b.id === props.selectedBranchId);
    return b?.name || '—';
});
</script>

<template>
    <div class="cn-dashboard cn-fade-in">
        <!-- Scope chip (solo empresa) -->
        <div v-if="context === 'empresa'" class="cn-scope-chip">
            <svg class="cn-scope-chip__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 0V21m18-9.349V9.349M3.75 9.349a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614" /></svg>
            <span>Vista</span>
            <span class="cn-scope-chip__value">{{ scopeLabel }}</span>
        </div>

        <!-- KPI ROW -->
        <div class="cn-kpi-row">
            <div class="cn-kpi cn-kpi--wine">
                <div class="cn-kpi__label">Ventas</div>
                <div class="cn-kpi__value">
                    <span class="cn-currency">$</span>{{ splitAmount(totals.total_sales)[0] }}<span class="cn-currency">.{{ splitAmount(totals.total_sales)[1] }}</span>
                </div>
                <div v-if="totals.delta_pct !== null && totals.delta_pct !== undefined" :class="['cn-kpi__delta', { neg: totals.delta_pct < 0 }]">
                    <svg width="10" height="10" viewBox="0 0 10 10" :style="{ transform: totals.delta_pct < 0 ? 'rotate(180deg)' : 'none' }"><path d="M5 1 L9 7 L1 7 Z" fill="currentColor"/></svg>
                    {{ totals.delta_pct >= 0 ? '+' : '' }}{{ totals.delta_pct }}% vs ayer
                </div>
                <div v-else class="cn-kpi__delta" style="color: var(--cn-ink-3)">Sin datos de ayer</div>
                <svg class="cn-kpi__spark" viewBox="0 0 120 28" preserveAspectRatio="none">
                    <path :d="salesSpark.area" fill="#F8DDE0" opacity="0.5"/>
                    <path :d="salesSpark.line" fill="none" stroke="#C9374A" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </div>

            <div class="cn-kpi cn-kpi--amber">
                <div class="cn-kpi__label">Transacciones</div>
                <div class="cn-kpi__value">{{ totals.sale_count }}</div>
                <div class="cn-kpi__delta">
                    {{ totals.sale_count - (totals.sale_count_yesterday ?? 0) >= 0 ? '+' : '' }}{{ totals.sale_count - (totals.sale_count_yesterday ?? 0) }} vs ayer
                </div>
                <svg class="cn-kpi__spark" viewBox="0 0 120 28" preserveAspectRatio="none">
                    <path :d="trxSpark.area" fill="#FEF3E6" opacity="0.5"/>
                    <path :d="trxSpark.line" fill="none" stroke="#D97706" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </div>

            <!-- KPI: Gastos hoy -->
            <div v-if="expenses" class="cn-kpi cn-kpi--rose">
                <div class="cn-kpi__label">Gastos hoy</div>
                <div class="cn-kpi__value">
                    <span class="cn-currency">$</span>{{ splitAmount(expenses.total)[0] }}<span class="cn-currency">.{{ splitAmount(expenses.total)[1] }}</span>
                </div>
                <div v-if="expenses.delta_pct !== null && expenses.delta_pct !== undefined"
                    :class="['cn-kpi__delta', { neg: expenses.delta_pct > 0 }]"
                    :style="{ color: expenses.delta_pct > 0 ? 'var(--cn-wine-500)' : 'var(--cn-green)' }">
                    <svg width="10" height="10" viewBox="0 0 10 10" :style="{ transform: expenses.delta_pct < 0 ? 'rotate(180deg)' : 'none' }"><path d="M5 1 L9 7 L1 7 Z" fill="currentColor"/></svg>
                    {{ expenses.delta_pct >= 0 ? '+' : '' }}{{ expenses.delta_pct }}% vs ayer
                </div>
                <div v-else class="cn-kpi__delta" style="color: var(--cn-ink-3)">{{ expenses.count }} {{ expenses.count === 1 ? 'gasto' : 'gastos' }}</div>
                <svg class="cn-kpi__spark" viewBox="0 0 120 28" preserveAspectRatio="none">
                    <path :d="expensesSpark.area" fill="#FCE9DD" opacity="0.5"/>
                    <path :d="expensesSpark.line" fill="none" stroke="#D97706" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </div>

            <!-- KPI: Utilidad neta -->
            <div v-if="expenses" :class="['cn-kpi', netProfit >= 0 ? 'cn-kpi--green' : 'cn-kpi--wine']">
                <div class="cn-kpi__label">Utilidad neta</div>
                <div class="cn-kpi__value" :style="{ color: netProfit >= 0 ? 'var(--cn-green)' : 'var(--cn-wine-500)' }">
                    <span class="cn-currency">$</span>{{ splitAmount(netProfit)[0] }}<span class="cn-currency">.{{ splitAmount(netProfit)[1] }}</span>
                </div>
                <div v-if="netProfitDelta !== null"
                    :class="['cn-kpi__delta', { neg: netProfitDelta < 0 }]">
                    <svg width="10" height="10" viewBox="0 0 10 10" :style="{ transform: netProfitDelta < 0 ? 'rotate(180deg)' : 'none' }"><path d="M5 1 L9 7 L1 7 Z" fill="currentColor"/></svg>
                    {{ netProfitDelta >= 0 ? '+' : '' }}{{ netProfitDelta }}% vs ayer
                </div>
                <div v-else class="cn-kpi__delta" style="color: var(--cn-ink-3)">Ventas − gastos del día</div>
                <svg class="cn-kpi__spark" viewBox="0 0 120 28" preserveAspectRatio="none">
                    <rect x="0" y="13" width="120" height="2" :fill="netProfit >= 0 ? '#E6F4EE' : '#F8DDE0'"/>
                </svg>
            </div>

            <!-- KPI: Cajeros en turno -->
            <div class="cn-kpi cn-kpi--blue">
                <div class="cn-kpi__label">Cajeros en turno</div>
                <div class="cn-kpi__value">
                    {{ activeCashierCount }}<span style="font-size: 14px; color: var(--cn-ink-3); font-weight: 500">&nbsp;/ {{ cajeroCount }}</span>
                </div>
                <div class="cn-kpi__delta" style="color: var(--cn-blue)">
                    <span class="cn-status-dot cn-status--on"/>{{ activeCashierCount }} {{ activeCashierCount === 1 ? 'activo' : 'activos' }}
                </div>
                <svg class="cn-kpi__spark" viewBox="0 0 120 28" preserveAspectRatio="none">
                    <path :d="cumulativeCashiers.area" fill="#E8F0F9" opacity="0.5"/>
                    <path :d="cumulativeCashiers.line" fill="none" stroke="#2563AE" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </div>
        </div>

        <!-- Secondary KPI row: pendientes, productos -->
        <div class="cn-mini-row">
            <div class="cn-mini-card">
                <div class="cn-mini-card__icon" style="background: #FEF3E6; color: #B27D04">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <div class="cn-mini-card__body">
                    <div class="cn-mini-card__value">{{ pendingCount }}</div>
                    <div class="cn-mini-card__label">Ventas pendientes</div>
                </div>
            </div>
            <div class="cn-mini-card">
                <div class="cn-mini-card__icon" style="background: var(--cn-green-bg); color: var(--cn-green)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25"/></svg>
                </div>
                <div class="cn-mini-card__body">
                    <div class="cn-mini-card__value">{{ productCount }}</div>
                    <div class="cn-mini-card__label">Productos activos</div>
                </div>
            </div>
            <div v-if="context === 'empresa'" class="cn-mini-card">
                <div class="cn-mini-card__icon" style="background: var(--cn-blue-bg); color: var(--cn-blue)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 9.349v11.65M2.36 21H21.64M3.75 9.349a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016"/></svg>
                </div>
                <div class="cn-mini-card__body">
                    <div class="cn-mini-card__value">{{ branches.length }}</div>
                    <div class="cn-mini-card__label">Sucursales</div>
                </div>
            </div>
        </div>

        <!-- Cancel requests alert -->
        <div v-if="cancelRequestCount > 0" class="cn-alert">
            <svg class="cn-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            <span class="cn-alert__msg">{{ cancelRequestCount }} solicitud{{ cancelRequestCount > 1 ? 'es' : '' }} de cancelación pendiente{{ cancelRequestCount > 1 ? 's' : '' }}</span>
            <Link v-if="context === 'sucursal'" :href="route(cancelacionesRouteName, tenant.slug)" class="cn-alert__cta">Revisar →</Link>
        </div>

        <!-- CHART ROW: Ventas por hora + Donut métodos -->
        <div class="cn-chart-row">
            <div class="cn-chart-card">
                <div class="cn-chart-head">
                    <div>
                        <div class="cn-chart-title">Ventas por hora · Hoy</div>
                        <div class="cn-chart-subtitle">{{ totalTrx }} transacciones en total</div>
                    </div>
                    <div class="cn-chart-legend">
                        <span><span class="cn-dot" style="background: #C9374A"/>Hoy</span>
                        <span><span class="cn-dot" style="background: #C9A7AB; opacity: 0.8"/>Ayer</span>
                    </div>
                </div>

                <div class="cn-chart-stats">
                    <div>
                        <div class="cn-stat__label">Total hoy</div>
                        <div class="cn-stat__value">${{ splitAmount(totals.total_sales)[0] }}<span style="color: var(--cn-ink-3); font-size: 13px">.{{ splitAmount(totals.total_sales)[1] }}</span></div>
                    </div>
                    <div>
                        <div class="cn-stat__label">Ticket promedio</div>
                        <div class="cn-stat__value">${{ fmt(avgTicket) }}</div>
                    </div>
                    <div>
                        <div class="cn-stat__label">Hora pico</div>
                        <div class="cn-stat__value">{{ peakHour }}:00 <span style="color: var(--cn-ink-3); font-size: 12px; font-weight: 500">· {{ peakTrx }} trx</span></div>
                    </div>
                    <div>
                        <div class="cn-stat__label">vs. ayer</div>
                        <div class="cn-stat__value" :style="{ color: (totals.delta_pct ?? 0) >= 0 ? 'var(--cn-green)' : 'var(--cn-wine-500)' }">
                            <span v-if="totals.delta_pct !== null && totals.delta_pct !== undefined">{{ totals.delta_pct >= 0 ? '+' : '' }}{{ totals.delta_pct }}%</span>
                            <span v-else style="color: var(--cn-ink-3)">—</span>
                        </div>
                    </div>
                </div>

                <svg class="cn-chart-svg" :viewBox="`0 0 ${chartW} ${chartH}`" preserveAspectRatio="none" @mouseleave="hover = null">
                    <defs>
                        <linearGradient id="cn-todayFill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="#C9374A" stop-opacity="0.32"/>
                            <stop offset="60%" stop-color="#C9374A" stop-opacity="0.10"/>
                            <stop offset="100%" stop-color="#C9374A" stop-opacity="0"/>
                        </linearGradient>
                        <linearGradient id="cn-barFill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="#821B29" stop-opacity="0.75"/>
                            <stop offset="100%" stop-color="#C9374A" stop-opacity="0.75"/>
                        </linearGradient>
                    </defs>

                    <g v-for="(v, i) in tickValues" :key="i">
                        <line :x1="PAD_L" :y1="yAt(v)" :x2="chartW - PAD_R" :y2="yAt(v)" stroke="rgba(26,20,16,0.06)" :stroke-dasharray="i === 0 ? '' : '2 3'"/>
                        <text :x="PAD_L - 8" :y="yAt(v) + 3" text-anchor="end" font-size="10" fill="#8A7F78" font-family="IBM Plex Mono">{{ fmtK(v) }}</text>
                    </g>

                    <rect v-for="(d, i) in chartData" :key="'bar-' + i"
                        :x="xAt(i) - Math.max(4, xStep * 0.32) / 2"
                        :y="yAt(d.sales)"
                        :width="Math.max(4, xStep * 0.32)"
                        :height="PAD_T + innerH - yAt(d.sales)"
                        rx="2" fill="url(#cn-barFill)"
                        :opacity="hover === i ? 1 : 0.18" />

                    <path v-if="yestLine" :d="yestLine" fill="none" stroke="#C9A7AB" stroke-width="1.5" stroke-dasharray="4 4" opacity="0.8"/>
                    <path v-if="todayArea" :d="todayArea" fill="url(#cn-todayFill)"/>
                    <path v-if="todayLine" :d="todayLine" fill="none" stroke="#C9374A" stroke-width="2.2" stroke-linecap="round"/>

                    <g v-for="(d, i) in chartData" :key="'pt-' + i">
                        <circle :cx="xAt(i)" :cy="yAt(d.sales)" :r="hover === i ? 5 : 3" fill="#fff" stroke="#C9374A" stroke-width="2" style="transition: r 120ms"/>
                        <rect :x="xAt(i) - xStep / 2" :y="PAD_T" :width="xStep" :height="innerH" fill="transparent" @mouseenter="hover = i"/>
                    </g>

                    <text v-for="(d, i) in chartData" :key="'x-' + i"
                        :x="xAt(i)" :y="chartH - 12" text-anchor="middle" font-size="10.5"
                        :fill="hover === i ? '#1A1410' : '#8A7F78'" font-family="IBM Plex Mono"
                        :font-weight="hover === i ? 600 : 400">{{ d.h }}h</text>

                    <g v-if="hover === null && todayVals.length">
                        <line :x1="xAt(peakIdx)" :x2="xAt(peakIdx)" :y1="yAt(todayVals[peakIdx]) - 6" :y2="PAD_T + 8" stroke="rgba(130,27,41,0.3)" stroke-dasharray="2 3"/>
                        <g :transform="`translate(${xAt(peakIdx)}, ${PAD_T + 2})`">
                            <rect x="-26" y="-4" width="52" height="16" rx="4" fill="#6B1721"/>
                            <text x="0" y="7" text-anchor="middle" font-size="9.5" fill="#fff" font-weight="600" font-family="IBM Plex Sans">PICO {{ peakHour }}h</text>
                        </g>
                    </g>

                    <g v-if="hover !== null && chartData[hover]" :transform="`translate(${Math.min(chartW - PAD_R - 140, Math.max(PAD_L, xAt(hover) - 70))}, ${Math.max(PAD_T + 4, yAt(chartData[hover].sales) - 62)})`" style="pointer-events: none">
                        <rect width="140" height="54" rx="8" fill="#1A1410" opacity="0.96"/>
                        <text x="12" y="17" font-size="10" fill="#B8ADA6" font-family="IBM Plex Mono">{{ chartData[hover].h }}:00 – {{ parseInt(chartData[hover].h) + 1 }}:00</text>
                        <text x="12" y="34" font-size="14" fill="#fff" font-weight="600" font-family="IBM Plex Mono">${{ fmt(chartData[hover].sales) }}</text>
                        <text x="12" y="47" font-size="10" fill="#E85868" font-family="IBM Plex Sans">{{ chartData[hover].trx }} transacciones</text>
                    </g>
                </svg>
            </div>

            <div class="cn-chart-card">
                <div class="cn-chart-head">
                    <div>
                        <div class="cn-chart-title">Métodos de pago</div>
                        <div class="cn-chart-subtitle">Distribución del día</div>
                    </div>
                </div>
                <div v-if="!paymentMethods || paymentMethods.length === 0" class="cn-empty">Sin pagos registrados</div>
                <div v-else class="cn-donut-wrap">
                    <svg :width="donutSize" :height="donutSize" :viewBox="`0 0 ${donutSize} ${donutSize}`">
                        <circle :cx="donutSize / 2" :cy="donutSize / 2" :r="donutRadius" fill="none" stroke="#F3EDE9" :stroke-width="donutStroke"/>
                        <circle v-for="(seg, i) in donutSegments" :key="i"
                            :cx="donutSize / 2" :cy="donutSize / 2" :r="donutRadius"
                            fill="none" :stroke="seg.color" :stroke-width="donutStroke"
                            :stroke-dasharray="`${seg.len} ${donutC - seg.len}`"
                            :stroke-dashoffset="-seg.offset"
                            :transform="`rotate(-90 ${donutSize / 2} ${donutSize / 2})`"
                            stroke-linecap="butt" />
                        <text :x="donutSize / 2" :y="donutSize / 2 - 2" text-anchor="middle" font-size="11" fill="#8A7F78" font-family="IBM Plex Sans">Total</text>
                        <text :x="donutSize / 2" :y="donutSize / 2 + 14" text-anchor="middle" font-size="15" fill="#1A1410" font-weight="600" font-family="IBM Plex Mono">${{ (methodsTotal / 1000).toFixed(1) }}k</text>
                    </svg>
                    <div class="cn-donut-legend">
                        <div v-for="(seg, i) in donutSegments" :key="i" class="cn-donut-legend__row">
                            <span class="cn-donut-legend__dot" :style="{ background: seg.color }"/>
                            <span class="cn-donut-legend__label">{{ seg.label }}</span>
                            <span class="cn-donut-legend__val">${{ Number(seg.total).toLocaleString('en-US', { maximumFractionDigits: 0 }) }}</span>
                        </div>
                        <div class="cn-donut-legend__footer">
                            <span v-for="(seg, i) in donutSegments" :key="i">
                                {{ seg.pct }}% {{ seg.label.toLowerCase() }}{{ i < donutSegments.length - 1 ? ' · ' : '' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BOTTOM ROW: Top productos + Top gastos por categoría -->
        <div class="cn-chart-row cn-chart-row--split">
            <div class="cn-card">
                <div class="cn-card__head">
                    <div>
                        <div class="cn-card__title">Productos más vendidos</div>
                        <div class="cn-card__subtitle">Top 5 del día</div>
                    </div>
                    <Link v-if="context === 'sucursal'" :href="route(productosRouteName, tenant.slug)" class="cn-card__link">Ver todos →</Link>
                </div>
                <div v-if="!topProducts || topProducts.length === 0" class="cn-empty">Sin ventas en esta fecha.</div>
                <div v-else class="cn-prod-list">
                    <div v-for="(p, i) in topProducts" :key="i" class="cn-prod-row">
                        <div class="cn-prod-row__top">
                            <div>
                                <span class="cn-prod-row__name">{{ p.product_name }}</span>
                                <span class="cn-prod-row__units">{{ fmt(p.total_qty, 1) }} kg</span>
                            </div>
                            <div class="cn-prod-row__amount">${{ fmt(p.total_revenue) }}</div>
                        </div>
                        <div class="cn-prod-bar">
                            <div class="cn-prod-bar__fill" :style="{ width: (Number(p.total_revenue) / maxProd) * 100 + '%' }"/>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top gastos por categoría -->
            <div v-if="expenses" class="cn-card">
                <div class="cn-card__head">
                    <div>
                        <div class="cn-card__title">Gastos del día</div>
                        <div class="cn-card__subtitle">Top categorías · {{ expenses.count }} {{ expenses.count === 1 ? 'gasto' : 'gastos' }}</div>
                    </div>
                    <Link :href="route(gastosRouteName, tenant.slug)" class="cn-card__link">Ver todos →</Link>
                </div>
                <div v-if="!expenses.top_categories || expenses.top_categories.length === 0" class="cn-empty">Sin gastos registrados hoy.</div>
                <div v-else class="cn-prod-list">
                    <div v-for="(c, i) in expenses.top_categories" :key="i" class="cn-prod-row">
                        <div class="cn-prod-row__top">
                            <div>
                                <span class="cn-prod-row__name">{{ c.subcategory || '—' }}</span>
                                <span class="cn-prod-row__units">{{ c.category }} · {{ c.count }} {{ c.count === 1 ? 'gasto' : 'gastos' }}</span>
                            </div>
                            <div class="cn-prod-row__amount" style="color: var(--cn-amber)">${{ fmt(c.total) }}</div>
                        </div>
                        <div class="cn-prod-bar">
                            <div class="cn-prod-bar__fill cn-prod-bar__fill--amber" :style="{ width: (Number(c.total) / maxExpenseCat) * 100 + '%' }"/>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Si NO tenemos expenses, mostramos cortes recientes en su lugar -->
            <div v-else class="cn-card">
                <div class="cn-card__head">
                    <div>
                        <div class="cn-card__title">Cortes recientes</div>
                        <div class="cn-card__subtitle">Últimos 5 cortes de caja</div>
                    </div>
                    <Link v-if="context === 'sucursal'" :href="route(cortesRouteName, tenant.slug)" class="cn-card__link">Ver todos →</Link>
                </div>
                <div v-if="!recentShifts || recentShifts.length === 0" class="cn-empty">Sin cortes registrados.</div>
                <div v-else class="cn-recent-list">
                    <div v-for="s in recentShifts" :key="s.id" class="cn-recent-row">
                        <div class="cn-recent-row__left">
                            <div class="cn-recent-avatar">{{ (s.user?.name || '?').charAt(0).toUpperCase() }}</div>
                            <div>
                                <div class="cn-recent-row__name">{{ s.user?.name ?? '—' }}</div>
                                <div class="cn-recent-row__meta">{{ formatDateTime(s.closed_at) }}</div>
                            </div>
                        </div>
                        <div style="text-align: right">
                            <span class="cn-recent-row__amount">${{ fmt(s.total_sales) }}</span>
                            <span class="cn-recent-row__trx">({{ s.sale_count }})</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent expenses + Recent shifts (only if we have expenses) -->
        <div v-if="expenses" class="cn-chart-row cn-chart-row--split">
            <div class="cn-card">
                <div class="cn-card__head">
                    <div>
                        <div class="cn-card__title">Gastos recientes</div>
                        <div class="cn-card__subtitle">Últimos del día</div>
                    </div>
                    <Link :href="route(gastosRouteName, tenant.slug)" class="cn-card__link">Ver todos →</Link>
                </div>
                <div v-if="!expenses.recent || expenses.recent.length === 0" class="cn-empty">Aún no hay gastos hoy.</div>
                <div v-else class="cn-recent-list">
                    <div v-for="e in expenses.recent" :key="e.id" class="cn-recent-row">
                        <div class="cn-recent-row__left">
                            <div class="cn-recent-avatar cn-recent-avatar--amber">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            </div>
                            <div>
                                <div class="cn-recent-row__name">{{ e.concept }}</div>
                                <div class="cn-recent-row__meta">
                                    {{ e.subcategory }}<span v-if="context === 'empresa' && e.branch"> · {{ e.branch }}</span> · {{ formatTime(e.expense_at) }}
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right">
                            <span class="cn-recent-row__amount" style="color: var(--cn-amber)">${{ fmt(e.amount) }}</span>
                            <span class="cn-recent-row__trx">{{ e.user }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cn-card">
                <div class="cn-card__head">
                    <div>
                        <div class="cn-card__title">Cortes recientes</div>
                        <div class="cn-card__subtitle">Últimos 5 cortes de caja</div>
                    </div>
                    <Link v-if="context === 'sucursal'" :href="route(cortesRouteName, tenant.slug)" class="cn-card__link">Ver todos →</Link>
                </div>
                <div v-if="!recentShifts || recentShifts.length === 0" class="cn-empty">Sin cortes registrados.</div>
                <div v-else class="cn-recent-list">
                    <div v-for="s in recentShifts" :key="s.id" class="cn-recent-row">
                        <div class="cn-recent-row__left">
                            <div class="cn-recent-avatar">{{ (s.user?.name || '?').charAt(0).toUpperCase() }}</div>
                            <div>
                                <div class="cn-recent-row__name">
                                    {{ s.user?.name ?? '—' }}
                                    <span v-if="context === 'empresa' && s.branch?.name" class="cn-recent-row__branch">· {{ s.branch.name }}</span>
                                </div>
                                <div class="cn-recent-row__meta">{{ formatDateTime(s.closed_at) }}</div>
                            </div>
                        </div>
                        <div style="text-align: right">
                            <span class="cn-recent-row__amount">${{ fmt(s.total_sales) }}</span>
                            <span class="cn-recent-row__trx">({{ s.sale_count }})</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- QUICK ACTIONS — solo en context sucursal -->
        <div v-if="context === 'sucursal'" class="cn-quick-actions">
            <Link :href="route(productosRouteName, tenant.slug)" class="cn-quick-action">
                <span class="cn-qa__label">
                    <span class="cn-qa__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg></span>
                    Productos
                </span>
                <span class="cn-qa__arrow">→</span>
            </Link>
            <Link :href="route(gastosRouteName, tenant.slug)" class="cn-quick-action">
                <span class="cn-qa__label">
                    <span class="cn-qa__icon" style="background: #FEF3E6; color: #B27D04"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg></span>
                    Gastos
                </span>
                <span class="cn-qa__arrow">→</span>
            </Link>
            <Link :href="route(usuariosRouteName, tenant.slug)" class="cn-quick-action">
                <span class="cn-qa__label">
                    <span class="cn-qa__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg></span>
                    Cajeros
                </span>
                <span class="cn-qa__arrow">→</span>
            </Link>
            <Link :href="route(cortesRouteName, tenant.slug)" class="cn-quick-action">
                <span class="cn-qa__label">
                    <span class="cn-qa__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span>
                    Cortes
                </span>
                <span class="cn-qa__arrow">→</span>
            </Link>
        </div>
    </div>
</template>

<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

.cn-dashboard {
    --cn-wine-900: #4A0E17;
    --cn-wine-800: #5C131E;
    --cn-wine-700: #6B1721;
    --cn-wine-600: #821B29;
    --cn-wine-500: #A12232;
    --cn-wine-400: #C9374A;
    --cn-wine-300: #E85868;
    --cn-wine-100: #F8DDE0;
    --cn-wine-50:  #FDF4F5;

    --cn-bg:       #FAF7F5;
    --cn-surface:  #FFFFFF;
    --cn-surface-2:#F3EDE9;
    --cn-border:   rgba(26, 20, 16, 0.08);
    --cn-ink:      #1A1410;
    --cn-ink-2:    #4A3F38;
    --cn-ink-3:    #8A7F78;
    --cn-ink-4:    #B8ADA6;

    --cn-amber:    #D97706;
    --cn-amber-bg: #FEF3E6;
    --cn-rose:     #E85868;
    --cn-rose-bg:  #FCE9DD;
    --cn-green:    #0E8A5F;
    --cn-green-bg: #E6F4EE;
    --cn-blue:     #2563AE;
    --cn-blue-bg:  #E8F0F9;

    --cn-shadow-sm: 0 1px 2px rgba(26, 20, 16, 0.04);
    --cn-shadow-md: 0 4px 16px rgba(26, 20, 16, 0.06), 0 1px 2px rgba(26, 20, 16, 0.04);
    --cn-radius-md: 12px;

    font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--cn-ink);
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.cn-dashboard .mono { font-family: 'IBM Plex Mono', monospace; font-feature-settings: "zero"; }

.cn-fade-in { animation: cnFadeIn 200ms ease-out both; }
@keyframes cnFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to   { opacity: 1; transform: none; }
}

/* Scope chip (empresa) */
.cn-scope-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 6px 10px;
    background: var(--cn-wine-50);
    border: 1px solid var(--cn-wine-100);
    border-radius: 999px;
    font-size: 12px;
    color: var(--cn-ink-2);
    align-self: flex-start;
}
.cn-scope-chip__icon { width: 14px; height: 14px; color: var(--cn-wine-500); }
.cn-scope-chip__value { font-weight: 600; color: var(--cn-wine-600); }

/* ========== KPI ========== */
.cn-kpi-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}
.cn-kpi {
    background: var(--cn-surface);
    border: 1px solid var(--cn-border);
    border-radius: var(--cn-radius-md);
    padding: 14px 16px 12px;
    box-shadow: var(--cn-shadow-sm);
    position: relative;
    overflow: hidden;
}
.cn-kpi::before {
    content: '';
    position: absolute;
    left: 0; top: 14px; bottom: 14px;
    width: 3px;
    border-radius: 3px;
}
.cn-kpi--wine::before  { background: var(--cn-wine-500); }
.cn-kpi--amber::before { background: var(--cn-amber); }
.cn-kpi--warn::before  { background: #E3A008; }
.cn-kpi--green::before { background: var(--cn-green); }
.cn-kpi--blue::before  { background: var(--cn-blue); }
.cn-kpi--rose::before  { background: var(--cn-amber); }

.cn-kpi__label {
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--cn-ink-3);
    font-weight: 500;
    margin-bottom: 4px;
}
.cn-kpi__value {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -0.02em;
    font-family: 'IBM Plex Mono', monospace;
    display: flex;
    align-items: baseline;
    gap: 0;
}
.cn-kpi__value .cn-currency { font-size: 14px; color: var(--cn-ink-3); font-weight: 500; }
.cn-kpi__delta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    color: var(--cn-green);
    margin-top: 6px;
    font-weight: 500;
}
.cn-kpi__delta.neg { color: var(--cn-wine-500); }
.cn-kpi__spark { margin-top: 8px; height: 28px; width: 100%; }

.cn-status-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.cn-status--on { background: var(--cn-green); box-shadow: 0 0 0 3px var(--cn-green-bg); }

/* ========== MINI ROW ========== */
.cn-mini-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.cn-mini-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--cn-surface);
    border: 1px solid var(--cn-border);
    border-radius: var(--cn-radius-md);
    padding: 12px 14px;
    box-shadow: var(--cn-shadow-sm);
}
.cn-mini-card__icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: grid; place-items: center;
}
.cn-mini-card__icon svg { width: 18px; height: 18px; }
.cn-mini-card__value { font-family: 'IBM Plex Mono', monospace; font-size: 18px; font-weight: 600; color: var(--cn-ink); line-height: 1; }
.cn-mini-card__label { font-size: 11px; color: var(--cn-ink-3); margin-top: 3px; }

/* ========== ALERT ========== */
.cn-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-radius: var(--cn-radius-md);
    border: 1px solid #FCD7A3;
    background: var(--cn-amber-bg);
}
.cn-alert__icon { width: 20px; height: 20px; color: var(--cn-amber); flex-shrink: 0; }
.cn-alert__msg { flex: 1; font-size: 13.5px; font-weight: 600; color: #8A4A0B; }
.cn-alert__cta { font-size: 13px; font-weight: 600; color: var(--cn-amber); text-decoration: none; }
.cn-alert__cta:hover { text-decoration: underline; }

/* ========== CHART ROW ========== */
.cn-chart-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
}
.cn-chart-row--split { grid-template-columns: 1fr 1fr; }

.cn-chart-card, .cn-card {
    background: var(--cn-surface);
    border: 1px solid var(--cn-border);
    border-radius: var(--cn-radius-md);
    padding: 20px 22px;
    box-shadow: var(--cn-shadow-sm);
}

.cn-chart-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 6px;
    gap: 12px;
}
.cn-chart-title { font-size: 14px; font-weight: 600; }
.cn-chart-subtitle { font-size: 12px; color: var(--cn-ink-3); margin-top: 2px; }
.cn-chart-legend { display: flex; gap: 16px; font-size: 11.5px; color: var(--cn-ink-3); }
.cn-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; vertical-align: middle; }

.cn-chart-stats { display: flex; gap: 28px; margin: 14px 0 10px; flex-wrap: wrap; }
.cn-stat__label { font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--cn-ink-3); }
.cn-stat__value { font-family: 'IBM Plex Mono', monospace; font-size: 18px; font-weight: 600; letter-spacing: -0.01em; margin-top: 2px; }

.cn-chart-svg { width: 100%; height: 240px; display: block; }

/* ========== DONUT ========== */
.cn-donut-wrap { display: flex; align-items: center; gap: 22px; margin-top: 14px; }
.cn-donut-legend { display: flex; flex-direction: column; gap: 10px; flex: 1; }
.cn-donut-legend__row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.cn-donut-legend__dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.cn-donut-legend__label { color: var(--cn-ink-2); min-width: 80px; }
.cn-donut-legend__val { font-family: 'IBM Plex Mono', monospace; font-weight: 600; margin-left: auto; }
.cn-donut-legend__footer {
    border-top: 1px solid var(--cn-border);
    padding-top: 10px;
    margin-top: 4px;
    font-size: 11.5px;
    color: var(--cn-ink-3);
}

/* ========== CARDS & PRODUCT LIST ========== */
.cn-card__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
.cn-card__title { font-size: 14px; font-weight: 600; letter-spacing: -0.01em; }
.cn-card__subtitle { font-size: 12px; color: var(--cn-ink-3); margin-top: 2px; }
.cn-card__link { color: var(--cn-wine-500); font-size: 12px; font-weight: 500; text-decoration: none; }
.cn-card__link:hover { text-decoration: underline; }

.cn-empty {
    padding: 24px 4px;
    text-align: center;
    font-size: 13px;
    color: var(--cn-ink-3);
}

.cn-prod-list { display: flex; flex-direction: column; gap: 12px; }
.cn-prod-row { display: grid; grid-template-columns: 1fr; gap: 6px; }
.cn-prod-row__top { display: flex; justify-content: space-between; align-items: baseline; }
.cn-prod-row__name { font-size: 13.5px; font-weight: 500; color: var(--cn-ink); }
.cn-prod-row__units { font-size: 11.5px; color: var(--cn-ink-3); margin-left: 8px; }
.cn-prod-row__amount { font-family: 'IBM Plex Mono', monospace; font-size: 13.5px; font-weight: 500; }
.cn-prod-bar { height: 4px; border-radius: 999px; background: var(--cn-surface-2); overflow: hidden; position: relative; }
.cn-prod-bar__fill {
    position: absolute; inset: 0 auto 0 0;
    background: linear-gradient(90deg, var(--cn-wine-500), var(--cn-wine-400));
    border-radius: 999px;
}
.cn-prod-bar__fill--amber {
    background: linear-gradient(90deg, var(--cn-amber), #F59E0B);
}

/* ========== RECENT ========== */
.cn-recent-list { display: flex; flex-direction: column; }
.cn-recent-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed var(--cn-border); }
.cn-recent-row:last-child { border-bottom: none; }
.cn-recent-row__left { display: flex; align-items: center; gap: 10px; }
.cn-recent-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--cn-wine-50); color: var(--cn-wine-600);
    display: grid; place-items: center;
    font-size: 11px; font-weight: 600;
    flex-shrink: 0;
}
.cn-recent-avatar--amber { background: var(--cn-amber-bg); color: var(--cn-amber); }
.cn-recent-row__name { font-size: 13px; font-weight: 500; color: var(--cn-ink); }
.cn-recent-row__branch { font-size: 11px; color: var(--cn-ink-3); font-weight: 400; }
.cn-recent-row__meta { font-size: 11px; color: var(--cn-ink-3); }
.cn-recent-row__amount { font-family: 'IBM Plex Mono', monospace; font-size: 13px; font-weight: 500; }
.cn-recent-row__trx { font-size: 11px; color: var(--cn-ink-3); margin-left: 6px; }

/* ========== QUICK ACTIONS ========== */
.cn-quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
.cn-quick-action {
    background: var(--cn-surface);
    border: 1px solid var(--cn-border);
    border-radius: var(--cn-radius-md);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--cn-ink);
    text-decoration: none;
    transition: transform 120ms, box-shadow 120ms, border-color 120ms;
    font-size: 13.5px;
}
.cn-quick-action:hover {
    border-color: var(--cn-wine-100);
    transform: translateY(-1px);
    box-shadow: var(--cn-shadow-md);
}
.cn-qa__label { display: flex; align-items: center; gap: 12px; font-weight: 500; }
.cn-qa__icon {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--cn-wine-50); color: var(--cn-wine-600);
    display: grid; place-items: center;
}
.cn-qa__icon svg { width: 16px; height: 16px; }
.cn-qa__arrow { color: var(--cn-ink-4); font-size: 16px; }

/* ========== RESPONSIVE ========== */
@media (max-width: 1200px) {
    .cn-kpi-row { grid-template-columns: repeat(3, 1fr); }
    .cn-chart-row { grid-template-columns: 1fr; }
    .cn-chart-row--split { grid-template-columns: 1fr; }
    .cn-quick-actions { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .cn-kpi-row { grid-template-columns: 1fr 1fr; }
}
</style>
