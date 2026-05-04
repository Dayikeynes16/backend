<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Sección de "Desglose de ventas" para el modal de detalle de producto.
 * Lazy-fetcha el endpoint /productos/{id}/price-breakdown con los mismos
 * filtros del rango actual y muestra 3 vistas en tabs:
 *
 *   Por precio    → tiers agrupados por (unit_price, presentación, modo)
 *                   con stacked share bar y origen del precio coloreado
 *                   (catálogo / preferencial / descuento / markup).
 *
 *   Por cliente   → top compradores con su precio promedio y mínimo, para
 *                   detectar quién consigue precios más bajos.
 *
 *   Por tipo      → desglose por modo de venta (peso/presentación/pieza)
 *                   con margen comparativo.
 *
 * Visual: barras CSS (sin Apex) para mantener el modal liviano y evitar
 * dependencia adicional. Color coding por tipo de descuento.
 */
const props = defineProps({
    fetchUrl: { type: String, required: true },
    rangeLabel: { type: String, default: '' },
});

const tab = ref('price');
const loading = ref(false);
const error = ref(null);
const data = ref(null);

const fetchData = async () => {
    loading.value = true;
    error.value = null;
    try {
        const res = await fetch(props.fetchUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        data.value = await res.json();
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el desglose.';
    } finally {
        loading.value = false;
    }
};

watch(() => props.fetchUrl, () => {
    if (props.fetchUrl) fetchData();
}, { immediate: true });

// === Helpers ============================================================
const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const moneyShort = (v) => {
    const n = Number(v ?? 0);
    if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000) return `$${(n / 1_000).toFixed(1)}k`;
    return money(n);
};
const num = (v, d = 0) => Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: d, maximumFractionDigits: d });
const pct = (v) => v == null ? '—' : `${Number(v).toFixed(1)}%`;

// === Por precio: tier styling ==========================================
const TIER_STYLES = {
    catalog: { label: 'Catálogo', bar: 'bg-emerald-500', soft: 'bg-emerald-50', text: 'text-emerald-700', ring: 'ring-emerald-200', dot: 'bg-emerald-500' },
    preferential: { label: 'Preferencial', bar: 'bg-violet-500', soft: 'bg-violet-50', text: 'text-violet-700', ring: 'ring-violet-200', dot: 'bg-violet-500' },
    discounted: { label: 'Descuento', bar: 'bg-amber-500', soft: 'bg-amber-50', text: 'text-amber-700', ring: 'ring-amber-200', dot: 'bg-amber-500' },
    markup: { label: 'Sobreprecio', bar: 'bg-sky-500', soft: 'bg-sky-50', text: 'text-sky-700', ring: 'ring-sky-200', dot: 'bg-sky-500' },
};
const tierStyle = (kind) => TIER_STYLES[kind] || TIER_STYLES.catalog;

const tiers = computed(() => data.value?.by_price ?? []);
const totalRevenue = computed(() => data.value?.summary?.revenue_total ?? 0);
const totalVolume = computed(() => data.value?.summary?.volume_total ?? 0);

const tierShare = (tier) => {
    if (totalRevenue.value <= 0) return 0;
    return (tier.revenue / totalRevenue.value) * 100;
};

// Stacked bar: cada segmento proporcional al revenue del tier.
const stackedSegments = computed(() => tiers.value.map(t => ({
    ...t,
    share: tierShare(t),
})));

const summary = computed(() => data.value?.summary ?? {});
const product = computed(() => data.value?.product ?? null);

// === Por cliente =======================================================
const customers = computed(() => data.value?.by_customer ?? []);
const customerMaxRevenue = computed(() => Math.max(1, ...customers.value.map(c => c.revenue)));
const catalogPrice = computed(() => product.value?.catalog_price ?? 0);

const customerPriceTone = (c) => {
    if (!catalogPrice.value) return 'text-gray-700';
    if (c.lowest_unit_price < catalogPrice.value * 0.7) return 'text-violet-700 font-semibold'; // muy por debajo
    if (c.lowest_unit_price < catalogPrice.value) return 'text-amber-700';
    return 'text-gray-700';
};

