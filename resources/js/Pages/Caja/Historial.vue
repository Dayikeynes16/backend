<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import DatePicker from '@/Components/DatePicker.vue';
import TicketPrinter from '@/Components/TicketPrinter.vue';
import WhatsappPhoneDialog from '@/Components/WhatsappPhoneDialog.vue';
import WhatsappSendConfirmDialog from '@/Components/WhatsappSendConfirmDialog.vue';
import SaleWhatsappPhoneChip from '@/Components/SaleWhatsappPhoneChip.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { useWhatsappSend } from '@/composables/useWhatsappSend';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({ sales: Object, filters: Object, tenant: Object, branchInfo: Object });

const date = ref(props.filters?.date || '');
const product = ref(props.filters?.product || '');
const minTotal = ref(props.filters?.min_total ?? '');
const maxTotal = ref(props.filters?.max_total ?? '');

const queryParams = () => ({
    date: date.value || undefined,
    product: product.value.trim() || undefined,
    min_total: minTotal.value !== '' && minTotal.value !== null ? minTotal.value : undefined,
    max_total: maxTotal.value !== '' && maxTotal.value !== null ? maxTotal.value : undefined,
});

const hasActiveFilters = computed(() =>
    !!product.value.trim() || minTotal.value !== '' || maxTotal.value !== '');

const clearFilters = () => {
    product.value = '';
    minTotal.value = '';
    maxTotal.value = '';
};

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
    if (selected.value) {
        // Mantener referencia fresca tras un reload (p.ej. al guardar/borrar
        // teléfono): si el venta sigue en la lista, apuntar al objeto nuevo.
        const updated = allSales.value.find(s => s.id === selected.value.id);
        selected.value = updated ?? null;
    }
});

const applyFilters = () => {
    selected.value = null;
    router.get(route('caja.historial', props.tenant.slug), queryParams(), { preserveState: true, replace: true });
};

watch(date, applyFilters);

let filterTimer = null;
watch([product, minTotal, maxTotal], () => {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(applyFilters, 350);
});

