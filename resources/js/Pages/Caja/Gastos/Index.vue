<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import GastoFormModal from '@/Components/Gastos/GastoFormModal.vue';
import GastoDetailModal from '@/Components/Gastos/GastoDetailModal.vue';
import GastoCapturaIAModal from '@/Components/Gastos/GastoCapturaIAModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    expenses: Object,
    totals: Object,
    dailyTotals: { type: Object, default: () => ({}) },
    currentShift: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    hasOpenShift: { type: Boolean, default: false },
    filters: Object,
    tenant: Object,
});

const page = usePage();
const branchId = computed(() => page.props.auth.branch?.id ?? null);

const hasUsableCategories = computed(() =>
    props.categories.some(c => c.status === 'active' && (c.subcategories || []).some(s => s.status === 'active'))
);
const canRegister = computed(() => props.hasOpenShift && hasUsableCategories.value);
const registerHint = computed(() => {
    if (!props.hasOpenShift) return 'Abre tu turno para registrar un gasto';
    if (!hasUsableCategories.value) return 'El admin de empresa debe crear categorías primero';
    return '';
});

// --- Form modal ---
const formOpen = ref(false);
const editId = ref(null);
const aiProposal = ref(null);
const aiDraftId = ref(null);
const aiAttachments = ref([]);
const aiTranscription = ref(null);

// --- Detail modal ---
const detailOpen = ref(false);
const selected = ref(null);
const paymentMethods = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transferencia' },
];

const openDetail = (e) => { selected.value = e; detailOpen.value = true; };
const onEditGasto = () => {
    detailOpen.value = false;
    resetAi();
    editId.value = selected.value.id;
    formOpen.value = true;
};
const onDeleteGasto = () => {
    if (!selected.value) return;
    const reason = prompt('Motivo de cancelación (opcional):') ?? '';
    router.delete(route('caja.gastos.destroy', { tenant: props.tenant.slug, gasto: selected.value.id }), {
        data: { cancellation_reason: reason },
        preserveScroll: true,
        onSuccess: () => { detailOpen.value = false; },
    });
};

const resetAi = () => {
    aiProposal.value = null;
    aiDraftId.value = null;
    aiAttachments.value = [];
    aiTranscription.value = null;
};

const openCreate = () => {
    resetAi();
    formOpen.value = true;
};

// --- IA capture ---
const iaOpen = ref(false);
const onAiProposal = ({ draftId, proposal, attachments, audioTranscription }) => {
    aiProposal.value = proposal;
    aiDraftId.value = draftId;
    aiAttachments.value = attachments;
    aiTranscription.value = audioTranscription;
    formOpen.value = true;
};

// --- Search ---
const search = ref(props.filters?.search || '');
let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('caja.gastos.index', props.tenant.slug),
            { search: search.value || undefined },
            { preserveState: true, replace: true, preserveScroll: true });
    }, 300);
});

const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtTime = (v) => v ? new Date(v).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '';
const fmtDateTime = (v) => v ? new Date(v).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtDayHeading = (v) => {
    if (!v) return '—';
    const s = new Date(v).toLocaleDateString('es-MX', { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });
    return s.charAt(0).toUpperCase() + s.slice(1);
};

