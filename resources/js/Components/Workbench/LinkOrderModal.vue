<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    scaleSale: { type: Object, required: true },
    // 'sucursal' o 'caja' — define qué grupo de rutas usar
    routePrefix: { type: String, default: 'sucursal' },
});

const emit = defineEmits(['close', 'linked']);

const orders = ref([]);
const loading = ref(false);
const submitting = ref(false);
const fetchError = ref('');
const selectedOrderId = ref(null);

const listRouteName = computed(() =>
    props.routePrefix === 'caja' ? 'caja.pending-web-orders' : 'sucursal.workbench.pending-web-orders'
);
const linkRouteName = computed(() =>
    props.routePrefix === 'caja' ? 'caja.link-order' : 'sucursal.workbench.link-order'
);

const fetchOrders = async () => {
    loading.value = true;
    fetchError.value = '';
    try {
        const { data } = await axios.get(route(listRouteName.value, props.tenantSlug));
        orders.value = data.orders || [];
    } catch (e) {
        fetchError.value = 'No se pudieron cargar los pedidos pendientes.';
    } finally {
        loading.value = false;
    }
};

watch(() => props.show, (v) => {
    if (v) {
        selectedOrderId.value = null;
        fetchError.value = '';
        fetchOrders();
    }
});

const itemsSubtotal = computed(() => {
    const total = Number(props.scaleSale?.total ?? 0);
    const fee = Number(props.scaleSale?.delivery_fee ?? 0);
    // Si la venta ya tiene un delivery_fee (no debería en este flujo), lo restamos
    // para no doble contarlo en el preview.
    return Math.max(total - fee, 0);
});

const selectedOrder = computed(() =>
    orders.value.find(o => o.id === selectedOrderId.value) ?? null
);

const previewDeliveryFee = computed(() => Number(selectedOrder.value?.delivery_fee ?? 0));
const previewTotal = computed(() => itemsSubtotal.value + previewDeliveryFee.value);

const canSubmit = computed(() => !submitting.value && selectedOrderId.value !== null);

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const formatRelativeTime = (iso) => {
    if (!iso) return '';
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.round(diffMs / 60000);
    if (mins < 1) return 'hace instantes';
    if (mins < 60) return `hace ${mins} min`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `hace ${hrs} h`;
    const days = Math.round(hrs / 24);
    return `hace ${days} d`;
};

const itemPreviewText = (item) => {
    const qty = Number(item.quantity ?? 0);
    const unit = item.unit_type === 'kg' ? ' kg' : '';
    const qtyStr = item.unit_type === 'kg' ? qty.toFixed(2) : String(Math.round(qty));
    return `${qtyStr}${unit} ${item.product_name}`;
};