// --- Infinite scroll ---
const loadMore = () => {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    router.get(route('caja.historial', props.tenant.slug), {
        cursor: nextCursor.value,
        ...queryParams(),
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
const showTicket = ref(false);

const paidPct = computed(() => {
    if (!selected.value) return 0;
    return selected.value.total > 0 ? Math.min((parseFloat(selected.value.amount_paid) / parseFloat(selected.value.total)) * 100, 100) : 0;
});

// --- WhatsApp ---
const reloadCurrent = () => router.reload({
    only: ['sales'],
    data: queryParams(),
    preserveScroll: true,
});

const {
    loading: whatsappLoading,
    savingPhone: whatsappSavingPhone,
    removingPhone: whatsappRemovingPhone,
    error: whatsappError,
    phoneInfo: whatsappPhoneInfo,
    confirmDialog: whatsappConfirmDialog,
    captureDialog: whatsappCaptureDialog,
    removeDialog: whatsappRemoveDialog,
    handleSendClick: clickWhatsappSend,
    confirmSend: confirmWhatsappSend,
    switchToEditFromConfirm: editFromWhatsappConfirm,
    handleChipEdit: chipEditPhone,
    handleChipAdd: chipAddPhone,
    handleChipRemove: chipRemovePhone,
    submitPhone: submitWhatsappPhone,
    confirmRemove: confirmRemovePhone,
    closeAll: closeWhatsappDialogs,
} = useWhatsappSend({
    sale: () => selected.value,
    linkUrl: () => route('caja.whatsapp-link', [props.tenant.slug, selected.value.id]),
    savePhoneUrl: () => route('caja.whatsapp-phone', [props.tenant.slug, selected.value.id]),
    deletePhoneUrl: () => route('caja.whatsapp-phone.destroy', [props.tenant.slug, selected.value.id]),
    onMutate: reloadCurrent,
});
</script>

<template>
    <Head title="Historial" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mis Ventas Cobradas</h1></template>

        <div class="flex h-[calc(100vh-7rem)] gap-5">
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4 space-y-3">
                    <DatePicker v-model="date" />

                    <!-- Buscar por producto -->
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="product" type="text" placeholder="Buscar por producto..."
                            class="w-full rounded-xl border-0 bg-gray-50 py-2.5 pl-9 pr-9 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-red-500" />
                        <button v-if="product" type="button" @click="product = ''"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-300 transition hover:text-gray-500">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Rango de precio (total de la venta) -->
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <input v-model="minTotal" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Mín"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 pl-7 pr-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-red-500" />
                        </div>
                        <span class="text-gray-300">—</span>
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                            <input v-model="maxTotal" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Máx"
                                class="w-full rounded-xl border-0 bg-gray-50 py-2.5 pl-7 pr-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-200 placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-red-500" />
                        </div>
                    </div>

                    <button v-if="hasActiveFilters" type="button" @click="clearFilters"
                        class="flex items-center gap-1 text-xs font-medium text-gray-400 transition hover:text-gray-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        Limpiar filtros
                    </button>
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
                    <div v-if="allSales.length === 0 && !loadingMore" class="py-16 text-center text-sm text-gray-400">{{ hasActiveFilters ? 'Sin resultados para los filtros.' : 'Sin ventas.' }}</div>
                </div>
            </div>

            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center"><p class="text-sm text-gray-400">Selecciona una venta</p></div>
                <template v-else>
                    <div class="border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-bold text-gray-900">{{ selected.folio }}</h2>
                                <span :class="[statusBadge(selected.status).c, 'rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset']">{{ statusBadge(selected.status).l }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="clickWhatsappSend" :disabled="whatsappLoading"
                                    title="Enviar nota por WhatsApp"
                                    class="flex items-center gap-1.5 rounded-lg bg-[#25D366]/10 px-3 py-1.5 text-xs font-bold text-[#128C7E] transition hover:bg-[#25D366]/20 disabled:cursor-wait disabled:opacity-60">
                                    <svg v-if="!whatsappLoading" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                                    </svg>
                                    <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z" />
                                    </svg>
                                    WhatsApp
                                </button>
                                <button @click="showTicket = true" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                                    Ticket
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400">{{ new Date(selected.created_at).toLocaleString('es-MX') }}</p>

                        <!-- Phone chip: siempre visible -->
                        <div class="mt-3 flex items-center gap-2">
                            <SaleWhatsappPhoneChip
                                :phone="whatsappPhoneInfo.phone"
                                :source="whatsappPhoneInfo.source"
                                :customer-name="whatsappPhoneInfo.customerName"
                                @edit="chipEditPhone"
                                @remove="chipRemovePhone"
                                @add="chipAddPhone" />
                        </div>

                        <p v-if="whatsappError && !whatsappConfirmDialog.show && !whatsappCaptureDialog.show && !whatsappRemoveDialog.show"
                            class="mt-2 flex items-center gap-1.5 text-xs font-medium text-red-700">
                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                            {{ whatsappError }}
                        </p>
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
        <TicketPrinter v-if="showTicket && selected" :sale="selected" :business-name="tenant.name"
            :branch-name="branchInfo?.name" :branch-address="branchInfo?.address" :branch-phone="branchInfo?.phone"
            :ticket-config="branchInfo?.ticket_config" @close="showTicket = false" />
        <WhatsappSendConfirmDialog
            :show="whatsappConfirmDialog.show"
            :sending="whatsappLoading"
            :phone="whatsappPhoneInfo.phone"
            :source="whatsappPhoneInfo.source"
            :customer-name="whatsappPhoneInfo.customerName"
            :server-error="whatsappError"
            @send="confirmWhatsappSend"
            @edit="editFromWhatsappConfirm"
            @close="closeWhatsappDialogs" />
        <WhatsappPhoneDialog
            :show="whatsappCaptureDialog.show"
            :saving="whatsappSavingPhone"
            :server-error="whatsappError"
            :initial-phone="whatsappCaptureDialog.initialPhone"
            :title="whatsappCaptureDialog.title"
            :subtitle="whatsappCaptureDialog.subtitle"
            :action-label="whatsappCaptureDialog.actionLabel"
            @submit="submitWhatsappPhone"
            @close="closeWhatsappDialogs" />
        <ConfirmDialog v-if="whatsappRemoveDialog.show"
            title="Quitar teléfono"
            message="Se eliminará el teléfono manual asociado a esta venta. No envía nada a WhatsApp."
            confirm-label="Quitar"
            variant="danger"
            :processing="whatsappRemovingPhone"
            @confirm="confirmRemovePhone"
            @cancel="closeWhatsappDialogs" />
    </CajeroLayout>
</template>
