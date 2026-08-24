<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DatePicker from '@/Components/DatePicker.vue';

/**
 * Lista de ventas modificadas después de cobrarse, agrupadas por venta.
 *
 * Compartida por la pantalla de empresa (todas las sucursales) y la de sucursal
 * (la suya). La diferencia se reduce a `scope` y a la ruta de recarga; el resto
 * del comportamiento es idéntico a propósito, para que ambas se lean igual.
 */
const props = defineProps({
    sales: { type: Object, required: true },
    summary: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    eventLabels: { type: Object, default: () => ({}) },
    scope: { type: String, default: 'sucursal' },
    routeName: { type: String, required: true },
    tenantSlug: { type: String, required: true },
});

const from = ref(props.filters?.from || props.summary?.from || '');
const to = ref(props.filters?.to || props.summary?.to || '');
const branchId = ref(props.filters?.branch_id || '');
const event = ref(props.filters?.event || '');
const userId = ref(props.filters?.user_id || '');

const applyFilters = () => {
    router.get(route(props.routeName, props.tenantSlug), {
        from: from.value || undefined,
        to: to.value || undefined,
        branch_id: branchId.value || undefined,
        event: event.value || undefined,
        user_id: userId.value || undefined,
    }, { preserveState: true, replace: true });
};

watch([from, to, branchId, event, userId], applyFilters);

const clearFilters = () => {
    branchId.value = '';
    event.value = '';
    userId.value = '';
};

const hasExtraFilters = computed(() => !!branchId.value || !!event.value || !!userId.value);

// ── Formato ─────────────────────────────────────────────────────────────
const money = (n) => {
    const v = parseFloat(n ?? 0);
    const sign = v < 0 ? '−' : v > 0 ? '+' : '';
    return `${sign}$${Math.abs(v).toFixed(2)}`;
};

const plainMoney = (n) => `$${parseFloat(n ?? 0).toFixed(2)}`;

const time = (iso) => iso ? new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '';
const day = (iso) => iso ? new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' }) : '';

// Las fechas del resumen llegan como 'YYYY-MM-DD', sin hora. `new Date()` lee
// esa forma como medianoche UTC, así que al pintarla en la zona del navegador
// (UTC−6) retrocedía un día: el periodo de hoy se leía como el de ayer.
const dayOnly = (ymd) => {
    if (! ymd) return '';
    const [y, m, d] = String(ymd).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
};

/** Cada evento guarda su `changes` con una forma distinta; aquí se vuelve una frase. */
const describe = (m) => {
    const c = m.changes || {};
    switch (m.event) {
        case 'item_added':
            return { text: c.product, detail: `agregado por ${plainMoney(c.subtotal)}` };
        case 'item_removed':
            return { text: c.product, detail: `quitado (valía ${plainMoney(c.subtotal)})` };
        case 'item_updated': {
            const parts = Object.entries(c.diff || {}).map(([field, pair]) => {
                const label = { unit_price: 'precio', quantity: 'cantidad', subtotal: 'subtotal' }[field] || field;
                return `${label}: ${pair[0]} → ${pair[1]}`;
            });
            return { text: c.product, detail: parts.join(' · ') };
        }
        case 'payment_added':
            return { text: `Pago ${c.method || ''}`, detail: `registrado por ${plainMoney(c.amount)}` };
        case 'payment_updated': {
            const [before, after] = c.amount || [];
            const methodChanged = c.method && c.method[0] !== c.method[1];
            const detail = `${plainMoney(before)} → ${plainMoney(after)}`;
            return { text: 'Pago', detail: methodChanged ? `${detail} · ${c.method[0]} → ${c.method[1]}` : detail };
        }
        case 'payment_deleted':
            return { text: `Pago ${c.method || ''}`, detail: `borrado (era ${plainMoney(c.amount)})` };
        case 'payment_cancelled':
            return { text: `Pago ${c.method || ''}`, detail: `cancelado: ${c.reason || 'sin motivo'}` };
        case 'cancelled':
            return { text: 'Venta cancelada', detail: c.reason || 'sin motivo' };
        case 'reopened':
            return { text: 'Venta reabierta', detail: `estaba cobrada por ${plainMoney(c.total)}` };
        case 'customer_assigned':
            return { text: 'Cliente asignado', detail: c.customer };
        case 'customer_removed':
            return { text: 'Cliente quitado', detail: c.customer || '' };
        default:
            return { text: m.event_label, detail: '' };
    }
};

