<script setup>
// Modal de detalle de producto, estilo iOS sheet (mobile) / dialog (desktop).
// Solo lectura + acciones rápidas (toggle status, toggle visible_online).
// Edición compleja sigue viviendo en /productos/{id}/edit (la pantalla full).
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PriceBreakdownSection from '@/Components/Productos/PriceBreakdownSection.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    product: { type: Object, default: null },
    tenant: { type: Object, required: true },
    // Estadísticas del rango (solo cuando se abre desde Métricas).
    // { revenue, gross_profit, margin_pct, quantity_kg, quantity_units, ticket_count, range_label }
    rangeStats: { type: Object, default: null },
    // Ruta nombrada (Ziggy) del endpoint de desglose. Si está presente y
    // hay product+rangeStats, se renderiza la sección "Desglose de ventas".
    breakdownRoute: { type: String, default: null },
    // Query string ya armado con los filtros actuales (preset, statuses…).
    breakdownQuery: { type: String, default: '' },
});

// URL completa del endpoint, construida cuando hay producto + ruta.
const breakdownUrl = computed(() => {
    if (!props.breakdownRoute || !props.product?.id) return null;
    const base = route(props.breakdownRoute, [props.tenant.slug, props.product.id]);
    return props.breakdownQuery ? `${base}?${props.breakdownQuery}` : base;
});

const emit = defineEmits(['close']);

const close = () => emit('close');

// Body lock + Esc handler
const onKeydown = (e) => { if (e.key === 'Escape' && props.show) close(); };
watch(() => props.show, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

// ─── Derivados ────────────────────────────────────────────────────────
const p = computed(() => props.product);
const presentations = computed(() => (p.value?.presentations || []).slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)));
const hasPresentations = computed(() => presentations.value.length > 0);

const margin = computed(() => {
    if (!p.value?.cost_price || !p.value?.price) return null;
    const cost = Number(p.value.cost_price);
    const price = Number(p.value.price);
    if (price <= 0 || cost <= 0) return null;
    const amount = price - cost;
    const pct = (amount / price) * 100;
    return { amount, pct };
});

const saleModeLabel = computed(() => ({
    weight: 'Por peso',
    presentation: 'Por presentación',
    both: 'Peso o presentación',
}[p.value?.sale_mode] || '—'));

const unitTypeLabel = computed(() => ({ kg: 'kg', piece: 'pz', cut: 'corte' }[p.value?.unit_type] || p.value?.unit_type || ''));

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatContent = (content, unit) => {
    const n = Number(content);
    if (unit === 'kg' || unit === 'l') return `${n.toFixed(3)} ${unit}`;
    if (unit === 'g' || unit === 'ml') return `${Math.round(n)} ${unit}`;
    return `${n} ${unit || ''}`.trim();
};

// ─── Toggles rápidos ──────────────────────────────────────────────────
const togglingStatus = ref(false);
const togglingOnline = ref(false);

const toggleStatus = () => {
    if (!p.value) return;
    togglingStatus.value = true;
    const next = p.value.status === 'active' ? 'inactive' : 'active';
    router.patch(route('sucursal.productos.quick', [props.tenant.slug, p.value.id]), { status: next }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { togglingStatus.value = false; },
    });
};

const toggleOnline = () => {
    if (!p.value) return;
    togglingOnline.value = true;
    router.patch(route('sucursal.productos.quick', [props.tenant.slug, p.value.id]), { visible_online: !p.value.visible_online }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { togglingOnline.value = false; },
    });
};

