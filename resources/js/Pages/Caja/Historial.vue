<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({ sales: Object, filters: Object, tenant: Object });

const date = ref(props.filters?.date || '');

const methodMeta = {
    cash:     { label: 'Efectivo',      color: 'text-emerald-600', iconBg: 'bg-emerald-100 text-emerald-600',
                icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    card:     { label: 'Tarjeta',       color: 'text-blue-600',    iconBg: 'bg-blue-100 text-blue-600',
                icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    transfer: { label: 'Transferencia', color: 'text-violet-600',  iconBg: 'bg-violet-100 text-violet-600',
                icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
};
const statusBadge = (s) => ({
    active:    { l: 'Activa',    c: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending:   { l: 'Pendiente', c: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { l: 'Cobrada',   c: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { l: 'Cancelada', c: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { l: s, c: 'bg-gray-100 text-gray-600' });

// --- Accumulated list ---
const allSales = ref([...props.sales.data]);
const nextCursor = ref(props.sales.next_cursor || null);
const loadingMore = ref(false);
const hasMore = computed(() => nextCursor.value !== null);

watch(() => props.sales, (newSales) => {
    if (loadingMore.value) return;
    allSales.value = [...newSales.data];
    nextCursor.value = newSales.next_cursor || null;
    if (selected.value && !allSales.value.find(s => s.id === selected.value.id)) {
        selected.value = null;
    }
});

watch(date, (v) => {
    selected.value = null;
    router.get(route('caja.historial', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});

// --- Infinite scroll ---
const loadMore = () => {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    router.get(route('caja.historial', props.tenant.slug), {
        cursor: nextCursor.value,
        date: date.value || undefined,
    }, {
        preserveState: true, preserveScroll: true, only: ['sales'],
        onSuccess: () => {
            const newSales = props.sales;
            if (newSales?.data) {
                const existingIds = new Set(allSales.value.map(s => s.id));
                const unique = newSales.data.filter(s => !existingIds.has(s.id));
                allSales.value.push(...unique);
                nextCursor.value = newSales.next_cursor || null;
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

const formatTime = (d) => new Date(d).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true });
const selected = ref(null);

const paidPct = computed(() => {
    if (!selected.value) return 0;
    return selected.value.total > 0 ? Math.min((parseFloat(selected.value.amount_paid) / parseFloat(selected.value.total)) * 100, 100) : 0;
});
</script>

<template>
    <Head title="Historial" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mis Ventas Cobradas</h1></template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4">
                    <DatePicker v-model="date" />
                </div>
                <div ref="listRef" @scroll="onScroll" class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="sale in allSales" :key="sale.id" @click="selected = sale"
                        :class="['cursor-pointer rounded-xl p-4 transition-all', selected?.id === sale.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ sale.folio }}</span>
                            <span :class="[statusBadge(sale.status).c, 'rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(sale.status).l }}</span>
                        </div>
                        <div class="mt-2 flex items-end justify-between">
                            <p class="text-lg font-bold text-gray-900">${{ parseFloat(sale.total).toFixed(2) }}</p>
                            <span class="text-xs text-gray-400">{{ formatTime(sale.created_at) }}</span>
                        </div>
                    </div>

                    <div v-if="loadingMore" class="flex justify-center py-4">
                        <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" /></svg>
                    </div>
                    <p v-if="!hasMore && allSales.length > 0" class="py-3 text-center text-xs text-gray-300">No hay mas ventas.</p>
                    <div v-if="allSales.length === 0 && !loadingMore" class="py-16 text-center text-sm text-gray-400">Sin ventas.</div>
                </div>
            </div>

            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center"><p class="text-sm text-gray-400">Selecciona una venta</p></div>
                <template v-else>
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                            <span :class="[statusBadge(selected.status).c, 'rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(selected.status).l }}</span>
                        </div>
                        <p class="text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX') }}</p>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6 space-y-5">
                        <!-- Items -->
                        <div class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                            <table class="min-w-full divide-y divide-gray-50">
                                <thead><tr class="bg-gray-50">
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Cant.</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="item in selected.items" :key="item.id">
                                        <td class="px-4 py-2.5 text-sm text-gray-900">{{ item.product_name }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-600">{{ parseFloat(item.quantity) }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">${{ parseFloat(item.subtotal).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary -->
                        <div class="rounded-xl ring-1 ring-gray-200/50 overflow-hidden">
                            <div class="px-5 py-4">
                                <div class="grid grid-cols-3 gap-4 mb-4">
                                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Total</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-gray-900">${{ parseFloat(selected.total).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-emerald-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-500">Pagado</p>
                                        <p class="font-mono text-lg font-bold tabular-nums text-emerald-600">${{ parseFloat(selected.amount_paid).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg px-3 py-2.5 text-center" :class="parseFloat(selected.amount_pending) > 0 ? 'bg-amber-50' : 'bg-gray-50'">
                                        <p class="text-[10px] font-medium uppercase tracking-wider" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-500' : 'text-gray-400'">Pendiente</p>
                                        <p class="font-mono text-lg font-bold tabular-nums" :class="parseFloat(selected.amount_pending) > 0 ? 'text-amber-600' : 'text-gray-300'">${{ parseFloat(selected.amount_pending).toFixed(2) }}</p>
                                    </div>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div class="h-full rounded-full transition-all duration-500" :class="paidPct >= 100 ? 'bg-emerald-500' : 'bg-amber-500'" :style="{ width: Math.max(paidPct, 2) + '%' }" />
                                </div>
                            </div>
                        </div>

                        <!-- Payments -->
                        <div v-if="selected.payments?.length">
                            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-gray-400">
                                Pagos
                                <span class="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-500">{{ selected.payments.length }}</span>
                            </h3>
                            <div class="space-y-1.5">
                                <div v-for="p in selected.payments" :key="p.id" class="flex items-center gap-3 rounded-lg bg-gray-50 px-4 py-3">
                                    <div :class="[methodMeta[p.method]?.iconBg, 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg']">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="methodMeta[p.method]?.icon" /></svg>
                                    </div>
                                    <div class="flex-1 flex items-center justify-between">
                                        <span :class="[methodMeta[p.method]?.color, 'text-sm font-semibold']">{{ methodMeta[p.method]?.label }}</span>
                                        <span class="font-mono text-sm font-bold tabular-nums text-gray-900">${{ parseFloat(p.amount).toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </CajeroLayout>
</template>
