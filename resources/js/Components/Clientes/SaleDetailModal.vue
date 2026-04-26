<script setup>
import Modal from '@/Components/Modal.vue';
import { displayName, displayQuantity } from '@/composables/useSaleItemDisplay';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    show: Boolean,
    tenantSlug: { type: String, required: true },
    customerId: { type: [Number, String], default: null },
    saleId: { type: [Number, String], default: null },
});

const emit = defineEmits(['close']);

const sale = ref(null);
const loading = ref(false);
const error = ref(null);

let controller = null;

const load = async () => {
    if (!props.customerId || !props.saleId) return;
    if (controller) controller.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    sale.value = null;
    try {
        const res = await fetch(
            route('sucursal.clientes.venta-detalle', [props.tenantSlug, props.customerId, props.saleId]),
            {
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        sale.value = await res.json();
    } catch (e) {
        if (e.name !== 'AbortError') error.value = e.message || 'Error al cargar la venta';
    } finally {
        loading.value = false;
    }
};

watch(() => [props.show, props.saleId], ([show]) => {
    if (show) load();
    else if (controller) { controller.abort(); }
});

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// Quantity/name rendering goes through the shared formatter so legacy items
// (only unit_type) and new items (with presentation_snapshot) both render correctly.
const itemQuantity = (item) => displayQuantity(item);
const itemName = (item) => displayName(item);
const fmtDateTime = (v) => {
    if (!v) return '—';
    return new Date(v).toLocaleString('es-MX', {
        day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m || '—');

const statusBadge = computed(() => {
    if (!sale.value) return null;
    const s = sale.value.status;
    const pending = Number(sale.value.amount_pending);
    const paid = Number(sale.value.amount_paid);
    if (s === 'cancelled') return { text: 'Cancelada', cls: 'bg-gray-100 text-gray-600 ring-gray-200' };
    if (pending <= 0 && paid > 0) return { text: 'Pagada', cls: 'bg-green-100 text-green-700 ring-green-200' };
    if (pending > 0 && paid > 0) return { text: 'Parcialmente pagada', cls: 'bg-amber-100 text-amber-800 ring-amber-200' };
    return { text: 'Pendiente de pago', cls: 'bg-red-100 text-red-700 ring-red-200' };
});

const totalSaved = computed(() => {
    if (!sale.value?.items) return 0;
    return sale.value.items.reduce((acc, i) => {
        const diff = Number(i.original_unit_price) - Number(i.unit_price);
        return acc + (diff > 0 ? diff * Number(i.quantity) : 0);
    }, 0);
});
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div v-if="loading" class="flex items-center justify-center px-8 py-16">
            <div class="flex items-center gap-3 text-gray-500">
                <svg class="h-5 w-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.37 0 0 5.37 0 12h4zm2 5.29A7.96 7.96 0 014 12H0c0 3.04 1.13 5.82 3 7.94l3-2.65z"></path>
                </svg>
                <span class="text-sm font-medium">Cargando venta...</span>
            </div>
        </div>

        <div v-else-if="error" class="px-8 py-12 text-center">
            <p class="text-sm font-medium text-red-600">{{ error }}</p>
            <button @click="emit('close')" class="mt-4 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cerrar</button>
        </div>

        <div v-else-if="sale">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-7 py-5">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-3">
                        <h3 class="text-xl font-bold text-gray-900">Venta {{ sale.folio }}</h3>
                        <span v-if="statusBadge" :class="['rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', statusBadge.cls]">{{ statusBadge.text }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">{{ fmtDateTime(sale.created_at) }}</p>
                    <p v-if="sale.cashier" class="text-xs text-gray-400">Cobrada por {{ sale.cashier.name }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a v-if="sale.whatsapp_url"
                        :href="sale.whatsapp_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        :title="`Enviar detalle por WhatsApp a ${sale.customer?.name ?? 'cliente'}`"
                        class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-3.5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-[#1ebe5b] focus:outline-none focus:ring-2 focus:ring-[#25D366]/40">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                        </svg>
                        <span class="hidden sm:inline">Enviar por WhatsApp</span>
                        <span class="sm:hidden">WhatsApp</span>
                    </a>
                    <button @click="emit('close')" class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" aria-label="Cerrar">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            <!-- Items -->
            <div class="max-h-[50vh] overflow-y-auto px-7 py-5">
                <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">Productos</h4>
                <div class="overflow-hidden rounded-xl ring-1 ring-gray-100">
                    <table class="min-w-full divide-y divide-gray-50">
                        <thead class="bg-gray-50/70">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500">Producto</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">Cantidad</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">Precio</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 bg-white">
                            <tr v-for="item in sale.items" :key="item.id">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ itemName(item) }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-700">{{ itemQuantity(item) }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums">
                                    <div :class="Number(item.unit_price) < Number(item.original_unit_price) ? 'text-green-700 font-semibold' : 'text-gray-900'">{{ money(item.unit_price) }}</div>
                                    <div v-if="Number(item.unit_price) < Number(item.original_unit_price)" class="text-[11px] font-normal text-gray-400 line-through">{{ money(item.original_unit_price) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-gray-900">{{ money(item.subtotal) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-gray-50/70">
                            <tr>
                                <td colspan="3" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Total</td>
                                <td class="px-4 py-2.5 text-right text-base font-bold tabular-nums text-gray-900">{{ money(sale.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Savings banner -->
                <div v-if="totalSaved > 0" class="mt-4 flex items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-100">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-amber-900">Ahorro del cliente en esta venta</p>
                        <p class="text-xs text-amber-700">Precio preferencial aplicado vs catálogo estándar</p>
                    </div>
                    <p class="text-lg font-bold tabular-nums text-amber-700">{{ money(totalSaved) }}</p>
                </div>

                <!-- Payments -->
                <div v-if="sale.payments.length > 0" class="mt-6">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">Pagos registrados</h4>
                    <ul class="divide-y divide-gray-100 rounded-xl ring-1 ring-gray-100">
                        <li v-for="pay in sale.payments" :key="pay.id" class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-green-50 text-green-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ methodLabel(pay.method) }} · {{ money(pay.amount) }}</p>
                                    <p class="text-xs text-gray-500">{{ fmtDateTime(pay.created_at) }}<span v-if="pay.user"> · {{ pay.user.name }}</span></p>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <!-- Payment summary -->
                    <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-lg bg-gray-50 px-3 py-2.5">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Total</p>
                            <p class="text-sm font-bold tabular-nums text-gray-900">{{ money(sale.total) }}</p>
                        </div>
                        <div class="rounded-lg bg-green-50 px-3 py-2.5">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-green-700">Pagado</p>
                            <p class="text-sm font-bold tabular-nums text-green-700">{{ money(sale.amount_paid) }}</p>
                        </div>
                        <div :class="['rounded-lg px-3 py-2.5', sale.amount_pending > 0 ? 'bg-red-50' : 'bg-gray-50']">
                            <p :class="['text-[11px] font-semibold uppercase tracking-wide', sale.amount_pending > 0 ? 'text-red-700' : 'text-gray-500']">Saldo</p>
                            <p :class="['text-sm font-bold tabular-nums', sale.amount_pending > 0 ? 'text-red-700' : 'text-gray-900']">{{ money(sale.amount_pending) }}</p>
                        </div>
                    </div>
                </div>

                <div v-else class="mt-6 rounded-xl border border-dashed border-gray-200 px-6 py-6 text-center">
                    <p class="text-sm text-gray-400">No hay pagos registrados para esta venta.</p>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-7 py-4">
                <button @click="emit('close')" class="h-10 rounded-lg bg-white px-5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Cerrar</button>
            </div>
        </div>
    </Modal>
</template>
