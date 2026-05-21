<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import CompraFormModal from '@/Components/Compras/CompraFormModal.vue';
import CompraDetailModal from '@/Components/Compras/CompraDetailModal.vue';
import CompraCapturaIAModal from '@/Components/Compras/CompraCapturaIAModal.vue';
import DateField from '@/Components/DateField.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { localToday } from '@/utils/date';

const props = defineProps({
    purchases: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    purchaseProducts: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({ total_amount: 0, count: 0, pending_total: 0, pending_count: 0 }) },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const search = ref(props.filters?.q || '');
const today = localToday();
// Por defecto el día de hoy; el calendario permite cambiar de día.
const selectedDate = ref(props.filters?.from || today);
const branchId = ref(props.filters?.branch_id || '');
const providerId = ref(props.filters?.provider_id || '');
const statusFilter = ref(props.filters?.status || 'all');
const paymentFilter = ref(props.filters?.payment_status || '');

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (iso) => iso ? new Date(iso).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: '2-digit' }) : '—';

let debounceTimer;
const navigate = () => {
    router.get(route('empresa.compras.index', slug.value), {
        q: search.value || undefined,
        from: selectedDate.value || undefined,
        to: selectedDate.value || undefined,
        branch_id: branchId.value || undefined,
        provider_id: providerId.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
        payment_status: paymentFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(navigate, 300); });
watch([selectedDate, branchId, providerId, statusFilter, paymentFilter], navigate);

const paymentBadge = (s) => ({
    paid: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-amber-100 text-amber-800',
    pending: 'bg-gray-100 text-gray-700',
    cancelled: 'bg-red-100 text-red-700',
})[s] || 'bg-gray-100 text-gray-700';

const paymentLabel = (s) => ({ paid: 'Pagada', partial: 'Abonada', pending: 'Pendiente', cancelled: 'Cancelada' })[s] || s;

// Modales
const formOpen = ref(false);
const detailOpen = ref(false);
const iaOpen = ref(false);
const editing = ref(null);
const viewing = ref(null);
const aiResult = ref(null);

const openCreate = () => { editing.value = null; aiResult.value = null; formOpen.value = true; };
const openIA = () => { iaOpen.value = true; };
const onAiAnalyzed = (result) => {
    aiResult.value = result;
    iaOpen.value = false;
    editing.value = null;
    formOpen.value = true;
};
const openDetail = (purchase) => { viewing.value = purchase; detailOpen.value = true; };
const editFromDetail = () => {
    editing.value = viewing.value;
    detailOpen.value = false;
    formOpen.value = true;
};

const flash = computed(() => page.props.flash || {});

const detailRoutes = {
    cancel: 'empresa.compras.cancel',
    pagoStore: 'empresa.compras.pagos.store',
    pagoDestroy: 'empresa.compras.pagos.destroy',
    adjuntoDownload: 'empresa.compras.adjuntos.download',
    adjuntoPreview: 'empresa.compras.adjuntos.preview',
    adjuntoDestroy: 'empresa.compras.adjuntos.destroy',
};
const formRoutes = { store: 'empresa.compras.store', update: 'empresa.compras.update' };
const iaRoutes = { iaStore: 'empresa.compras.ia.store' };
</script>

<template>
    <Head title="Compras" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-lg font-bold text-gray-900">Compras</h1>
        </template>

        <div class="space-y-5">
            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Total comprado</div>
                    <div class="text-2xl font-bold text-gray-900">{{ fmt(kpis.total_amount) }}</div>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500"># Compras</div>
                    <div class="text-2xl font-bold text-gray-900">{{ kpis.count }}</div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Por pagar</div>
                    <div class="text-2xl font-bold text-amber-700">{{ fmt(kpis.pending_total) }}</div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500"># Pendientes</div>
                    <div class="text-2xl font-bold text-amber-700">{{ kpis.pending_count }}</div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="grid grid-cols-1 gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
                <input v-model="search" type="text" placeholder="Folio, factura, proveedor…"
                    class="rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 lg:col-span-2" />
                <div class="lg:col-span-2">
                    <DateField v-model="selectedDate" mode="single" :max="today" align="left" class="w-full" />
                </div>
                <select v-model="branchId" class="rounded-xl border-gray-300 text-sm">
                    <option value="">Todas las sucursales</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <select v-model="providerId" class="rounded-xl border-gray-300 text-sm">
                    <option value="">Todos los proveedores</option>
                    <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <div class="col-span-full flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Estado:</span>
                    <button v-for="s in ['all', 'received', 'cancelled']" :key="s"
                        @click="statusFilter = s"
                        :class="['rounded-lg px-3 py-1 text-xs font-semibold transition',
                            statusFilter === s ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                        {{ s === 'all' ? 'Todas' : s === 'received' ? 'Recibidas' : 'Canceladas' }}
                    </button>
                    <span class="ml-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Pago:</span>
                    <button v-for="ps in ['', 'pending', 'partial', 'paid']" :key="ps || 'all'"
                        @click="paymentFilter = ps"
                        :class="['rounded-lg px-3 py-1 text-xs font-semibold transition',
                            (paymentFilter || '') === ps ? 'bg-orange-600 text-white' : 'bg-orange-50 text-orange-800 hover:bg-orange-100']">
                        {{ ps === '' ? 'Todas' : ps === 'pending' ? 'Pendientes' : ps === 'partial' ? 'Abonadas' : 'Pagadas' }}
                    </button>
                    <div class="ml-auto flex gap-2">
                        <button @click="openIA"
                            class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-violet-700 hover:to-fuchsia-700">
                            ✨ Capturar con IA
                        </button>
                        <button @click="openCreate"
                            class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">
                            + Nueva compra
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="flash.success" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{{ flash.error }}</div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Folio</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Fecha</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Proveedor</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Sucursal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Pendiente</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Pago</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in purchases" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-gray-900">{{ p.folio }}</div>
                                <div v-if="p.invoice_number" class="text-xs text-gray-500">F: {{ p.invoice_number }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ fmtDate(p.purchased_at) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.provider?.name || '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.branch?.name || '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900">{{ fmt(p.total) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium" :class="p.amount_pending > 0 ? 'text-amber-700' : 'text-gray-400'">
                                {{ p.amount_pending > 0 ? fmt(p.amount_pending) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', paymentBadge(p.payment_status)]">
                                    {{ paymentLabel(p.payment_status) }}
                                </span>
                                <span v-if="p.attachments?.length > 0" class="ml-1 inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">
                                    📎 {{ p.attachments.length }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button @click="openDetail(p)" class="text-sm font-medium text-orange-700 hover:text-orange-900">Ver</button>
                            </td>
                        </tr>
                        <tr v-if="!purchases.length">
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-gray-500">
                                Sin compras aún. <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Registra la primera</button>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <CompraFormModal
            :open="formOpen"
            :purchase="editing"
            :providers="providers"
            :purchase-products="purchaseProducts"
            :branches="branches"
            :ai-result="aiResult"
            :routes="formRoutes"
            @close="formOpen = false; aiResult = null"
        />
        <CompraDetailModal
            :open="detailOpen"
            :purchase="viewing"
            :routes="detailRoutes"
            @close="detailOpen = false"
            @edit="editFromDetail"
        />
        <CompraCapturaIAModal
            :open="iaOpen"
            :routes="iaRoutes"
            @close="iaOpen = false"
            @analyzed="onAiAnalyzed"
        />
    </EmpresaLayout>
</template>