const submit = () => {
    if (!canSubmit.value) return;
    submitting.value = true;

    router.post(
        route(linkRouteName.value, [props.tenantSlug, props.scaleSale.id]),
        { order_id: selectedOrderId.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('linked', selectedOrder.value);
                emit('close');
            },
            onFinish: () => {
                submitting.value = false;
            },
        }
    );
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150"
            leave-active-class="transition duration-100"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4"
                @click.self="$emit('close')"
            >
                <div
                    class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl"
                    @click.stop
                >
                    <!-- Header -->
                    <div class="flex items-start gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-600">
                            🔗
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold text-gray-900">
                                Vincular venta con pedido web
                            </h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                Selecciona el pedido al que corresponde la venta
                                <span class="font-mono font-semibold text-gray-700">{{ scaleSale.folio }}</span>.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                            @click="$emit('close')"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex flex-1 flex-col overflow-hidden">
                        <!-- Loading -->
                        <div v-if="loading" class="flex flex-1 items-center justify-center py-12">
                            <div class="flex items-center gap-3 text-sm text-gray-500">
                                <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                                Cargando pedidos…
                            </div>
                        </div>

                        <!-- Error -->
                        <div v-else-if="fetchError" class="px-6 py-10 text-center">
                            <p class="text-sm text-red-600">{{ fetchError }}</p>
                            <button type="button" class="mt-3 text-sm font-semibold text-red-700 underline" @click="fetchOrders">
                                Reintentar
                            </button>
                        </div>

                        <!-- Empty -->
                        <div v-else-if="orders.length === 0" class="px-6 py-12 text-center">
                            <p class="text-sm text-gray-500">No hay pedidos web pendientes en esta sucursal.</p>
                        </div>

                        <!-- List -->
                        <div v-else class="flex-1 overflow-y-auto px-6 py-4">
                            <ul class="space-y-2.5">
                                <li v-for="o in orders" :key="o.id">
                                    <label
                                        class="block cursor-pointer rounded-xl border-2 px-4 py-3 transition"
                                        :class="selectedOrderId === o.id
                                            ? 'border-orange-500 bg-orange-50 ring-2 ring-orange-200'
                                            : 'border-gray-200 hover:border-orange-300 hover:bg-orange-50/30'"
                                    >
                                        <div class="flex items-start gap-3">
                                            <input
                                                v-model="selectedOrderId"
                                                type="radio"
                                                :value="o.id"
                                                class="mt-1.5 h-4 w-4 shrink-0 cursor-pointer text-orange-600 focus:ring-orange-500"
                                            />
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-mono text-sm font-bold text-gray-900">{{ o.folio }}</span>
                                                    <span class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ formatMoney(o.total) }}</span>
                                                </div>
                                                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                                    <span>{{ formatRelativeTime(o.created_at) }}</span>
                                                    <span v-if="o.contact_name" class="text-gray-700">· {{ o.contact_name }}</span>
                                                    <a
                                                        v-if="o.contact_phone"
                                                        :href="`tel:${o.contact_phone}`"
                                                        class="text-orange-600 hover:underline"
                                                        @click.stop
                                                    >
                                                        {{ o.contact_phone }}
                                                    </a>
                                                </div>
                                                <div class="mt-1.5 flex items-center gap-2 text-xs">
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                        :class="o.delivery_type === 'delivery'
                                                            ? 'bg-blue-100 text-blue-700'
                                                            : 'bg-emerald-100 text-emerald-700'"
                                                    >
                                                        {{ o.delivery_type === 'delivery' ? '🛵 Envío' : '🏬 Recoger' }}
                                                    </span>
                                                    <span v-if="o.delivery_type === 'delivery' && o.delivery_fee" class="text-gray-500">
                                                        +{{ formatMoney(o.delivery_fee) }}
                                                    </span>
                                                </div>
                                                <p v-if="o.delivery_address" class="mt-1 truncate text-xs text-gray-500">
                                                    {{ o.delivery_address }}
                                                </p>
                                                <div v-if="o.items_preview?.length" class="mt-2 space-y-0.5">
                                                    <p v-for="(item, idx) in o.items_preview" :key="idx" class="truncate text-xs text-gray-600">
                                                        · {{ itemPreviewText(item) }}
                                                    </p>
                                                    <p v-if="o.items_count > o.items_preview.length" class="text-xs italic text-gray-400">
                                                        y {{ o.items_count - o.items_preview.length }} más
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </li>
                            </ul>
                        </div>

                        <!-- Preview del desglose -->
                        <div v-if="selectedOrder" class="border-t border-gray-100 bg-orange-50/50 px-6 py-3">
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                                Total final de la venta tras vincular
                            </p>
                            <div class="space-y-1 text-sm">
                                <div class="flex justify-between text-gray-600">
                                    <span>Productos en la venta</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(itemsSubtotal) }}</span>
                                </div>
                                <div v-if="previewDeliveryFee > 0" class="flex justify-between text-gray-600">
                                    <span>Envío (del pedido web)</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(previewDeliveryFee) }}</span>
                                </div>
                                <div class="flex justify-between border-t border-orange-200 pt-1.5 text-base font-bold text-gray-900">
                                    <span>Total</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(previewTotal) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button
                            type="button"
                            class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200"
                            @click="$emit('close')"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            :disabled="!canSubmit"
                            class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-50"
                            @click="submit"
                        >
                            <svg v-if="submitting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            {{ submitting ? 'Vinculando…' : 'Confirmar vinculación' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
