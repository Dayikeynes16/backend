<script setup>
import { computed, ref } from 'vue';
import ChartCard from '@/Components/Metrics/ChartCard.vue';

/**
 * Card de tendencia inteligente — el layout se adapta al volumen de datos
 * para que el usuario nunca quede sin informacion util:
 *
 *   mode 'empty'  | actual y previo en cero  | mensaje amigable
 *   mode 'single' | rango es 1-2 buckets    | bloques comparativos con barras
 *   mode 'bars'   | 3-3 buckets sparse      | bar chart vertical comparativo
 *   mode 'line'   | 4+ datos / rango largo  | linea + anotaciones permanentes
 *
 * En todos los modos: headline gigante con total y delta visible (sin
 * depender de hover) y veredicto en lenguaje plano arriba del chart.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    current: { type: Array, required: true },
    previous: { type: Array, default: () => [] },
    valueLabel: { type: String, default: 'Valor' },
    formatValue: { type: Function, required: true },
    color: { type: String, default: '#dc2626' },
    previousColor: { type: String, default: '#9ca3af' },
    compare: { type: Boolean, default: false },
});

const granularity = ref('day');

// === Fechas en español =================================================
const SPANISH_MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const SPANISH_MONTHS_LONG = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const parseDay = (day) => {
    const [y, m, d] = String(day).split('-').map(Number);
    return { y, m, d };
};

const dayLabel = ({ d, m }) => `${String(d).padStart(2, '0')} ${SPANISH_MONTHS_SHORT[m - 1]}`;

const weekKey = (day) => {
    const { y, m, d } = parseDay(day);
    const date = new Date(Date.UTC(y, m - 1, d));
    const dow = (date.getUTCDay() + 6) % 7;
    date.setUTCDate(date.getUTCDate() - dow);
    return date.toISOString().slice(0, 10);
};

const weekLabel = (key) => {
    const start = parseDay(key);
    const end = new Date(Date.UTC(start.y, start.m - 1, start.d + 6));
    const endParts = { y: end.getUTCFullYear(), m: end.getUTCMonth() + 1, d: end.getUTCDate() };
    if (start.m === endParts.m) return `${start.d}–${endParts.d} ${SPANISH_MONTHS_SHORT[start.m - 1]}`;
    return `${dayLabel(start)} – ${dayLabel(endParts)}`;
};

const monthKey = (day) => {
    const { y, m } = parseDay(day);
    return `${y}-${String(m).padStart(2, '0')}-01`;
};

const monthLabel = (key) => {
    const { y, m } = parseDay(key);
    const name = SPANISH_MONTHS_LONG[m - 1];
    return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${y}`;
};

const aggregate = (series) => {
    if (granularity.value === 'day') {
        return series.map(p => ({ key: p.day, label: dayLabel(parseDay(p.day)), value: p.value }));
    }
    const keyFn = granularity.value === 'week' ? weekKey : monthKey;
    const labelFn = granularity.value === 'week' ? weekLabel : monthLabel;
    const buckets = new Map();
    for (const p of series) {
        const k = keyFn(p.day);
        buckets.set(k, (buckets.get(k) ?? 0) + Number(p.value || 0));
    }
    return Array.from(buckets.entries())
        .sort(([a], [b]) => a < b ? -1 : 1)
        .map(([key, value]) => ({ key, label: labelFn(key), value }));
};

// === Series y métricas =================================================
const currentAgg = computed(() => aggregate(props.current));
const previousAgg = computed(() => props.compare ? aggregate(props.previous) : []);

const nonZeroCurrent = computed(() => currentAgg.value.filter(p => p.value > 0));
const nonZeroPrevious = computed(() => previousAgg.value.filter(p => p.value > 0));

const totalCurrent = computed(() => currentAgg.value.reduce((s, p) => s + p.value, 0));
const totalPrevious = computed(() => previousAgg.value.reduce((s, p) => s + p.value, 0));

const hasComparison = computed(() => props.compare && previousAgg.value.length > 0);

const deltaAbsolute = computed(() => totalCurrent.value - totalPrevious.value);
const deltaPct = computed(() => {
    if (!hasComparison.value || totalPrevious.value === 0) return null;
    return ((totalCurrent.value - totalPrevious.value) / totalPrevious.value) * 100;
});

const bestPoint = computed(() => {
    if (!nonZeroCurrent.value.length) return null;
    return nonZeroCurrent.value.reduce((best, p) => p.value > best.value ? p : best);
});

const worstPoint = computed(() => {
    if (nonZeroCurrent.value.length < 2) return null;
    return nonZeroCurrent.value.reduce((worst, p) => p.value < worst.value ? p : worst);
});

// === Modo adaptativo ===================================================
const mode = computed(() => {
    if (totalCurrent.value === 0 && totalPrevious.value === 0) return 'empty';
    const buckets = currentAgg.value.length;
    const nonZero = nonZeroCurrent.value.length;
    // Rango muy corto (today, yesterday): bloques comparativos.
    if (buckets <= 2) return 'single';
    // Rango decente con datos abundantes o muchos buckets: linea.
    if (nonZero >= 4 || buckets > 14) return 'line';
    // Pocos datos en rango medio: bar chart comparativo.
    return 'bars';
});

// === Veredicto en lenguaje plano =======================================
const verdict = computed(() => {
    if (!hasComparison.value) {
        if (totalCurrent.value === 0) return { tone: 'neutral', icon: '·', text: 'No hubo ventas en este periodo' };
        return { tone: 'neutral', icon: '', text: '' };
    }
    if (totalCurrent.value === 0 && totalPrevious.value === 0) {
        return { tone: 'neutral', icon: '·', text: 'Sin ventas en este periodo ni en el anterior' };
    }
    if (totalPrevious.value === 0 && totalCurrent.value > 0) {
        return { tone: 'positive', icon: '↑', text: 'Comenzaron las ventas en este periodo' };
    }
    if (totalCurrent.value === 0 && totalPrevious.value > 0) {
        return { tone: 'negative', icon: '↓', text: `Sin ventas. Antes se vendió ${props.formatValue(totalPrevious.value)}` };
    }
    const pct = deltaPct.value ?? 0;
    if (Math.abs(pct) < 1) return { tone: 'neutral', icon: '·', text: 'Casi igual que el periodo anterior' };
    if (pct >= 10) return { tone: 'positive', icon: '↑', text: 'Vas bastante mejor que el periodo anterior' };
    if (pct > 0) return { tone: 'positive', icon: '↑', text: 'Vas un poco mejor que el periodo anterior' };
    if (pct >= -10) return { tone: 'negative', icon: '↓', text: 'Vas un poco peor que el periodo anterior' };
    return { tone: 'negative', icon: '↓', text: 'Vas bastante peor que el periodo anterior' };
});

const verdictColorClass = computed(() => ({
    positive: 'text-emerald-600',
    negative: 'text-red-600',
    neutral: 'text-gray-500',
}[verdict.value.tone]));

// === Bloques (single mode) =============================================
const comparisonScale = computed(() => Math.max(totalCurrent.value, totalPrevious.value, 1));
const currentBarWidth = computed(() => `${Math.max(2, (totalCurrent.value / comparisonScale.value) * 100)}%`);
const previousBarWidth = computed(() => `${Math.max(2, (totalPrevious.value / comparisonScale.value) * 100)}%`);

// === Bar chart (bars mode) =============================================
const barCategories = computed(() => currentAgg.value.map(p => p.label));

const barSeries = computed(() => {
    const series = [{ name: props.valueLabel, data: currentAgg.value.map(p => p.value) }];
    if (hasComparison.value) {
        const prev = previousAgg.value.slice(0, currentAgg.value.length).map(p => p.value);
        while (prev.length < currentAgg.value.length) prev.push(0);
        series.push({ name: 'Periodo previo', data: prev });
    }
    return series;
});

const barOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', stacked: false },
    colors: [props.color, props.previousColor],
    plotOptions: { bar: { columnWidth: hasComparison.value ? '60%' : '45%', borderRadius: 4, dataLabels: { position: 'top' } } },
    dataLabels: {
        enabled: true,
        enabledOnSeries: [0],
        formatter: (v) => v > 0 ? props.formatValue(v) : '',
        style: { fontSize: '11px', fontWeight: 700, colors: ['#111827'] },
        offsetY: -22,
    },
    xaxis: {
        categories: barCategories.value,
        labels: { style: { fontSize: '11px', colors: '#6b7280' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { labels: { formatter: abbreviated, style: { fontSize: '11px', colors: '#9ca3af' } } },
    tooltip: { y: { formatter: v => props.formatValue(v) } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 30 } },
    legend: { show: hasComparison.value, fontSize: '12px', position: 'top', horizontalAlign: 'right', markers: { radius: 3 } },
}));

// === Line chart (line mode) ============================================
const lineSeries = computed(() => {
    const series = [{ name: props.valueLabel, data: currentAgg.value.map(p => p.value) }];
    if (hasComparison.value) {
        const prev = previousAgg.value.slice(0, currentAgg.value.length).map(p => p.value);
        while (prev.length < currentAgg.value.length) prev.push(null);
        series.push({ name: 'Periodo previo', data: prev });
    }
    return series;
});

const lineOptions = computed(() => {
    const points = [];
    if (bestPoint.value) {
        points.push({
            x: bestPoint.value.label,
            y: bestPoint.value.value,
            marker: { size: 7, fillColor: '#10b981', strokeColor: '#fff', strokeWidth: 2 },
            label: {
                text: `Mejor · ${props.formatValue(bestPoint.value.value)}`,
                offsetY: -8,
                borderColor: 'transparent',
                style: { background: '#ecfdf5', color: '#047857', fontSize: '11px', fontWeight: 600, padding: { left: 6, right: 6, top: 2, bottom: 2 } },
            },
        });
    }
    if (worstPoint.value && worstPoint.value.label !== bestPoint.value?.label) {
        points.push({
            x: worstPoint.value.label,
            y: worstPoint.value.value,
            marker: { size: 7, fillColor: '#ef4444', strokeColor: '#fff', strokeWidth: 2 },
            label: {
                text: `Más bajo · ${props.formatValue(worstPoint.value.value)}`,
                offsetY: 22,
                borderColor: 'transparent',
                style: { background: '#fef2f2', color: '#991b1b', fontSize: '11px', fontWeight: 600, padding: { left: 6, right: 6, top: 2, bottom: 2 } },
            },
        });
    }

    return {
        chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
        colors: [props.color, props.previousColor],
        stroke: { curve: 'smooth', width: hasComparison.value ? [3, 2] : [3], dashArray: hasComparison.value ? [0, 4] : [0] },
        fill: { type: 'gradient', gradient: { shadeIntensity: 0.2, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 90, 100] } },
        markers: { size: 4, strokeWidth: 0, hover: { size: 6 } },
        dataLabels: { enabled: false },
        xaxis: {
            type: 'category',
            categories: currentAgg.value.map(p => p.label),
            labels: { style: { fontSize: '11px', colors: '#6b7280' }, hideOverlappingLabels: true, rotate: 0 },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: { labels: { formatter: abbreviated, style: { fontSize: '11px', colors: '#9ca3af' } } },
        tooltip: { theme: 'light', y: { formatter: (v) => v == null ? '—' : props.formatValue(v) } },
        grid: { borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 28, bottom: 16 } },
        legend: { show: hasComparison.value, fontSize: '12px', position: 'top', horizontalAlign: 'right', markers: { radius: 3 } },
        annotations: { points },
    };
});

// === Util ==============================================================
function abbreviated(v) {
    const n = Number(v ?? 0);
    if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000) return `$${Math.round(n / 1_000)}k`;
    return `$${Math.round(n)}`;
}

const granularityOptions = [
    { value: 'day', label: 'Por día' },
    { value: 'week', label: 'Por semana' },
    { value: 'month', label: 'Por mes' },
];

const bucketWord = computed(() => ({ day: 'día', week: 'semana', month: 'mes' }[granularity.value]));
const bucketWordPlural = computed(() => ({ day: 'días', week: 'semanas', month: 'meses' }[granularity.value]));

const currentLabelTitle = computed(() => {
    const n = nonZeroCurrent.value.length;
    return n === 1 ? '1 día con ventas' : `${n} ${bucketWordPlural.value} con ventas`;
});
const previousLabelTitle = computed(() => {
    if (!hasComparison.value) return 'Sin comparación';
    const n = nonZeroPrevious.value.length;
    return n === 1 ? '1 día con ventas' : `${n} ${bucketWordPlural.value} con ventas`;
});
</script>

<template>
    <ChartCard :title="title" :subtitle="subtitle">
        <template #actions>
            <select v-model="granularity"
                class="rounded-lg border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 shadow-sm focus:border-red-500 focus:ring-red-500">
                <option v-for="opt in granularityOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
        </template>

        <!-- ============== EMPTY MODE ============== -->
        <div v-if="mode === 'empty'"
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-12 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-white shadow-sm">
                <svg class="h-7 w-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
            </span>
            <p class="mt-3 text-base font-semibold text-gray-700">No hubo ventas en este periodo</p>
            <p class="mt-1 max-w-sm text-sm text-gray-500">
                Selecciona un rango más amplio o cambia la granularidad para ver tendencias.
            </p>
        </div>

        <!-- ============== HEADLINE + BODY ============== -->
        <div v-else class="space-y-5">
            <!-- HEADLINE: total + delta + verdicto, gigante y obvio -->
            <header class="flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 pb-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Total del periodo</p>
                    <p class="mt-0.5 text-3xl font-bold tracking-tight text-gray-900">{{ formatValue(totalCurrent) }}</p>
                </div>
                <div v-if="hasComparison" class="text-right">
                    <p v-if="deltaPct !== null"
                        class="inline-flex items-baseline gap-1 text-2xl font-bold"
                        :class="verdict.tone === 'positive' ? 'text-emerald-600' : verdict.tone === 'negative' ? 'text-red-600' : 'text-gray-500'">
                        <span class="text-xl" aria-hidden="true">{{ verdict.icon }}</span>
                        {{ Math.abs(deltaPct).toFixed(1) }}%
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ deltaAbsolute >= 0 ? '+' : '−' }}{{ formatValue(Math.abs(deltaAbsolute)) }} vs antes
                    </p>
                </div>
            </header>

            <p v-if="verdict.text" class="-mt-2 text-sm font-medium" :class="verdictColorClass">
                {{ verdict.text }}
            </p>

            <!-- ============ SINGLE MODE: bloques comparativos ============ -->
            <div v-if="mode === 'single'" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border-2 p-4"
                        :style="{ borderColor: color + '33', backgroundColor: color + '0d' }">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-600">Periodo actual</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ formatValue(totalCurrent) }}</p>
                        <p class="text-xs text-gray-500">{{ currentLabelTitle }}</p>
                        <div class="mt-3 h-3 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full transition-all"
                                :style="{ width: currentBarWidth, backgroundColor: color }"></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Periodo anterior</p>
                        <p class="mt-1 text-2xl font-bold text-gray-700">{{ hasComparison ? formatValue(totalPrevious) : '—' }}</p>
                        <p class="text-xs text-gray-500">{{ previousLabelTitle }}</p>
                        <div class="mt-3 h-3 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-gray-400 transition-all"
                                :style="{ width: hasComparison ? previousBarWidth : '0%' }"></div>
                        </div>
                    </div>
                </div>

                <!-- Lista de dias con ventas, opcional pero util -->
                <div v-if="nonZeroCurrent.length" class="rounded-xl border border-gray-100 bg-gray-50/60 p-3">
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                        {{ currentLabelTitle.charAt(0).toUpperCase() + currentLabelTitle.slice(1) }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="p in nonZeroCurrent" :key="p.key" class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-700">{{ p.label }}</span>
                            <span class="font-semibold text-gray-900">{{ formatValue(p.value) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- ============ BARS MODE: bar chart vertical ============ -->
            <div v-else-if="mode === 'bars'">
                <apexchart type="bar" height="300" :options="barOptions" :series="barSeries" />
                <div v-if="bestPoint || worstPoint" class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div v-if="bestPoint" class="rounded-lg border border-emerald-100 bg-emerald-50/40 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Mejor {{ bucketWord }}</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ formatValue(bestPoint.value) }}</p>
                        <p class="text-xs text-gray-500">{{ bestPoint.label }}</p>
                    </div>
                    <div v-if="worstPoint" class="rounded-lg border border-red-100 bg-red-50/40 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-red-700">{{ bucketWord.charAt(0).toUpperCase() + bucketWord.slice(1) }} más bajo</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ formatValue(worstPoint.value) }}</p>
                        <p class="text-xs text-gray-500">{{ worstPoint.label }}</p>
                    </div>
                </div>
            </div>

            <!-- ============ LINE MODE: linea con annotations ============ -->
            <div v-else>
                <apexchart type="area" height="320" :options="lineOptions" :series="lineSeries" />
                <div v-if="bestPoint || worstPoint" class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div v-if="bestPoint" class="rounded-lg border border-emerald-100 bg-emerald-50/40 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Mejor {{ bucketWord }}</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ formatValue(bestPoint.value) }}</p>
                        <p class="text-xs text-gray-500">{{ bestPoint.label }}</p>
                    </div>
                    <div v-if="worstPoint" class="rounded-lg border border-red-100 bg-red-50/40 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-red-700">{{ bucketWord.charAt(0).toUpperCase() + bucketWord.slice(1) }} más bajo</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ formatValue(worstPoint.value) }}</p>
                        <p class="text-xs text-gray-500">{{ worstPoint.label }}</p>
                    </div>
                </div>
            </div>
        </div>
    </ChartCard>
</template>
