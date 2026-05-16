<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    sale: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const changes = ref([]);
const loading = ref(false);
const error = ref('');

watch(() => props.show, async (v) => {
    if (!v || !props.sale) return;
    loading.value = true;
    error.value = '';
    changes.value = [];
    try {
        const resp = await axios.get(route('sucursal.workbench.items.history', [props.tenantSlug, props.sale.id]));
        changes.value = resp.data?.changes ?? [];
    } catch (e) {
        error.value = 'No se pudo cargar el historial.';
    } finally {
        loading.value = false;
    }
});

const formatMoney = (v) => '$' + Number(v ?? 0).toFixed(2);

const timeAgo = (iso) => {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso)) / 1000);
    if (diff < 60) return 'hace unos segundos';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    return new Date(iso).toLocaleString('es-MX', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};

const eventMeta = {
    added: {
        label: 'Agregado',
        cls: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
        iconBg: 'bg-emerald-100 text-emerald-700',
        icon: 'M12 4.5v15m7.5-7.5h-15',
    },
    updated: {
        label: 'Editado',
        cls: 'bg-orange-100 text-orange-700 ring-orange-600/20',
        iconBg: 'bg-orange-100 text-orange-700',
        icon: 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z',
    },
    removed: {
        label: 'Eliminado',
        cls: 'bg-red-100 text-red-700 ring-red-600/20',
        iconBg: 'bg-red-100 text-red-700',
        icon: 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79',
    },
};

const fieldLabels = {
    quantity: 'Cantidad',
    unit_price: 'Precio unitario',
    subtotal: 'Subtotal',
};

const formatFieldValue = (field, value) => {
    if (value === null || value === undefined) return '—';
    if (field === 'unit_price' || field === 'subtotal') return formatMoney(value);

    return String(value);
};

const productName = (change) => change.after?.product_name || change.before?.product_name || 'Producto sin nombre';

const isEmpty = computed(() => !loading.value && !error.value && changes.value.length === 0);
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" leave-active-class="transition duration-100"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="$emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold text-gray-900">Historial de cambios</h3>
                            <p class="mt-0.5 text-sm text-gray-500">Cada edición a los items de la venta queda registrada aquí.</p>
                        </div>
                        <button @click="$emit('close')" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div v-if="loading" class="flex items-center justify-center py-10 text-sm text-gray-400">
                            <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            Cargando historial…
                        </div>
                        <div v-else-if="error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ error }}</div>
                        <div v-else-if="isEmpty" class="rounded-2xl border-2 border-dashed border-gray-200 px-6 py-12 text-center">
                            <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <p class="mt-3 text-sm font-semibold text-gray-700">Esta venta no tiene cambios registrados.</p>
                            <p class="mt-1 text-xs text-gray-400">Los items se crearon con la venta y nadie los ha tocado desde entonces.</p>
                        </div>
                        <ol v-else class="space-y-3">
                            <li v-for="ch in changes" :key="ch.id" class="rounded-2xl bg-white p-4 ring-1 ring-gray-100 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <div :class="[eventMeta[ch.event]?.iconBg, 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full']">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="eventMeta[ch.event]?.icon" /></svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span :class="[eventMeta[ch.event]?.cls, 'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset']">{{ eventMeta[ch.event]?.label }}</span>
                                            <span class="text-sm font-bold text-gray-900">{{ productName(ch) }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            {{ timeAgo(ch.created_at) }}
                                            <span v-if="ch.user?.name"> · <span class="font-semibold text-gray-700">{{ ch.user.name }}</span></span>
                                        </p>

                                        <!-- Diff para updated -->
                                        <div v-if="ch.event === 'updated' && ch.diff" class="mt-2 space-y-0.5">
                                            <div v-for="(values, field) in ch.diff" :key="field" class="flex items-baseline gap-2 text-xs">
                                                <span class="text-gray-500">{{ fieldLabels[field] || field }}:</span>
                                                <span class="text-gray-400 line-through">{{ formatFieldValue(field, values[0]) }}</span>
                                                <svg class="h-3 w-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                                                <span class="font-semibold text-gray-900">{{ formatFieldValue(field, values[1]) }}</span>
                                            </div>
                                        </div>

                                        <!-- Snapshot para added -->
                                        <div v-else-if="ch.event === 'added' && ch.after" class="mt-2 text-xs text-gray-600">
                                            {{ ch.after.quantity }} × {{ formatMoney(ch.after.unit_price) }} =
                                            <span class="font-semibold text-gray-900">{{ formatMoney(ch.after.subtotal) }}</span>
                                        </div>

                                        <!-- Snapshot para removed -->
                                        <div v-else-if="ch.event === 'removed' && ch.before" class="mt-2 text-xs text-gray-600">
                                            Se eliminó {{ ch.before.quantity }} × {{ formatMoney(ch.before.unit_price) }} = <span class="font-semibold text-gray-900">{{ formatMoney(ch.before.subtotal) }}</span>
                                        </div>

                                        <p v-if="ch.reason" class="mt-2 italic text-xs text-gray-500">"{{ ch.reason }}"</p>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button type="button" @click="$emit('close')"
                            class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Cerrar</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
