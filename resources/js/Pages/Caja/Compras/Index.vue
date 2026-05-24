<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CompraFormModal from '@/Components/Compras/CompraFormModal.vue';
import CompraCapturaIAModal from '@/Components/Compras/CompraCapturaIAModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    purchases: Object,
    totals: Object,
    providers: { type: Array, default: () => [] },
    purchaseProducts: { type: Array, default: () => [] },
    hasOpenShift: { type: Boolean, default: false },
    filters: Object,
    tenant: Object,
});

const page = usePage();
const branchId = computed(() => page.props.auth.branch?.id ?? null);

const compraOpen = ref(false);
const compraIaOpen = ref(false);
const compraAiResult = ref(null);

const search = ref(props.filters?.search || '');
let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('caja.compras.index', props.tenant.slug),
            { search: search.value || undefined },
            { preserveState: true, replace: true, preserveScroll: true });
    }, 300);
});

const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (v) => v ? new Date(v).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <Head title="Compras" />
    <CajeroLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Mis Compras</h1></template>

        <div class="mx-auto max-w-3xl space-y-5">
            <!-- Nota: afecta el corte -->
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <p class="text-xs text-amber-800">
                    El pago en efectivo de una compra <strong>sale del cajón</strong> y se descuenta del efectivo esperado en tu <strong>corte de turno</strong>. Solo ves las compras que tú registraste.
                </p>
            </div>

            <!-- Resumen + acciones -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <div class="flex gap-6">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Total</p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ money(totals.amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400"># Compras</p>
                        <p class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ totals.count }}</p>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <div class="flex gap-2">
                        <button @click="compraIaOpen = true" :disabled="!hasOpenShift"
                            :title="hasOpenShift ? 'Captura una compra con IA' : 'Abre tu turno para registrar una compra'"
                            class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-4 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:from-gray-300 disabled:to-gray-300 disabled:shadow-none">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                            Compra con IA
                        </button>
                        <button @click="compraOpen = true" :disabled="!hasOpenShift"
                            :title="hasOpenShift ? '' : 'Abre tu turno para registrar una compra'"
                            class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-red-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:shadow-none">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Registrar compra
                        </button>
                    </div>
                    <Link v-if="!hasOpenShift" :href="route('caja.turno', tenant.slug)" class="text-[11px] font-medium text-red-600 hover:underline">Abre tu turno primero →</Link>
                </div>
            </div>

            <!-- Buscador -->
            <div class="relative">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input v-model="search" type="text" placeholder="Buscar folio, factura o proveedor..."
                    class="block h-10 w-full rounded-xl border-gray-200 bg-white pl-10 pr-3 text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300" />
            </div>

            <!-- Lista -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!purchases.data.length" class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-500">Aún no has registrado compras.</p>
                    <p class="mt-1 text-xs text-gray-400">Usa “Registrar compra” cuando tengas tu turno abierto.</p>
                </div>
                <table v-else class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/60"><tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Fecha</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Folio</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Proveedor</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50 bg-white">
                        <tr v-for="p in purchases.data" :key="p.id" class="transition hover:bg-red-50/30">
                            <td class="px-5 py-3 text-sm font-semibold text-gray-700">{{ fmtDate(p.purchased_at) }}</td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                <div class="font-bold text-gray-900">{{ p.folio }}</div>
                                <div v-if="p.invoice_number" class="text-xs text-gray-400">Factura {{ p.invoice_number }}</div>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-700">{{ p.provider?.name || '—' }}</td>
                            <td class="px-5 py-3 text-right text-sm font-bold tabular-nums text-gray-900">
                                {{ money(p.total) }}
                                <div v-if="Number(p.amount_pending) > 0" class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-red-500">
                                    Debe {{ money(p.amount_pending) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="purchases.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs text-gray-500">Página <span class="font-semibold">{{ purchases.current_page }}</span> de {{ purchases.last_page }} · {{ purchases.total }} compras</p>
                <div class="flex gap-1.5">
                    <button v-for="link in purchases.links" :key="link.label" @click="goToPage(link.url)"
                        :disabled="!link.url || link.active" v-html="link.label"
                        :class="['h-9 min-w-[36px] rounded-lg px-3 text-xs font-bold transition',
                            link.active ? 'bg-red-600 text-white' :
                            link.url ? 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50' :
                            'bg-gray-50 text-gray-300 cursor-not-allowed']" />
                </div>
            </div>
        </div>

        <CompraCapturaIAModal :open="compraIaOpen" :routes="{ iaStore: 'caja.compras.ia.store' }"
            @close="compraIaOpen = false"
            @analyzed="(r) => { compraAiResult = r; compraIaOpen = false; compraOpen = true; }" />

        <CompraFormModal :open="compraOpen" :purchase="null" cash-mode
            :providers="providers" :purchase-products="purchaseProducts"
            :fixed-branch-id="branchId" :ai-result="compraAiResult"
            :routes="{ store: 'caja.compras.store', update: 'caja.compras.store' }"
            @close="compraOpen = false; compraAiResult = null" />

        <FlashToast />
    </CajeroLayout>
</template>
