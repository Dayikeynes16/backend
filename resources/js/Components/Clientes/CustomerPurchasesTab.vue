<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import DateField from '@/Components/DateField.vue';
import SaleDetailModal from '@/Components/Clientes/SaleDetailModal.vue';

const props = defineProps({
    customerId: { type: Number, required: true },
    tenantSlug: { type: String, required: true },
    history: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    products: { type: Array, default: () => [] },
    allowedPaymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    saleItemEditReasonMode: { type: String, default: 'optional' },
});

const emit = defineEmits(['load']);

// v-model del DateField range: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }.
const range = ref({ from: '', to: '' });

onMounted(() => emit('load', {}));
watch(range, (v) => emit('load', { from: v?.from || undefined, to: v?.to || undefined }), { deep: true });

const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));
const formatDate = (iso) => iso ? new Date(iso).toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
}) : '—';

const statusBadge = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });

const sales = computed(() => props.history?.data || []);
const meta = computed(() => props.history?.meta || {});

const selectedSaleId = ref(null);
</script>

<template>
    <div class="space-y-4">
        <!-- Filtros: DateField range con presets nativos -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <DateField v-model="range" mode="range" align="left" />
            <span v-if="!loading && sales.length" class="text-xs text-gray-500">
                {{ sales.length }} {{ sales.length === 1 ? 'compra' : 'compras' }}
            </span>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div v-if="loading" class="flex items-center justify-center px-6 py-12 text-sm text-gray-400">
                <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                Cargando compras…
            </div>
            <div v-else-if="error" class="px-6 py-10 text-center text-sm text-red-600">{{ error }}</div>
            <div v-else-if="sales.length === 0" class="px-6 py-12 text-center">
                <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-700">Sin compras en el rango</p>
                <p class="mt-1 text-xs text-gray-400">Ajusta el rango o selecciona "Todo" para ver el historial completo.</p>
            </div>
            <table v-else class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Folio</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Fecha</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500"># items</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Total</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Pendiente</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Estado</th>
                        <th class="px-2 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-for="sale in sales" :key="sale.id" @click="selectedSaleId = sale.id" class="cursor-pointer transition hover:bg-gray-50/60">
                        <td class="whitespace-nowrap px-5 py-3 text-sm font-bold text-gray-900">{{ sale.folio }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600">{{ formatDate(sale.created_at) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums text-gray-600">{{ sale.items?.length ?? 0 }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ money(sale.total) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums"
                            :class="Number(sale.amount_pending) > 0 ? 'font-semibold text-red-600' : 'text-gray-400'">
                            {{ Number(sale.amount_pending) > 0 ? money(sale.amount_pending) : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-5 py-3 text-sm">
                            <span :class="[statusBadge(sale.status).cls, 'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset']">{{ statusBadge(sale.status).label }}</span>
                        </td>
                        <td class="whitespace-nowrap px-2 py-3 text-right">
                            <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="sales.length > 0 && meta.total" class="border-t border-gray-100 bg-gray-50/40 px-5 py-2.5 text-xs text-gray-500">
                Mostrando <b>{{ sales.length }}</b> de <b>{{ meta.total }}</b> compras
            </div>
        </div>

        <SaleDetailModal
            :show="!!selectedSaleId"
            :tenant-slug="tenantSlug"
            :customer-id="customerId"
            :sale-id="selectedSaleId"
            :products="products"
            :allowed-payment-methods="allowedPaymentMethods"
            :sale-item-edit-reason-mode="saleItemEditReasonMode"
            @close="selectedSaleId = null"
            @sale-changed="emit('load', { from: range.from || undefined, to: range.to || undefined })" />
    </div>
</template>
