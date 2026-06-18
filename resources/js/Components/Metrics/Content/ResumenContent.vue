<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatCurrency, formatNumber } from '@/composables/useCurrency';

const props = defineProps({
    data: { type: Object, default: null },
    scope: { type: String, default: 'empresa' }, // empresa | sucursal
});

const page = usePage();
const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);
const range = computed(() => page.props.range || null);

const pnl = computed(() => props.data?.pnl ?? {});
const kpis = computed(() => props.data?.kpis ?? {});
const coverage = computed(() => pnl.value.coverage ?? { pct: 100 });
const coverageLow = computed(() => (coverage.value.pct ?? 100) < 100);

const alerts = computed(() => props.data?.alerts ?? []);
const topProducts = computed(() => props.data?.top_products ?? []);
const branches = computed(() => props.data?.branch_comparison ?? []);
const maxBranch = computed(() => branches.value.reduce((m, b) => Math.max(m, b.net_sales), 0) || 1);

const pct = (v) => `${Number(v ?? 0).toFixed(1)}%`;

// Delta chip: dir (up/down/flat), y si es "bueno" (verde) o "malo" (rojo).
// `invert` para Gastos (subir es malo).
const deltaChip = (kpi, { invert = false, points = false } = {}) => {
    if (!kpi) return null;
    const raw = points ? kpi.delta_pts : kpi.delta_pct;
    if (raw === null || raw === undefined) return { text: 'sin período previo', dir: 'flat', good: null };
    const up = raw > 0;
    const flat = raw === 0;
    const good = flat ? null : (invert ? !up : up);
    const arrow = flat ? '→' : (up ? '▲' : '▼');
    const text = points
        ? `${arrow} ${Math.abs(raw).toFixed(1)} pts`
        : `${arrow} ${Math.abs(raw).toFixed(1)}%`;
    return { text, dir: flat ? 'flat' : (up ? 'up' : 'down'), good };
};

const u = computed(() => deltaChip(kpis.value.utilidad_neta));
const v = computed(() => deltaChip(kpis.value.ventas));
const m = computed(() => deltaChip(kpis.value.margen, { points: true }));
const g = computed(() => deltaChip(kpis.value.gastos, { invert: true }));

// Waterfall: barras CSS relativas a las ventas con costo (la base mayor).
const wf = computed(() => {
    const base = Math.max(pnl.value.revenue_covered ?? 0, 1);
    const h = (val) => `${Math.max(2, Math.round((Math.abs(val) / base) * 100))}%`;
    return {
        ventas: { val: pnl.value.revenue_covered ?? 0, h: h(pnl.value.revenue_covered) },
        cmv: { val: pnl.value.cmv ?? 0, h: h(pnl.value.cmv) },
        bruta: { val: pnl.value.utilidad_bruta ?? 0, h: h(pnl.value.utilidad_bruta) },
        gastos: { val: pnl.value.gastos ?? 0, h: h(pnl.value.gastos) },
        neta: { val: pnl.value.utilidad_neta ?? 0, h: h(pnl.value.utilidad_neta) },
    };
});

const dotClass = { red: 'bg-red-500', amber: 'bg-amber-500', blue: 'bg-blue-500' };

const branchHref = (branchId) => {
    const q = { tenant: slug.value };
    if (range.value?.preset) q.preset = range.value.preset;
    else if (range.value?.from) { q.from = range.value.from; q.to = range.value.to; }
    q.branch_id = branchId;
    return route('empresa.metricas.ventas', q);
};

const chipTone = (d) => {
    if (!d || d.good === null) return 'bg-gray-100 text-gray-500';
    return d.good ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700';
};
const chipToneHero = (d) => {
    if (!d || d.good === null) return 'bg-white/20 text-white';
    return d.good ? 'bg-white/25 text-white' : 'bg-black/15 text-white';
};
</script>