// === Por tipo ==========================================================
const SALE_TYPE_LABELS = {
    weight: { label: 'Por peso', tone: 'amber', icon: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75v6.75M9 12h6' },
    presentation: { label: 'Por presentación', tone: 'sky', icon: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9' },
    piece: { label: 'Por pieza', tone: 'violet', icon: 'M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122' },
};
const SALE_TYPE_TONE_CLASSES = {
    amber: { wash: 'from-amber-50 to-white', icon: 'bg-amber-100 text-amber-700', accent: 'text-amber-700' },
    sky: { wash: 'from-sky-50 to-white', icon: 'bg-sky-100 text-sky-700', accent: 'text-sky-700' },
    violet: { wash: 'from-violet-50 to-white', icon: 'bg-violet-100 text-violet-700', accent: 'text-violet-700' },
};
const saleTypes = computed(() => data.value?.by_sale_type ?? []);

const saleTypeStyleFor = (mode) => {
    const meta = SALE_TYPE_LABELS[mode] ?? { label: mode, tone: 'amber', icon: SALE_TYPE_LABELS.weight.icon };
    return { ...meta, classes: SALE_TYPE_TONE_CLASSES[meta.tone] };
};

// === UI state ==========================================================
const tabs = computed(() => [
    { key: 'price', label: 'Por precio', count: tiers.value.length },
    { key: 'customer', label: 'Por cliente', count: customers.value.length },
    { key: 'type', label: 'Por tipo', count: saleTypes.value.length },
]);

const hasAnyData = computed(() => tiers.value.length > 0 || customers.value.length > 0);
</script>

<template>
    <section class="border-t border-gray-100 pt-5">
        <!-- Header de la sección -->
        <header class="flex items-baseline justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Desglose de ventas</p>
                <p v-if="rangeLabel" class="mt-0.5 text-[11px] font-medium text-gray-400">{{ rangeLabel }}</p>
            </div>
            <span v-if="!loading && hasAnyData" class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">
                {{ summary.tiers_count }} {{ summary.tiers_count === 1 ? 'precio' : 'precios' }}
            </span>
        </header>

        <!-- Loading -->
        <div v-if="loading" class="mt-3 space-y-2">
            <div class="h-12 animate-pulse rounded-xl bg-gray-100"></div>
            <div class="h-20 animate-pulse rounded-xl bg-gray-100"></div>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-100">
            {{ error }}
        </div>

        <!-- Empty -->
        <div v-else-if="!hasAnyData" class="mt-3 rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-4 py-6 text-center">
            <p class="text-sm font-medium text-gray-500">Sin ventas en el rango</p>
            <p class="mt-1 text-xs text-gray-400">Cambia el rango o verifica los estados seleccionados.</p>
        </div>

        <!-- Contenido -->
        <div v-else class="mt-3">
            <!-- Tabs -->
            <div class="inline-flex rounded-xl bg-gray-100 p-0.5 text-xs font-semibold">
                <button v-for="t in tabs" :key="t.key" type="button" @click="tab = t.key"
                    :class="[
                        'rounded-lg px-3 py-1.5 transition',
                        tab === t.key
                            ? 'bg-white text-gray-900 shadow-sm'
                            : 'text-gray-500 hover:text-gray-700',
                    ]">
                    {{ t.label }}
                    <span class="ml-1 opacity-60">· {{ t.count }}</span>
                </button>
            </div>

            <!-- ======== TAB: Por precio ======== -->
            <div v-if="tab === 'price'" class="mt-4 space-y-4">
                <!-- Stacked share bar -->
                <div>
                    <p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-gray-500">Composición del ingreso</p>
                    <div class="flex h-3 w-full overflow-hidden rounded-full ring-1 ring-gray-100">
                        <div v-for="(seg, i) in stackedSegments" :key="i"
                            :class="[tierStyle(seg.discount_kind).bar, 'transition-all']"
                            :style="{ width: seg.share + '%' }"
                            :title="`${tierStyle(seg.discount_kind).label} · ${money(seg.unit_price)} · ${seg.share.toFixed(1)}%`"></div>
                    </div>
                    <!-- Leyenda compacta -->
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-gray-500">
                        <span v-for="seg in stackedSegments" :key="seg.unit_price + seg.discount_kind + (seg.label||'')" class="inline-flex items-center gap-1">
                            <span :class="['h-1.5 w-1.5 rounded-full', tierStyle(seg.discount_kind).dot]"></span>
                            {{ tierStyle(seg.discount_kind).label }} {{ money(seg.unit_price) }} · {{ seg.share.toFixed(0) }}%
                        </span>
                    </div>
                </div>

                <!-- Tiers detail -->
                <div class="space-y-2">
                    <div v-for="(t, i) in tiers" :key="i"
                        :class="['relative rounded-2xl px-4 py-3 ring-1 transition', tierStyle(t.discount_kind).soft, tierStyle(t.discount_kind).ring]">
                        <!-- Cabecera del tier -->
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2">
                                    <p class="font-mono text-lg font-extrabold tabular-nums text-gray-900">{{ money(t.unit_price) }}</p>
                                    <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset', tierStyle(t.discount_kind).text, tierStyle(t.discount_kind).soft, tierStyle(t.discount_kind).ring]">
                                        {{ tierStyle(t.discount_kind).label }}
                                    </span>
                                </div>
                                <p class="mt-0.5 truncate text-xs font-medium text-gray-600">{{ t.label }}</p>
                                <p v-if="t.original_unit_price !== null && t.original_unit_price !== t.unit_price"
                                    class="mt-0.5 text-[11px]"
                                    :class="t.discount_kind === 'markup' ? 'text-sky-700' : 'text-gray-500'">
                                    Catálogo: <span class="line-through">{{ money(t.original_unit_price) }}</span>
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(t.revenue) }}</p>
                                <p class="text-[10px] font-medium text-gray-500">{{ tierShare(t).toFixed(1) }}% del ingreso</p>
                            </div>
                        </div>

                        <!-- Barra de share -->
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/70">
                            <div :class="[tierStyle(t.discount_kind).bar, 'h-full rounded-full transition-all']"
                                :style="{ width: Math.max(2, tierShare(t)) + '%' }"></div>
                        </div>

                        <!-- Stats inline -->
                        <div class="mt-2.5 grid grid-cols-3 gap-2 text-[11px]">
                            <div>
                                <span class="font-semibold text-gray-600">Vendido</span>
                                <p class="font-mono tabular-nums text-gray-900">
                                    <template v-if="t.kg_equivalent != null && t.is_weight">{{ num(t.kg_equivalent, 3) }} kg</template>
                                    <template v-else>× {{ num(t.volume, 0) }}</template>
                                </p>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-600">Tickets</span>
                                <p class="font-mono tabular-nums text-gray-900">{{ t.ticket_count }}</p>
                            </div>
                            <div>
                                <span class="font-semibold text-gray-600">Margen</span>
                                <p :class="['font-mono tabular-nums', t.margin_pct == null ? 'text-gray-400' : t.margin_pct < 0 ? 'text-red-600 font-bold' : 'text-emerald-700']">
                                    {{ pct(t.margin_pct) }}
                                </p>
                            </div>
                        </div>

                        <!-- Top customer (solo preferencial) -->
                        <div v-if="t.top_customer" class="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-white/80 px-2.5 py-1 text-[11px] font-medium text-violet-700 ring-1 ring-violet-200">
                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7 9a7 7 0 1 1 14 0H3Z" clip-rule="evenodd" />
                            </svg>
                            {{ t.top_customer.name }} · {{ num(t.top_customer.volume, 0) }} unid.
                        </div>

                        <!-- Margen negativo (alerta sobre la fila) -->
                        <div v-if="t.margin_pct !== null && t.margin_pct < 0"
                            class="mt-2 flex items-center gap-1.5 rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-medium text-red-700 ring-1 ring-red-100">
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
                            Vendido por debajo del costo
                        </div>
                    </div>
                </div>

                <!-- Alerta lost-to-discounts -->
                <div v-if="summary.lost_to_discounts > 0"
                    class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                    <svg class="h-5 w-5 shrink-0 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-amber-900">Los descuentos te costaron {{ money(summary.lost_to_discounts) }} en ingresos potenciales.</p>
                        <p class="mt-0.5 text-xs text-amber-800">Comparado contra los precios de catálogo congelados al momento de cada venta.</p>
                    </div>
                </div>
            </div>

            <!-- ======== TAB: Por cliente ======== -->
            <div v-else-if="tab === 'customer'" class="mt-4 space-y-2">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">
                    {{ customers.length }} {{ customers.length === 1 ? 'comprador' : 'compradores' }}
                    <span v-if="catalogPrice" class="ml-1 font-medium text-gray-400">· catálogo {{ money(catalogPrice) }}</span>
                </p>

                <div v-for="(c, i) in customers" :key="i"
                    class="rounded-2xl bg-white px-4 py-3 ring-1 ring-gray-100 transition hover:ring-gray-200">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold text-gray-900">{{ c.customer_name }}</p>
                            <p class="text-[11px] text-gray-500">
                                {{ c.ticket_count }} {{ c.ticket_count === 1 ? 'ticket' : 'tickets' }}
                                · {{ c.lines }} {{ c.lines === 1 ? 'línea' : 'líneas' }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(c.revenue) }}</p>
                            <p class="text-[10px] font-medium text-gray-500">{{ ((c.revenue / customerMaxRevenue) * 100).toFixed(0) }}% del top</p>
                        </div>
                    </div>

                    <!-- Barra de revenue relativa al top -->
                    <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-gray-900 transition-all"
                            :style="{ width: Math.max(2, (c.revenue / customerMaxRevenue) * 100) + '%' }"></div>
                    </div>

                    <!-- Precios por cliente -->
                    <div class="mt-3 grid grid-cols-3 gap-2 text-[11px]">
                        <div>
                            <span class="font-semibold text-gray-600">Promedio</span>
                            <p :class="['font-mono tabular-nums', customerPriceTone(c)]">{{ money(c.avg_unit_price) }}</p>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-600">Más barato</span>
                            <p :class="['font-mono tabular-nums', customerPriceTone(c)]">
                                {{ money(c.lowest_unit_price) }}
                                <span v-if="catalogPrice && c.lowest_unit_price < catalogPrice" class="ml-0.5 text-[10px] font-medium">
                                    ({{ ((1 - c.lowest_unit_price / catalogPrice) * 100).toFixed(0) }}% off)
                                </span>
                            </p>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-600">Margen</span>
                            <p :class="['font-mono tabular-nums', c.margin_pct == null ? 'text-gray-400' : c.margin_pct < 0 ? 'text-red-600 font-bold' : 'text-emerald-700']">
                                {{ pct(c.margin_pct) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======== TAB: Por tipo ======== -->
            <div v-else-if="tab === 'type'" class="mt-4 space-y-3">
                <div v-for="s in saleTypes" :key="s.mode"
                    :class="['relative overflow-hidden rounded-2xl bg-gradient-to-br p-4 ring-1 ring-gray-100', saleTypeStyleFor(s.mode).classes.wash]">
                    <div class="flex items-start gap-3">
                        <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', saleTypeStyleFor(s.mode).classes.icon]">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="saleTypeStyleFor(s.mode).icon" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900">{{ saleTypeStyleFor(s.mode).label }}</p>
                            <p class="text-[11px] text-gray-600">
                                {{ s.ticket_count }} {{ s.ticket_count === 1 ? 'ticket' : 'tickets' }}
                                · {{ s.lines }} {{ s.lines === 1 ? 'línea' : 'líneas' }}
                                · precio promedio {{ money(s.avg_unit_price) }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p :class="['font-mono text-base font-extrabold tabular-nums', saleTypeStyleFor(s.mode).classes.accent]">{{ moneyShort(s.revenue) }}</p>
                            <p :class="['text-[10px] font-medium', s.margin_pct == null ? 'text-gray-400' : s.margin_pct < 0 ? 'text-red-600' : 'text-emerald-700']">
                                margen {{ pct(s.margin_pct) }}
                            </p>
                        </div>
                    </div>

                    <!-- Barra de share del tipo -->
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/70">
                        <div :class="['h-full rounded-full transition-all', saleTypeStyleFor(s.mode).classes.icon.split(' ').find(c => c.startsWith('bg-'))]"
                            :style="{ width: Math.max(2, totalRevenue > 0 ? (s.revenue / totalRevenue) * 100 : 0) + '%' }"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