// ─── Eliminar (con confirm) ───────────────────────────────────────────
const confirmingDelete = ref(false);
const deleteProduct = () => {
    if (!p.value) return;
    router.delete(route('sucursal.productos.destroy', [props.tenant.slug, p.value.id]), {
        preserveScroll: true,
        onSuccess: () => { confirmingDelete.value = false; close(); },
        onError: () => { confirmingDelete.value = false; },
    });
};
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            leave-active-class="transition-opacity duration-150 ease-in"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-[100] bg-black/40 backdrop-blur-md" @click="close" />
        </Transition>

        <!-- Modal / Sheet container -->
        <Transition
            enter-active-class="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            leave-active-class="transition duration-200 ease-[cubic-bezier(0.4,0,1,1)]"
            enter-from-class="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95"
            enter-to-class="opacity-100 translate-y-0 sm:scale-100"
            leave-from-class="opacity-100 translate-y-0 sm:scale-100"
            leave-to-class="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95">
            <div v-if="show && p"
                class="fixed inset-x-0 bottom-0 z-[110] flex justify-center sm:inset-0 sm:items-center sm:p-4"
                @click.self="close">
                <div
                    class="pointer-events-auto flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white/95 shadow-2xl ring-1 ring-black/5 backdrop-blur-xl sm:max-h-[88vh] sm:max-w-2xl sm:rounded-3xl"
                    @click.stop>

                    <!-- Drag handle (mobile sheet) -->
                    <div class="flex justify-center pt-2.5 pb-1 sm:hidden">
                        <div class="h-1.5 w-10 rounded-full bg-gray-300" />
                    </div>

                    <!-- Header con close button (desktop) -->
                    <div class="hidden items-center justify-between px-6 pt-5 sm:flex">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400">Detalle del producto</p>
                        <button type="button" @click="close"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                            aria-label="Cerrar">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Scroll content -->
                    <div class="flex-1 overflow-y-auto overscroll-contain">
                        <div class="px-6 pb-6 pt-2 sm:pt-3">
                            <!-- Hero image -->
                            <div class="aspect-[16/10] w-full overflow-hidden rounded-2xl bg-gradient-to-br from-gray-100 to-gray-50 ring-1 ring-gray-200/50">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center">
                                    <svg class="h-16 w-16 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 0 0 2.25-2.25V5.25a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                                </div>
                            </div>

                            <!-- Title + price -->
                            <div class="mt-5 flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <h2 class="text-2xl font-bold tracking-tight text-gray-900">{{ p.name }}</h2>
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <span v-if="p.category" class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 ring-1 ring-inset ring-orange-600/15">
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5.5 16a3.5 3.5 0 0 1-1.4-6.7L13 4.7a3.5 3.5 0 1 1 4 4l-4.6 4.6A3.5 3.5 0 0 1 5.5 16Z" clip-rule="evenodd" /></svg>
                                            {{ p.category.name }}
                                        </span>
                                        <span v-else class="text-xs text-gray-400">Sin categoría</span>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="font-mono text-2xl font-extrabold tabular-nums text-gray-900">{{ money(p.price) }}</p>
                                    <p v-if="unitTypeLabel" class="mt-0.5 text-xs font-medium text-gray-400">/{{ unitTypeLabel }}</p>
                                </div>
                            </div>

                            <!-- En este rango (solo cuando se abre desde Métricas) -->
                            <div v-if="rangeStats" class="mt-5 border-t border-gray-100 pt-5">
                                <div class="flex items-center justify-between">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">En este rango</p>
                                    <p v-if="rangeStats.range_label" class="text-[10px] font-medium text-gray-400">{{ rangeStats.range_label }}</p>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    <div class="rounded-xl bg-blue-50/60 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-blue-700/70">Ingreso</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-blue-700">${{ Number(rangeStats.revenue ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</p>
                                    </div>
                                    <div class="rounded-xl bg-emerald-50/60 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-700/70">Ganancia</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-emerald-700">${{ Number(rangeStats.gross_profit ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</p>
                                        <p v-if="rangeStats.margin_pct != null" class="text-[10px] text-emerald-700/60">{{ Number(rangeStats.margin_pct).toFixed(1) }}% margen</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-500">Tickets</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ Number(rangeStats.ticket_count ?? 0).toLocaleString('es-MX') }}</p>
                                    </div>
                                    <div v-if="Number(rangeStats.quantity_kg) > 0" class="rounded-xl bg-amber-50/60 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-amber-700/70">Vendido (peso)</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-amber-800">{{ Number(rangeStats.quantity_kg).toFixed(3) }} kg</p>
                                    </div>
                                    <div v-if="Number(rangeStats.quantity_units) > 0" class="rounded-xl bg-violet-50/60 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-violet-700/70">Vendido (unid.)</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-violet-800">× {{ Number(rangeStats.quantity_units).toLocaleString('es-MX') }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Desglose de ventas (solo cuando se abre desde Métricas) -->
                            <div v-if="rangeStats && breakdownUrl" class="mt-5">
                                <PriceBreakdownSection
                                    :key="breakdownUrl"
                                    :fetch-url="breakdownUrl"
                                    :range-label="rangeStats.range_label || ''" />
                            </div>

                            <!-- Description -->
                            <div v-if="p.description" class="mt-5 border-t border-gray-100 pt-5">
                                <p class="text-sm leading-relaxed text-gray-700">{{ p.description }}</p>
                            </div>

                            <!-- Sale mode -->
                            <div class="mt-5 border-t border-gray-100 pt-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Modo de venta</p>
                                <div class="mt-2 flex items-center gap-2.5">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                                        <svg v-if="p.sale_mode === 'weight'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                                        <svg v-else-if="p.sale_mode === 'presentation'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>
                                        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3" /></svg>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-900">{{ saleModeLabel }}</p>
                                </div>
                            </div>

                            <!-- Presentations -->
                            <div v-if="hasPresentations" class="mt-5 border-t border-gray-100 pt-5">
                                <div class="flex items-baseline justify-between">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Presentaciones</p>
                                    <p class="text-xs font-medium text-gray-400">{{ presentations.length }}</p>
                                </div>
                                <div class="mt-2 overflow-hidden rounded-2xl ring-1 ring-gray-100">
                                    <div v-for="(pres, idx) in presentations" :key="pres.id"
                                        :class="['flex items-center justify-between px-4 py-3', idx % 2 === 0 ? 'bg-gray-50/50' : 'bg-white']">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ pres.name }}</p>
                                            <p class="text-xs text-gray-500">{{ formatContent(pres.content, pres.unit) }}</p>
                                        </div>
                                        <p class="ml-4 shrink-0 font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(pres.price) }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Costo y margen -->
                            <div v-if="p.cost_price" class="mt-5 border-t border-gray-100 pt-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Costo y margen</p>
                                <div class="mt-2 grid grid-cols-3 gap-2">
                                    <div class="rounded-xl bg-gray-50 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Costo</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(p.cost_price) }}</p>
                                    </div>
                                    <div v-if="margin" class="rounded-xl bg-emerald-50 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-700/70">Ganancia</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-emerald-700">{{ money(margin.amount) }}</p>
                                    </div>
                                    <div v-if="margin" class="rounded-xl bg-emerald-50 px-3 py-2.5">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-700/70">Margen</p>
                                        <p class="font-mono text-sm font-bold tabular-nums text-emerald-700">{{ margin.pct.toFixed(0) }}%</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Toggles rápidos: estado + visibilidad online -->
                            <div class="mt-5 border-t border-gray-100 pt-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Disponibilidad</p>
                                <div class="mt-2 space-y-2">
                                    <!-- Activo / Inactivo -->
                                    <button type="button" @click="toggleStatus" :disabled="togglingStatus"
                                        class="flex w-full items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-gray-200 transition hover:bg-gray-50 active:bg-gray-100 disabled:cursor-wait disabled:opacity-60">
                                        <div class="flex items-center gap-3">
                                            <div :class="['flex h-9 w-9 items-center justify-center rounded-xl', p.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-400']">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                            </div>
                                            <div class="text-left">
                                                <p class="text-sm font-semibold text-gray-900">Producto activo</p>
                                                <p class="text-xs text-gray-500">{{ p.status === 'active' ? 'Disponible para vender en mesa de trabajo' : 'No aparece para venta nueva' }}</p>
                                            </div>
                                        </div>
                                        <span :class="['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors', p.status === 'active' ? 'bg-emerald-500' : 'bg-gray-300']">
                                            <span :class="['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', p.status === 'active' ? 'translate-x-5' : 'translate-x-0.5']" />
                                        </span>
                                    </button>

                                    <!-- Online -->
                                    <button type="button" @click="toggleOnline" :disabled="togglingOnline"
                                        class="flex w-full items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-gray-200 transition hover:bg-gray-50 active:bg-gray-100 disabled:cursor-wait disabled:opacity-60">
                                        <div class="flex items-center gap-3">
                                            <div :class="['flex h-9 w-9 items-center justify-center rounded-xl', p.visible_online ? 'bg-blue-50 text-blue-600' : 'bg-gray-100 text-gray-400']">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" /></svg>
                                            </div>
                                            <div class="text-left">
                                                <p class="text-sm font-semibold text-gray-900">Pedidos en línea</p>
                                                <p class="text-xs text-gray-500">{{ p.visible_online ? 'Visible en menú online' : 'Solo visible en sucursal' }}</p>
                                            </div>
                                        </div>
                                        <span :class="['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors', p.visible_online ? 'bg-blue-500' : 'bg-gray-300']">
                                            <span :class="['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', p.visible_online ? 'translate-x-5' : 'translate-x-0.5']" />
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- Visibility chip (público/restringido) -->
                            <div class="mt-5 border-t border-gray-100 pt-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Visibilidad</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <span :class="['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset',
                                        p.visibility === 'public'
                                            ? 'bg-green-50 text-green-700 ring-green-600/20'
                                            : 'bg-amber-50 text-amber-700 ring-amber-600/20']">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" /><path fill-rule="evenodd" d="M.664 10.59a1.65 1.65 0 0 1 0-1.18C2.108 5.6 5.7 3 10 3s7.892 2.6 9.336 6.41a1.65 1.65 0 0 1 0 1.18C17.892 14.4 14.3 17 10 17S2.108 14.4.664 10.59ZM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" clip-rule="evenodd" /></svg>
                                        {{ p.visibility === 'public' ? 'Público' : 'Restringido' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer sticky -->
                    <div class="border-t border-gray-100 bg-white/80 px-6 py-4 backdrop-blur-md">
                        <div class="flex items-center justify-between gap-3">
                            <button type="button" @click="confirmingDelete = true"
                                class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-100 transition hover:bg-red-50 active:bg-red-100">
                                Eliminar
                            </button>
                            <Link :href="route('sucursal.productos.edit', [tenant.slug, p.id])"
                                class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:bg-red-800">
                                Editar
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Confirm delete (over modal) -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            leave-active-class="transition duration-150 ease-in"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0">
            <div v-if="confirmingDelete" class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" @click.self="confirmingDelete = false">
                <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl">
                    <h3 class="text-base font-bold text-gray-900">Eliminar producto</h3>
                    <p class="mt-2 text-sm leading-relaxed text-gray-500">¿Seguro que quieres eliminar <span class="font-semibold text-gray-900">{{ p?.name }}</span>? Esta acción no se puede deshacer.</p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" @click="confirmingDelete = false" class="rounded-xl bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200">Cancelar</button>
                        <button type="button" @click="deleteProduct" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700">Eliminar</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
