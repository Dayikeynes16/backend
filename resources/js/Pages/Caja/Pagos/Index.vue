<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    payments: Object, totals: Object,
    filters: Object, tenant: Object,
});

const methodMeta = {
    cash:     { label: 'Efectivo',       color: 'text-emerald-600', bg: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', iconBg: 'bg-emerald-100 text-emerald-600',
                icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    card:     { label: 'Tarjeta',        color: 'text-blue-600',    bg: 'bg-blue-50 text-blue-700 ring-blue-600/20',         iconBg: 'bg-blue-100 text-blue-600',
                icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    transfer: { label: 'Transferencia',  color: 'text-violet-600',  bg: 'bg-violet-50 text-violet-700 ring-violet-600/20',   iconBg: 'bg-violet-100 text-violet-600',
                icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
};
const statusBadge = (s) => ({
    active:    { label: 'Activa',    cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending:   { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada',   cls: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });

// --- Filters ---
const method = ref(props.filters?.method || '');
const date = ref(props.filters?.date || '');

// --- Accumulated payments list ---
const allPayments = ref([...props.payments.data]);
const nextCursor = ref(props.payments.next_cursor || null);
const loadingMore = ref(false);
const hasMore = computed(() => nextCursor.value !== null);

watch(() => props.payments, (newPayments) => {
    if (loadingMore.value) return;
    allPayments.value = [...newPayments.data];
    nextCursor.value = newPayments.next_cursor || null;
    if (selectedId.value && !allPayments.value.find(p => p.id === selectedId.value)) {
        selectedId.value = null;
        selected.value = null;
    }
});

// --- Filter application ---
let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        selectedId.value = null;
        selected.value = null;
        router.get(route('caja.pagos', props.tenant.slug), {
            method: method.value || undefined,
            date: date.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};

watch(method, () => { clearTimeout(debounceTimer); applyFilters(); });
watch(date, () => { clearTimeout(debounceTimer); applyFilters(); });

// --- Infinite scroll ---
const loadMore = () => {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    router.get(route('caja.pagos', props.tenant.slug), {
        cursor: nextCursor.value,
        method: method.value || undefined,
        date: date.value || undefined,
    }, {
        preserveState: true, preserveScroll: true, only: ['payments'],
        onSuccess: () => {
            const newPayments = props.payments;
            if (newPayments?.data) {
                const existingIds = new Set(allPayments.value.map(p => p.id));
                const unique = newPayments.data.filter(p => !existingIds.has(p.id));
                allPayments.value.push(...unique);
                nextCursor.value = newPayments.next_cursor || null;
            }
            loadingMore.value = false;
        },
        onError: () => { loadingMore.value = false; },
    });
};

const listRef = ref(null);
const onScroll = () => {
    const el = listRef.value;
    if (!el || loadingMore.value || !hasMore.value) return;
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 100) loadMore();
};

// --- Formatters ---
const formatTime = (d) => new Date(d).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
const formatFullDate = (d) => {
    const dt = new Date(d);
    const day = dt.toLocaleDateString('es-MX', { weekday: 'long' });
    const rest = dt.toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' });
    const time = dt.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
    return `${day.charAt(0).toUpperCase() + day.slice(1)} ${rest}, ${time}`;
};

// --- Selection ---
const selectedId = ref(null);
const selected = ref(null);
const selectPayment = (payment) => { selectedId.value = payment.id; selected.value = payment; };

// --- Computed for sale payments context ---
const salePayments = computed(() => selected.value?.sale?.payments || []);
const paidPct = computed(() => {
    if (!selected.value?.sale) return 0;
    const s = selected.value.sale;
    return s.total > 0 ? Math.min((parseFloat(s.amount_paid) / parseFloat(s.total)) * 100, 100) : 0;
});
</script>

<template>
    <Head title="Mis Pagos" />
    <CajeroLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Mis Pagos</h1>
        </template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <!-- LEFT PANEL -->
            <div class="flex w-[440px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">

                <!-- KPI Hero -->
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Total cobrado</p>
                        <DatePicker v-model="date" />
                    </div>
                    <p class="font-mono text-3xl font-extrabold tabular-nums text-gray-900">${{ parseFloat(totals?.total || 0).toFixed(2) }}</p>

                    <!-- Method breakdown cards -->
                    <div class="mt-4 grid grid-cols-3 gap-2">
                        <div v-for="m in ['cash', 'card', 'transfer']" :key="m"
                            :class="['rounded-lg px-3 py-2.5', m === 'cash' ? 'bg-emerald-50' : m === 'card' ? 'bg-blue-50' : 'bg-violet-50']">
                            <div class="flex items-center gap-1.5 mb-1">
                                <svg :class="['h-3.5 w-3.5', methodMeta[m].color]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[m].icon" /></svg>
                                <span :class="['text-[10px] font-medium', methodMeta[m].color]">{{ methodMeta[m].label }}</span>
                            </div>
                            <p :class="['font-mono text-sm font-bold tabular-nums', methodMeta[m].color]">${{ parseFloat(totals?.[m] || 0).toFixed(2) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="border-b border-gray-100 px-5 py-3">
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'',l:'Todos'},{v:'cash',l:'Efectivo'},{v:'card',l:'Tarjeta'},{v:'transfer',l:'Transfer.'}]"
                            :key="f.v" @click="method = f.v"
                            :class="['flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                method === f.v ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            <svg v-if="f.v" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[f.v]?.icon" /></svg>
                            {{ f.l }}
                        </button>
                    </div>
                </div>

                <!-- Payments list -->
                <div ref="listRef" @scroll="onScroll" class="flex-1 overflow-y-auto p-3 space-y-1.5">
                    <div v-for="payment in allPayments" :key="payment.id" @click="selectPayment(payment)"
                        :class="['group cursor-pointer rounded-xl px-4 py-3.5 transition-all',
                            selectedId === payment.id ? 'ring-2 ring-red-500 bg-red-50/50' : 'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center gap-3">
                            <!-- Method icon -->
                            <div :class="[methodMeta[payment.method]?.iconBg, 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg']">
                                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[payment.method]?.icon" /></svg>
                            </div>
                            <!-- Info -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-gray-900">{{ payment.sale?.folio }}</span>
                                        <span v-if="payment.updated_by" class="rounded-full bg-orange-50 px-1.5 py-0.5 text-[10px] font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20">Editado</span>
                                    </div>
                                    <span class="font-mono text-sm font-bold tabular-nums text-gray-900">${{ parseFloat(payment.amount).toFixed(2) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between">
                                    <span class="text-xs text-gray-400 truncate">{{ payment.user?.name }}</span>
                                    <span class="text-xs text-gray-400">{{ formatTime(payment.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="loadingMore" class="flex justify-center py-4">
                        <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" /></svg>
                    </div>
                    <p v-if="!hasMore && allPayments.length > 0" class="py-3 text-center text-xs text-gray-300">No hay mas pagos.</p>
                    <div v-if="allPayments.length === 0 && !loadingMore" class="flex flex-col items-center py-16 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100">
                            <svg class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        </div>
                        <p class="mt-3 text-sm font-medium text-gray-400">No se encontraron pagos</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT PANEL: Detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <!-- Empty state -->
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-50">
                            <svg class="h-8 w-8 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        </div>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona un pago para ver el detalle</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Detail header -->
                    <div class="border-b border-gray-100 px-6 py-5">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div :class="[methodMeta[selected.method]?.iconBg, 'flex h-10 w-10 items-center justify-center rounded-xl']">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[selected.method]?.icon" /></svg>
                                </div>
                                <div>
                                    <h2 class="text-lg font-bold text-gray-900">{{ selected.sale?.folio }}</h2>
                                    <p class="text-xs text-gray-400">{{ formatFullDate(selected.created_at) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="[methodMeta[selected.method]?.bg, 'rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset']">{{ methodMeta[selected.method]?.label }}</span>
                                <span v-if="selected.updated_by" class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 ring-1 ring-inset ring-orange-500/20">Editado</span>
                            </div>
                        </div>
                    </div>

                    <!-- Scrollable content -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-5">

                        <!-- Section A: Pago seleccionado -->
                        <div class="rounded-xl bg-gradient-to-br from-gray-50 to-gray-100/50 p-5 ring-1 ring-gray-200/50">
                            <h3 class="mb-4 text-xs font-bold uppercase tracking-wider text-gray-400">Detalle del pago</h3>
                            <div class="flex items-start justify-between">
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-xs text-gray-400">Monto cobrado</p>
                                        <p class="font-mono text-2xl font-extrabold tabular-nums text-gray-900">${{ parseFloat(selected.amount).toFixed(2) }}</p>
                                    </div>
                                    <div class="flex gap-6">
                                        <div>
                                            <p class="text-xs text-gray-400">Metodo</p>
                                            <div class="mt-0.5 flex items-center gap-1.5">
                                                <svg :class="[methodMeta[selected.method]?.color, 'h-4 w-4']" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[selected.method]?.icon" /></svg>
                                                <span :class="[methodMeta[selected.method]?.color, 'text-sm font-semibold']">{{ methodMeta[selected.method]?.label }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-400">Cobrado por</p>
                                            <p class="mt-0.5 text-sm font-semibold text-gray-900">{{ selected.user?.name }}</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Edited info -->
                                <div v-if="selected.updated_by_user" class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-right">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-orange-500">Editado</p>
                                    <p class="text-xs font-semibold text-orange-700">{{ selected.updated_by_user?.name }}</p>
                                    <p class="text-[10px] text-orange-500">{{ formatFullDate(selected.updated_at) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Section B: Venta asociada -->
                        <div v-if="selected.sale" class="rounded-xl ring-1 ring-gray-200/50 overflow-hidden">
                            <div class="flex items-center justify-between bg-gray-50 px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Venta</h3>
                                    <span class="text-sm font-bold text-gray-900">{{ selected.sale.folio }}</span>
                                    <span :class="[statusBadge(selected.sale.status).cls, 'rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset']">{{ statusBadge(selected.sale.status).label }}</span>
                                </div>
                            </div>
                            <div class="px-5 py-4">
                                <div class="grid grid-cols-3 gap-4 mb-4">
                                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Total</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-gray-900">${{ parseFloat(selected.sale.total).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-emerald-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-500">Pagado</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-emerald-600">${{ parseFloat(selected.sale.amount_paid).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg px-3 py-2.5 text-center" :class="parseFloat(selected.sale.amount_pending) > 0 ? 'bg-amber-50' : 'bg-gray-50'">
                                        <p class="text-[10px] font-medium uppercase tracking-wider" :class="parseFloat(selected.sale.amount_pending) > 0 ? 'text-amber-500' : 'text-gray-400'">Pendiente</p>
                                        <p class="font-mono text-lg font-bold tabular-nums" :class="parseFloat(selected.sale.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.sale.amount_pending).toFixed(2) }}</p>
                                    </div>
                                </div>
                                <!-- Progress bar -->
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div class="h-full rounded-full transition-all duration-500" :class="paidPct >= 100 ? 'bg-emerald-500' : 'bg-amber-500'" :style="{ width: Math.max(paidPct, 2) + '%' }" />
                                </div>
                            </div>
                        </div>

                        <!-- Section C: Timeline de pagos de la venta -->
                        <div v-if="salePayments.length > 0">
                            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">
                                Pagos de esta venta
                                <span class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-500">{{ salePayments.length }}</span>
                            </h3>
                            <div class="space-y-0">
                                <div v-for="(p, idx) in salePayments" :key="p.id"
                                    :class="['relative flex items-start gap-3 py-3 px-4 rounded-lg transition-colors',
                                        p.id === selected.id ? 'bg-red-50/60 ring-1 ring-red-200' : 'hover:bg-gray-50']">
                                    <!-- Timeline connector -->
                                    <div class="flex flex-col items-center">
                                        <div :class="[p.id === selected.id ? 'bg-red-500 ring-2 ring-red-200' : methodMeta[p.method]?.iconBg,
                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full']">
                                            <svg :class="[p.id === selected.id ? 'text-white' : '', 'h-3.5 w-3.5']" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[p.method]?.icon" /></svg>
                                        </div>
                                        <div v-if="idx < salePayments.length - 1" class="mt-1 h-full w-px bg-gray-200" />
                                    </div>
                                    <!-- Content -->
                                    <div class="flex-1 min-w-0 pt-0.5">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm font-bold tabular-nums text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                                <span :class="[methodMeta[p.method]?.color, 'text-xs font-medium']">{{ methodMeta[p.method]?.label }}</span>
                                                <span v-if="p.id === selected.id" class="rounded-full bg-red-100 px-1.5 py-0.5 text-[9px] font-bold text-red-600">ACTUAL</span>
                                            </div>
                                            <span class="text-xs text-gray-400">{{ formatTime(p.created_at) }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ p.user?.name }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <FlashToast />
    </CajeroLayout>
</template>