<template>
    <div v-if="!data" class="rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-6 py-16 text-center text-sm text-gray-500">
        Sin datos para el período seleccionado.
    </div>

    <div v-else class="space-y-5">
        <!-- KPIs hero -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Utilidad neta (hero) -->
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-red-600 to-orange-500 p-5 text-white shadow-lg shadow-red-600/20">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-white/10"></div>
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-red-50">Utilidad neta</p>
                <p class="mt-2 text-3xl font-extrabold leading-none tracking-tight tabular-nums">{{ formatCurrency(pnl.utilidad_neta) }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <span v-if="u" :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold', chipToneHero(u)]">{{ u.text }}</span>
                    <span class="text-[11px] text-red-50/90">vs período anterior</span>
                </div>
                <p class="mt-2 text-[11px] text-red-50/80">
                    Utilidad bruta {{ formatCurrency(pnl.utilidad_bruta) }} − gastos {{ formatCurrency(pnl.gastos) }}
                    <span v-if="coverageLow"> · sobre {{ pct(coverage.pct) }} de ventas con costo</span>
                </p>
            </div>

            <!-- Ventas -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Ventas netas</p>
                <p class="mt-2 text-2xl font-extrabold leading-none tracking-tight tabular-nums text-gray-900">{{ formatCurrency(pnl.ventas_netas) }}</p>
                <span v-if="v" :class="['mt-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold', chipTone(v)]">{{ v.text }}</span>
            </div>

            <!-- Margen bruto -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Margen bruto</p>
                <p class="mt-2 text-2xl font-extrabold leading-none tracking-tight tabular-nums text-gray-900">{{ pct(pnl.margin_pct) }}</p>
                <span v-if="m" :class="['mt-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold', chipTone(m)]">{{ m.text }}</span>
            </div>

            <!-- Gastos -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-gray-500">Gastos operativos</p>
                <p class="mt-2 text-2xl font-extrabold leading-none tracking-tight tabular-nums text-gray-900">{{ formatCurrency(pnl.gastos) }}</p>
                <span v-if="g" :class="['mt-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold', chipTone(g)]">{{ g.text }}</span>
            </div>
        </div>

        <!-- ¿Gané dinero? + Alertas -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <!-- Waterfall -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">¿Gané dinero?</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Ventas con costo − costo de lo vendido = utilidad bruta − gastos = utilidad neta</p>
                    </div>
                    <span v-if="coverageLow" class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-700">
                        ⚠ {{ pct(coverage.pct) }} con costo
                    </span>
                </div>

                <div class="mt-5 flex items-end gap-2 sm:gap-3" style="height:170px">
                    <div class="flex h-full flex-1 flex-col items-center justify-end">
                        <span class="mb-1 text-[11px] font-extrabold tabular-nums text-emerald-700">{{ formatCurrency(wf.ventas.val) }}</span>
                        <div class="w-full rounded-t-lg bg-emerald-300" :style="{ height: wf.ventas.h }"></div>
                        <span class="mt-1.5 text-center text-[10px] font-semibold leading-tight text-gray-500">Ventas<br>con costo</span>
                    </div>
                    <span class="self-center pb-7 text-sm font-extrabold text-gray-300">−</span>
                    <div class="flex h-full flex-1 flex-col items-center justify-end">
                        <span class="mb-1 text-[11px] font-extrabold tabular-nums text-red-700">{{ formatCurrency(wf.cmv.val) }}</span>
                        <div class="w-full rounded-t-lg bg-red-300" :style="{ height: wf.cmv.h }"></div>
                        <span class="mt-1.5 text-center text-[10px] font-semibold leading-tight text-gray-500">Costo de<br>lo vendido</span>
                    </div>
                    <span class="self-center pb-7 text-sm font-extrabold text-gray-300">=</span>
                    <div class="flex h-full flex-1 flex-col items-center justify-end">
                        <span class="mb-1 text-[11px] font-extrabold tabular-nums text-teal-700">{{ formatCurrency(wf.bruta.val) }}</span>
                        <div class="w-full rounded-t-lg bg-teal-400" :style="{ height: wf.bruta.h }"></div>
                        <span class="mt-1.5 text-center text-[10px] font-semibold leading-tight text-gray-500">Utilidad<br>bruta</span>
                    </div>
                    <span class="self-center pb-7 text-sm font-extrabold text-gray-300">−</span>
                    <div class="flex h-full flex-1 flex-col items-center justify-end">
                        <span class="mb-1 text-[11px] font-extrabold tabular-nums text-orange-700">{{ formatCurrency(wf.gastos.val) }}</span>
                        <div class="w-full rounded-t-lg bg-orange-300" :style="{ height: wf.gastos.h }"></div>
                        <span class="mt-1.5 text-center text-[10px] font-semibold leading-tight text-gray-500">Gastos</span>
                    </div>
                    <span class="self-center pb-7 text-sm font-extrabold text-gray-300">=</span>
                    <div class="flex h-full flex-1 flex-col items-center justify-end">
                        <span class="mb-1 text-[11px] font-extrabold tabular-nums text-red-600">{{ formatCurrency(wf.neta.val) }}</span>
                        <div class="w-full rounded-t-lg bg-gradient-to-b from-red-600 to-orange-500" :style="{ height: wf.neta.h }"></div>
                        <span class="mt-1.5 text-center text-[10px] font-semibold leading-tight text-gray-500">Utilidad<br>neta</span>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-2 rounded-xl border border-dashed border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                    <span>💵</span>
                    <span><span class="font-bold text-gray-900">Compras del período: {{ formatCurrency(data.compras) }}</span> — egreso de caja (reposición), no se resta de la utilidad.</span>
                </div>
            </div>

            <!-- Alertas -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
                <h3 class="text-base font-bold text-gray-900">Alertas</h3>
                <p class="mt-0.5 text-xs text-gray-500">Derivadas de tus datos</p>
                <div v-if="alerts.length" class="mt-3 divide-y divide-gray-100">
                    <div v-for="(a, i) in alerts" :key="i" class="flex items-start gap-2.5 py-2.5">
                        <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', dotClass[a.severity] || 'bg-gray-400']"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-800">{{ a.title }}</p>
                            <p class="text-xs text-gray-500">{{ a.detail }}</p>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-4 flex flex-col items-center justify-center rounded-xl border border-dashed border-emerald-200 bg-emerald-50/50 px-4 py-8 text-center">
                    <span class="text-2xl">✓</span>
                    <p class="mt-1 text-sm font-semibold text-emerald-700">Todo en orden</p>
                    <p class="text-xs text-gray-500">No hay alertas en este período.</p>
                </div>
            </div>
        </div>

        <!-- Top productos + comparativa sucursales -->
        <div class="grid grid-cols-1 gap-4" :class="scope === 'empresa' ? 'lg:grid-cols-2' : ''">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-bold text-gray-900">Productos que más aportan</h3>
                <p class="mt-0.5 text-xs text-gray-500">Por utilidad bruta en el período</p>
                <table class="mt-3 w-full">
                    <thead>
                        <tr class="border-b border-gray-100 text-[10px] uppercase tracking-wide text-gray-400">
                            <th class="py-2 text-left font-bold">Producto</th>
                            <th class="py-2 text-right font-bold">Vendido</th>
                            <th class="py-2 text-right font-bold">Margen</th>
                            <th class="py-2 text-right font-bold">Utilidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in topProducts" :key="p.product_id" class="border-b border-gray-50 last:border-0">
                            <td class="py-2.5 text-sm font-semibold text-gray-900">
                                {{ p.product_name }}
                                <span v-if="p.has_missing_cost" class="ml-1 text-[10px] text-amber-600" title="Algunas ventas sin costo capturado">⚠</span>
                            </td>
                            <td class="py-2.5 text-right text-sm tabular-nums text-gray-600">{{ formatCurrency(p.revenue) }}</td>
                            <td class="py-2.5 text-right text-sm font-bold tabular-nums" :class="p.margin_pct < 15 ? 'text-red-600' : 'text-emerald-600'">{{ pct(p.margin_pct) }}</td>
                            <td class="py-2.5 text-right text-sm font-bold tabular-nums text-gray-900">{{ formatCurrency(p.gross_profit) }}</td>
                        </tr>
                        <tr v-if="!topProducts.length">
                            <td colspan="4" class="py-8 text-center text-sm text-gray-400">Sin ventas con costo en el período.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="scope === 'empresa' && branches.length" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-bold text-gray-900">Comparativa por sucursal</h3>
                <p class="mt-0.5 text-xs text-gray-500">Ventas del período · clic para ver detalle</p>
                <div class="mt-4 space-y-3">
                    <Link v-for="b in branches" :key="b.branch_id" :href="branchHref(b.branch_id)"
                        class="group flex items-center gap-3 text-sm">
                        <span class="w-20 shrink-0 truncate font-semibold text-gray-700 group-hover:text-red-600">{{ b.name }}</span>
                        <span class="h-4 flex-1 overflow-hidden rounded-md bg-gray-100">
                            <span class="block h-full rounded-md bg-gradient-to-r from-red-600 to-orange-500" :style="{ width: `${Math.round((b.net_sales / maxBranch) * 100)}%` }"></span>
                        </span>
                        <span class="w-24 shrink-0 text-right font-bold tabular-nums text-gray-900">{{ formatCurrency(b.net_sales) }}</span>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
