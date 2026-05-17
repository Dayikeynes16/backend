<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    // El pedido web pendiente que el usuario quiere cumplir con una venta ya creada
    webOrder: { type: Object, required: true },
    routePrefix: { type: String, default: 'sucursal' },
});

const emit = defineEmits(['close', 'linked']);

const sales = ref([]);
const loading = ref(false);
const submitting = ref(false);
const fetchError = ref('');
const selectedSaleId = ref(null);

const listRouteName = computed(() =>
    props.routePrefix === 'caja' ? 'caja.linkable-sales' : 'sucursal.workbench.linkable-sales'
);
const linkRouteName = computed(() =>
    props.routePrefix === 'caja' ? 'caja.link-order' : 'sucursal.workbench.link-order'
);

const fetchSales = async () => {
    loading.value = true;
    fetchError.value = '';
    try {
        const { data } = await axios.get(route(listRouteName.value, props.tenantSlug));
        sales.value = data.sales || [];
    } catch (e) {
        fetchError.value = 'No se pudieron cargar las ventas disponibles.';
    } finally {
        loading.value = false;
    }
};

watch(() => props.show, (v) => {
    if (v) {
        selectedSaleId.value = null;
        fetchError.value = '';
        fetchSales();
    }
});

const selectedSale = computed(() => sales.value.find(s => s.id === selectedSaleId.value) ?? null);

const deliveryFee = computed(() => Number(props.webOrder?.delivery_fee ?? 0));
const itemsSubtotal = computed(() => Number(selectedSale.value?.total ?? 0));
const previewTotal = computed(() => itemsSubtotal.value + deliveryFee.value);

const canSubmit = computed(() => !submitting.value && selectedSaleId.value !== null);

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const formatRelativeTime = (iso) => {
    if (!iso) return '';
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.round(diffMs / 60000);
    if (mins < 1) return 'hace instantes';
    if (mins < 60) return `hace ${mins} min`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `hace ${hrs} h`;
    return `hace ${Math.round(hrs / 24)} d`;
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
        route(linkRouteName.value, [props.tenantSlug, selectedSaleId.value]),
        { order_id: props.webOrder.id },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                emit('linked', selectedSale.value);
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
                                Vincular pedido con venta de báscula
                            </h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                Selecciona la venta real que cumple el pedido
                                <span class="font-mono font-semibold text-gray-700">{{ webOrder.folio }}</span>.
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
                        <div v-if="loading" class="flex flex-1 items-center justify-center py-12">
                            <div class="flex items-center gap-3 text-sm text-gray-500">
                                <svg class="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                                Cargando ventas…
                            </div>
                        </div>

                        <div v-else-if="fetchError" class="px-6 py-10 text-center">
                            <p class="text-sm text-red-600">{{ fetchError }}</p>
                            <button type="button" class="mt-3 text-sm font-semibold text-red-700 underline" @click="fetchSales">
                                Reintentar
                            </button>
                        </div>

                        <div v-else-if="sales.length === 0" class="px-6 py-12 text-center">
                            <p class="text-sm text-gray-500">
                                No hay ventas activas disponibles para vincular.
                            </p>
                            <p class="mt-1 text-xs text-gray-400">
                                Primero el carnicero debe registrar la venta en la báscula.
                            </p>
                        </div>

                        <div v-else class="flex-1 overflow-y-auto px-6 py-4">
                            <ul class="space-y-2.5">
                                <li v-for="s in sales" :key="s.id">
                                    <label
                                        class="block cursor-pointer rounded-xl border-2 px-4 py-3 transition"
                                        :class="selectedSaleId === s.id
                                            ? 'border-orange-500 bg-orange-50 ring-2 ring-orange-200'
                                            : 'border-gray-200 hover:border-orange-300 hover:bg-orange-50/30'"
                                    >
                                        <div class="flex items-start gap-3">
                                            <input
                                                v-model="selectedSaleId"
                                                type="radio"
                                                :value="s.id"
                                                class="mt-1.5 h-4 w-4 shrink-0 cursor-pointer text-orange-600 focus:ring-orange-500"
                                            />
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-mono text-sm font-bold text-gray-900">{{ s.folio }}</span>
                                                    <span class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ formatMoney(s.total) }}</span>
                                                </div>
                                                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                                    <span>{{ formatRelativeTime(s.created_at) }}</span>
                                                    <span class="text-gray-700">· {{ s.origin_name || 'API' }}</span>
                                                </div>
                                                <div v-if="s.items_preview?.length" class="mt-2 space-y-0.5">
                                                    <p v-for="(item, idx) in s.items_preview" :key="idx" class="truncate text-xs text-gray-600">
                                                        · {{ itemPreviewText(item) }}
                                                    </p>
                                                    <p v-if="s.items_count > s.items_preview.length" class="text-xs italic text-gray-400">
                                                        y {{ s.items_count - s.items_preview.length }} más
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </li>
                            </ul>
                        </div>

                        <div v-if="selectedSale" class="border-t border-gray-100 bg-orange-50/50 px-6 py-3">
                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                                Total final de la venta tras vincular
                            </p>
                            <div class="space-y-1 text-sm">
                                <div class="flex justify-between text-gray-600">
                                    <span>Productos en {{ selectedSale.folio }}</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(itemsSubtotal) }}</span>
                                </div>
                                <div v-if="deliveryFee > 0" class="flex justify-between text-gray-600">
                                    <span>Envío (del pedido)</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(deliveryFee) }}</span>
                                </div>
                                <div class="flex justify-between border-t border-orange-200 pt-1.5 text-base font-bold text-gray-900">
                                    <span>Total</span>
                                    <span class="font-mono tabular-nums">{{ formatMoney(previewTotal) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

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
