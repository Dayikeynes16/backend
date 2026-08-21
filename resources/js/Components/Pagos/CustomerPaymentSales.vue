<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Las ventas que abonó un cobro global (FIFO), con lo que le tocó a cada una.
 *
 * Sin esto el panel de un cobro global se corta en el folio del cobro y nunca dice
 * a dónde se fue el dinero — que es justo lo que uno pregunta ahí.
 *
 * `historyUrl` es una función folio → URL, para no saber de rutas ni de rol.
 */
const props = defineProps({
    customerPayment: { type: Object, required: true },
    historyUrl: { type: Function, default: null },
});

const money = (n) => `$${parseFloat(n ?? 0).toFixed(2)}`;

const shortDate = (iso) => iso
    ? new Date(iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
    : '';

const badge = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada', cls: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });

// Orden FIFO: la venta más vieja primero, que es como se repartió el abono.
const rows = computed(() => (props.customerPayment?.payments ?? [])
    .filter((p) => p.sale)
    .map((p) => ({ id: p.id, amount: p.amount, sale: p.sale }))
    .sort((a, b) => new Date(a.sale.created_at) - new Date(b.sale.created_at)));
</script>

<template>
    <div v-if="rows.length">
        <h3 class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-gray-400">
            Ventas que pagó
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-500">{{ rows.length }}</span>
        </h3>

        <div class="overflow-hidden rounded-xl ring-1 ring-gray-200/50">
            <div
                v-for="(row, idx) in rows"
                :key="row.id"
                :class="['flex items-center justify-between gap-3 px-4 py-2.5', idx > 0 ? 'border-t border-gray-100' : '']">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="text-sm font-bold text-gray-900">{{ row.sale.folio }}</span>
                    <span class="text-xs text-gray-400">{{ shortDate(row.sale.created_at) }}</span>
                    <span :class="[badge(row.sale.status).cls, 'rounded-full px-1.5 py-0.5 text-[9px] font-semibold ring-1 ring-inset']">
                        {{ badge(row.sale.status).label }}
                    </span>
                </div>

                <div class="flex shrink-0 items-center gap-2.5">
                    <span class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(row.amount) }}</span>
                    <Link
                        v-if="props.historyUrl"
                        :href="props.historyUrl(row.sale.folio)"
                        class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-white px-2.5 py-1 text-[11px] font-bold text-red-600 transition hover:bg-red-50 hover:border-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
                        :title="`Abrir la venta ${row.sale.folio} en el historial`">
                        Ver
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