// Día local (consistente con lo que ve el usuario) para agrupar.
const dayKey = (v) => {
    const d = new Date(v);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Agrupa los gastos de la página por día. El total y el conteo del día vienen
// del backend (dailyTotals), por lo que son exactos aunque un día quede partido
// entre varias páginas. Si faltara la clave, cae al cálculo de la página visible.
// Los datos llegan ordenados por expense_at DESC, así que cada día queda contiguo.
const groupedDays = computed(() => {
    const groups = [];
    let current = null;
    for (const e of props.expenses.data) {
        const key = dayKey(e.expense_at);
        if (!current || current.key !== key) {
            const stats = props.dailyTotals?.[key] ?? null;
            current = {
                key,
                date: e.expense_at,
                items: [],
                exact: !!stats,
                total: stats ? stats.total : 0,
                count: stats ? stats.count : 0,
            };
            groups.push(current);
        }
        current.items.push(e);
        if (!current.exact) {
            current.total += Number(e.amount ?? 0);
            current.count = current.items.length;
        }
    }
    return groups;
});
</script>

<template>
    <Head title="Gastos" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mis Gastos</h1></template>

        <div class="mx-auto max-w-3xl space-y-5">
            <!-- Nota: afecta el corte -->
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <p class="text-xs text-amber-800">
                    Los gastos en efectivo <strong>salen del cajón</strong> y se descuentan del efectivo esperado en tu <strong>corte de turno</strong>. Solo ves los gastos que tú registraste.
                </p>
            </div>

            <!-- Gastos del turno abierto (lo que se descuenta del cajón en el corte) -->
            <div v-if="currentShift"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-red-100 bg-gradient-to-br from-red-50 to-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-red-500">Gastos de tu turno</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Abierto {{ fmtDateTime(currentShift.opened_at) }} · {{ currentShift.count }} {{ currentShift.count === 1 ? 'gasto' : 'gastos' }}
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold tabular-nums text-red-600">{{ money(currentShift.total) }}</p>
                    <p class="text-[11px] font-medium text-gray-400">se descuentan del cajón</p>
                </div>
            </div>

            <!-- Resumen + acciones -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <div class="flex gap-6">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Total</p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ money(totals.amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400"># Gastos</p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ totals.count }}</p>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <div class="flex gap-2">
                        <button @click="iaOpen = true" :disabled="!canRegister" :title="registerHint"
                            class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-4 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:from-gray-300 disabled:to-gray-300 disabled:shadow-none">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                            Con IA
                        </button>
                        <button @click="openCreate" :disabled="!canRegister" :title="registerHint"
                            class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-red-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:shadow-none">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Registrar gasto
                        </button>
                    </div>
                    <Link v-if="!hasOpenShift" :href="route('caja.turno', tenant.slug)" class="text-[11px] font-medium text-red-600 hover:underline">Abre tu turno primero →</Link>
                </div>
            </div>

            <!-- Buscador -->
            <div class="relative">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input v-model="search" type="text" placeholder="Buscar concepto o notas..."
                    class="block h-10 w-full rounded-xl border-gray-200 bg-white pl-10 pr-3 text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300" />
            </div>

            <!-- Lista -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!expenses.data.length" class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-500">Aún no has registrado gastos.</p>
                    <p class="mt-1 text-xs text-gray-400">Usa “Registrar gasto” cuando tengas tu turno abierto.</p>
                </div>
                <table v-else class="min-w-full">
                    <thead class="bg-gray-50/60"><tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Hora</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Concepto</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Subcategoría</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Monto</th>
                    </tr></thead>
                    <tbody v-for="g in groupedDays" :key="g.key" class="divide-y divide-gray-50 border-t border-gray-100 bg-white">
                        <!-- Encabezado del día: fecha + nº de gastos + subtotal -->
                        <tr class="bg-gray-50/70">
                            <td colspan="2" class="px-5 py-2.5">
                                <span class="text-xs font-bold uppercase tracking-wide text-gray-600">{{ fmtDayHeading(g.date) }}</span>
                                <span class="ml-2 text-[11px] font-medium text-gray-400">{{ g.count }} {{ g.count === 1 ? 'gasto' : 'gastos' }}</span>
                            </td>
                            <td colspan="2" class="px-5 py-2.5 text-right">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total del día&nbsp;</span>
                                <span class="text-sm font-bold tabular-nums text-gray-700">{{ money(g.total) }}</span>
                            </td>
                        </tr>
                        <tr v-for="e in g.items" :key="e.id" @click="openDetail(e)"
                            class="cursor-pointer transition hover:bg-red-50/30">
                            <td class="px-5 py-3 text-sm tabular-nums text-gray-500">{{ fmtTime(e.expense_at) }}</td>
                            <td class="px-5 py-3 text-sm font-bold text-gray-900">
                                {{ e.concept }}
                                <span v-if="e.attachments?.length" class="ml-1 inline-flex items-center gap-0.5 align-middle text-[11px] font-semibold text-blue-600">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
                                    {{ e.attachments.length }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                <div>{{ e.subcategory?.name || '—' }}</div>
                                <div class="text-xs text-gray-400">{{ e.subcategory?.category?.name }}</div>
                            </td>
                            <td class="px-5 py-3 text-right text-sm font-bold tabular-nums text-gray-900">{{ money(e.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="expenses.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs text-gray-500">Página <span class="font-semibold">{{ expenses.current_page }}</span> de {{ expenses.last_page }} · {{ expenses.total }} gastos</p>
                <div class="flex gap-1.5">
                    <button v-for="link in expenses.links" :key="link.label" @click="goToPage(link.url)"
                        :disabled="!link.url || link.active" v-html="link.label"
                        :class="['h-9 min-w-[36px] rounded-lg px-3 text-xs font-bold transition',
                            link.active ? 'bg-red-600 text-white' :
                            link.url ? 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50' :
                            'bg-gray-50 text-gray-300 cursor-not-allowed']" />
                </div>
            </div>
        </div>

        <!-- IA capture -->
        <GastoCapturaIAModal
            :show="iaOpen"
            :tenant-slug="tenant.slug"
            submit-route-name="caja.gastos.ia.store"
            @close="iaOpen = false"
            @proposal="onAiProposal" />

        <!-- Form (efectivo fijo, sin selector de sucursal ni método de pago) -->
        <GastoFormModal
            :show="formOpen"
            :mode="editId ? 'edit' : 'create'"
            :tenant-slug="tenant.slug"
            :categories="categories"
            :allow-branch-select="false"
            :fixed-branch-id="branchId"
            :expense="editId ? selected : null"
            :ai-proposal="aiProposal"
            :ai-draft-id="aiDraftId"
            :ai-attachments="aiAttachments"
            :ai-transcription="aiTranscription"
            :submit-route-name="editId ? 'caja.gastos.update' : 'caja.gastos.store'"
            attachment-destroy-route-name="caja.gastos.store"
            @close="formOpen = false; editId = null; resetAi()"
            @success="formOpen = false; editId = null; resetAi()" />

        <GastoDetailModal
            :show="detailOpen"
            :expense="selected"
            :tenant-slug="tenant.slug"
            preview-route-name="caja.gastos.index"
            download-route-name="caja.gastos.index"
            :can-edit="selected?.can_manage ?? false"
            :can-delete="selected?.can_manage ?? false"
            :payment-methods="paymentMethods"
            @close="detailOpen = false"
            @edit="onEditGasto"
            @delete="onDeleteGasto" />

        <FlashToast />
    </CajeroLayout>
</template>
