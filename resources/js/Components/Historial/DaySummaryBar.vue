<script setup>
/**
 * DaySummaryBar — franja resumen del día para Historial y Pagos.
 *
 * Reutilizable. Diseño iOS, colapsable, persistente en localStorage.
 *
 * Props:
 * - title         string · "Hoy · Lun 28 abr 2026"
 * - legend        string · texto explicativo en gris (ej. "Sólo cobradas")
 * - kpis          array · [{ label, value, format: 'currency'|'number'|'text' }]
 * - byMethod      array · [{ method, amount, count? }] (filtra por activos)
 * - paymentMethods array · slugs activos (cash, card, transfer, etc.)
 * - storageKey    string · key para persistir el estado expand/collapse
 *
 * Slot footer · contenido adicional debajo (conteo por estado, etc).
 */
import { computed, ref, onMounted } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    legend: { type: String, default: '' },
    kpis: { type: Array, default: () => [] },
    byMethod: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    storageKey: { type: String, default: 'day-summary-bar' },
});

const STORAGE_PREFIX = 'cn:dsb:';
const collapsed = ref(false);

onMounted(() => {
    try {
        const saved = localStorage.getItem(STORAGE_PREFIX + props.storageKey);
        if (saved !== null) collapsed.value = saved === '1';
    } catch (e) { /* localStorage no disponible */ }
});

const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem(STORAGE_PREFIX + props.storageKey, collapsed.value ? '1' : '0');
    } catch (e) { /* */ }
};

const methodMeta = {
    cash:     { label: 'Efectivo',       hue: 'emerald',
                icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    card:     { label: 'Tarjeta',        hue: 'sky',
                icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    transfer: { label: 'Transferencia',  hue: 'violet',
                icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
    credit:   { label: 'Crédito',        hue: 'amber',
                icon: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
};

const hueClasses = {
    emerald: { bg: 'bg-emerald-50', text: 'text-emerald-700', icon: 'text-emerald-600', ring: 'ring-emerald-100' },
    sky:     { bg: 'bg-sky-50',     text: 'text-sky-700',     icon: 'text-sky-600',     ring: 'ring-sky-100' },
    violet:  { bg: 'bg-violet-50',  text: 'text-violet-700',  icon: 'text-violet-600',  ring: 'ring-violet-100' },
    amber:   { bg: 'bg-amber-50',   text: 'text-amber-700',   icon: 'text-amber-600',   ring: 'ring-amber-100' },
    gray:    { bg: 'bg-gray-50',    text: 'text-gray-700',    icon: 'text-gray-500',    ring: 'ring-gray-100' },
};

const visibleMethods = computed(() => {
    const active = new Set(props.paymentMethods || []);
    return props.byMethod
        .filter(m => active.size === 0 || active.has(m.method))
        .map(m => {
            const meta = methodMeta[m.method] || { label: titleCase(m.method), hue: 'gray', icon: methodMeta.cash.icon };
            return {
                ...m,
                label: meta.label,
                hue: meta.hue,
                icon: meta.icon,
                classes: hueClasses[meta.hue] || hueClasses.gray,
            };
        });
});

const total = computed(() => visibleMethods.value.reduce((s, m) => s + Number(m.amount || 0), 0));

const methodPct = (amount) => {
    if (total.value <= 0) return 0;
    return Math.round((Number(amount) / total.value) * 100);
};

const fmt = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtNumber = (v) => Number(v ?? 0).toLocaleString('es-MX');

const formatKpi = (kpi) => {
    if (kpi.format === 'currency') return fmt(kpi.value);
    if (kpi.format === 'number') return fmtNumber(kpi.value);
    return kpi.value ?? '—';
};

function titleCase(s) {
    return String(s).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}
</script>

<template>
    <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
        <!-- Header -->
        <header class="flex items-start justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <h2 class="text-sm font-bold text-gray-900">{{ title }}</h2>
                <p v-if="legend" class="mt-0.5 text-xs text-gray-500">{{ legend }}</p>
            </div>
            <button type="button" @click="toggleCollapsed"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                :aria-label="collapsed ? 'Expandir resumen' : 'Colapsar resumen'">
                <svg class="h-4 w-4 transition-transform" :class="collapsed ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
        </header>

        <!-- Body (colapsable) -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            leave-active-class="transition-all duration-150 ease-in"
            enter-from-class="opacity-0 -translate-y-1 max-h-0"
            enter-to-class="opacity-100 translate-y-0 max-h-[400px]"
            leave-from-class="opacity-100 max-h-[400px]"
            leave-to-class="opacity-0 -translate-y-1 max-h-0">
            <div v-if="!collapsed" class="overflow-hidden">
                <!-- KPIs -->
                <div v-if="kpis.length" class="grid gap-4 border-t border-gray-100 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="(kpi, i) in kpis" :key="i" class="rounded-xl bg-gray-50/60 px-4 py-3 ring-1 ring-gray-100">
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">{{ kpi.label }}</p>
                        <p class="mt-1 font-mono text-xl font-bold tabular-nums text-gray-900">{{ formatKpi(kpi) }}</p>
                        <p v-if="kpi.hint" class="mt-0.5 text-[11px] text-gray-500">{{ kpi.hint }}</p>
                    </div>
                </div>

                <!-- Métodos de pago -->
                <div v-if="visibleMethods.length" class="border-t border-gray-100 px-5 py-4">
                    <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400">Por método de pago</p>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="m in visibleMethods" :key="m.method"
                            :class="['flex items-center gap-3 rounded-xl px-3.5 py-3 ring-1', m.classes.bg, m.classes.ring]">
                            <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm', m.classes.icon]">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="m.icon" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p :class="['text-[10px] font-bold uppercase tracking-wider', m.classes.text]">{{ m.label }}</p>
                                <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ fmt(m.amount) }}</p>
                            </div>
                            <div class="text-right">
                                <p :class="['text-xs font-bold', m.classes.text]">{{ methodPct(m.amount) }}%</p>
                                <p v-if="m.count != null" class="text-[10px] text-gray-500">{{ m.count }} pago{{ m.count !== 1 ? 's' : '' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slot para conteo por estado u otro footer -->
                <div v-if="$slots.footer" class="border-t border-gray-100 px-5 py-3">
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </section>
</template>