const eventTone = (ev) => {
    if (ev.startsWith('item_')) return 'bg-amber-50 text-amber-700 ring-amber-600/20';
    if (ev.startsWith('payment_')) return 'bg-red-50 text-red-700 ring-red-600/20';
    if (ev === 'cancelled' || ev === 'reopened') return 'bg-violet-50 text-violet-700 ring-violet-600/20';
    return 'bg-blue-50 text-blue-700 ring-blue-600/20';
};

const open = ref(new Set());
const toggle = (saleId) => {
    const next = new Set(open.value);
    next.has(saleId) ? next.delete(saleId) : next.add(saleId);
    open.value = next;
};

// La venta más golpeada marca el ancho máximo de la barra; sin referencia, los
// tamaños relativos no dirían nada.
const worst = computed(() => Math.max(1, ...props.sales.data.map((s) => Math.abs(s.net_effect || 0))));
const barWidth = (row) => `${Math.round((Math.abs(row.net_effect || 0) / worst.value) * 100)}%`;
</script>

<template>
    <div class="space-y-5">
        <!-- Resumen del periodo -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-5 ring-1 ring-gray-100">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Ventas modificadas</p>
                <p class="mt-1 font-mono text-3xl font-extrabold tabular-nums text-gray-900">{{ summary.sales_touched }}</p>
                <p class="mt-0.5 text-xs text-gray-400">{{ summary.movement_count }} cambios en total</p>
            </div>
            <div class="rounded-xl bg-white p-5 ring-1 ring-gray-100">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Efecto en el dinero</p>
                <p class="mt-1 font-mono text-3xl font-extrabold tabular-nums"
                    :class="summary.net_effect < 0 ? 'text-red-600' : summary.net_effect > 0 ? 'text-emerald-600' : 'text-gray-300'">
                    {{ money(summary.net_effect) }}
                </p>
                <p class="mt-0.5 text-xs text-gray-400">Sin contar cambios de cliente</p>
            </div>
            <div class="rounded-xl bg-white p-5 ring-1 ring-gray-100">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Periodo</p>
                <p class="mt-1 text-lg font-bold text-gray-900">{{ dayOnly(summary.from) }} – {{ dayOnly(summary.to) }}</p>
                <p class="mt-0.5 text-xs text-gray-400">Cambios hechos en estas fechas</p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 ring-1 ring-gray-100">
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-400">Del</span>
                <DatePicker v-model="from" />
                <span class="text-xs font-semibold text-gray-400">al</span>
                <DatePicker v-model="to" />
            </div>

            <select v-if="scope === 'empresa'" v-model="branchId"
                class="rounded-xl border-0 bg-gray-50 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 focus:bg-white focus:ring-2 focus:ring-red-500">
                <option value="">Todas las sucursales</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>

            <select v-model="event"
                class="rounded-xl border-0 bg-gray-50 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 focus:bg-white focus:ring-2 focus:ring-red-500">
                <option value="">Todos los cambios</option>
                <option v-for="(label, value) in eventLabels" :key="value" :value="value">{{ label }}</option>
            </select>

            <select v-model="userId"
                class="rounded-xl border-0 bg-gray-50 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 focus:bg-white focus:ring-2 focus:ring-red-500">
                <option value="">Cualquier usuario</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>

            <button v-if="hasExtraFilters" type="button" @click="clearFilters"
                class="text-xs font-medium text-gray-400 transition hover:text-gray-600">Limpiar</button>
        </div>

        <!-- Lista por venta -->
        <div v-if="sales.data.length" class="overflow-hidden rounded-xl bg-white ring-1 ring-gray-100">
            <div v-for="row in sales.data" :key="row.sale_id" class="border-b border-gray-50 last:border-b-0">
                <button type="button" @click="toggle(row.sale_id)"
                    class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50/70 focus-visible:outline-none focus-visible:bg-gray-50"
                    :class="row.suspicious ? 'bg-amber-50/50' : ''">
                    <svg class="h-4 w-4 shrink-0 text-gray-300 transition-transform" :class="open.has(row.sale_id) ? 'rotate-90' : ''"
                        fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>

                    <div class="w-24 shrink-0">
                        <p class="font-mono text-sm font-bold text-gray-900">{{ row.folio || '—' }}</p>
                        <p class="text-[11px] text-gray-400">{{ day(row.last_at) }} · {{ time(row.last_at) }}</p>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-gray-700">
                                {{ row.movement_count }} {{ row.movement_count === 1 ? 'cambio' : 'cambios' }}
                            </span>
                            <span v-if="row.suspicious"
                                class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700 ring-1 ring-inset ring-red-600/20">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-2.994-1.5-3.86 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                Precio y pago en la misma hora
                            </span>
                            <span v-if="row.customer_name" class="text-xs text-gray-400">· {{ row.customer_name }}</span>
                            <span v-if="scope === 'empresa' && row.branch_name" class="text-xs text-gray-400">· {{ row.branch_name }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full" :class="row.net_effect < 0 ? 'bg-red-500' : 'bg-gray-300'"
                                :style="{ width: barWidth(row) }" />
                        </div>
                    </div>

                    <div class="w-24 shrink-0 text-right">
                        <p class="font-mono text-sm font-bold tabular-nums"
                            :class="row.net_effect < 0 ? 'text-red-600' : row.net_effect > 0 ? 'text-emerald-600' : 'text-gray-300'">
                            {{ money(row.net_effect) }}
                        </p>
                        <p v-if="row.sale_total !== null" class="text-[11px] text-gray-400">de {{ plainMoney(row.sale_total) }}</p>
                    </div>

                    <div class="hidden w-32 shrink-0 text-right sm:block">
                        <p class="text-[11px] font-semibold text-gray-500">{{ row.devices.join(', ') || '—' }}</p>
                    </div>
                </button>

                <!-- Detalle de los cambios -->
                <div v-if="open.has(row.sale_id)" class="border-t border-gray-100 bg-gray-50/60 px-5 py-2">
                    <div v-for="m in row.movements" :key="m.id"
                        class="flex flex-wrap items-center gap-3 border-b border-gray-100 py-2.5 last:border-b-0">
                        <span class="w-12 shrink-0 font-mono text-xs tabular-nums text-gray-400">{{ time(m.created_at) }}</span>
                        <span :class="[eventTone(m.event), 'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset']">
                            {{ m.event_label }}
                        </span>
                        <span class="min-w-0 flex-1 text-sm text-gray-700">
                            <span class="font-semibold">{{ describe(m).text }}</span>
                            <span v-if="describe(m).detail" class="text-gray-500"> — {{ describe(m).detail }}</span>
                        </span>
                        <span class="shrink-0 font-mono text-xs font-bold tabular-nums"
                            :class="m.amount_effect === null ? 'text-gray-300' : m.amount_effect < 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ m.amount_effect === null ? 'sin efecto' : money(m.amount_effect) }}
                        </span>
                        <span class="w-40 shrink-0 text-right text-[11px] text-gray-400">
                            {{ m.user_name || 'sistema' }}<span v-if="m.device"> · {{ m.device }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl bg-white px-6 py-16 text-center ring-1 ring-gray-100">
            <p class="text-sm font-semibold text-gray-500">Ninguna venta se modificó en estas fechas.</p>
            <p class="mt-1 text-xs text-gray-400">Aparecen aquí los cambios de producto, los pagos editados o borrados, las cancelaciones y los cambios de cliente.</p>
        </div>

        <!-- Paginación -->
        <div v-if="sales.links && sales.last_page > 1" class="flex flex-wrap justify-center gap-1">
            <component v-for="link in sales.links" :key="link.label"
                :is="link.url ? 'a' : 'span'" :href="link.url || undefined"
                class="rounded-lg px-3 py-1.5 text-sm transition"
                :class="link.active ? 'bg-red-600 font-bold text-white' : link.url ? 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>
    </div>
</template>
