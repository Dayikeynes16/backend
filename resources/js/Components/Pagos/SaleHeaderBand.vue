<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Banda superior de la venta asociada a un pago: folio, estado y el salto al
 * Historial. Compartida por Pagos de caja y de sucursal — es el bloque que si no
 * habría que escribir dos veces.
 *
 * No conoce rutas ni rol: recibe la URL ya resuelta. Sin `historyUrl` el folio se
 * queda como texto, que es lo correcto para quien no tiene a dónde ir.
 */
const props = defineProps({
    sale: { type: Object, required: true },
    historyUrl: { type: String, default: null },
});

const badge = (s) => ({
    active: { label: 'Activa', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20' },
    pending: { label: 'Pendiente', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
    completed: { label: 'Cobrada', cls: 'bg-green-50 text-green-700 ring-green-600/20' },
    cancelled: { label: 'Cancelada', cls: 'bg-red-50 text-red-700 ring-red-600/20' },
}[s] || { label: s, cls: 'bg-gray-100 text-gray-600' });
</script>

<template>
    <div class="flex items-center justify-between gap-3 bg-gray-50 px-5 py-3">
        <div class="flex min-w-0 items-center gap-2.5">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Venta</h3>
            <span class="text-sm font-bold text-gray-900">{{ props.sale.folio }}</span>
            <span :class="[badge(props.sale.status).cls, 'rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset']">
                {{ badge(props.sale.status).label }}
            </span>
        </div>

        <div class="flex shrink-0 items-center gap-2.5">
            <slot name="meta" />

            <Link
                v-if="props.historyUrl"
                :href="props.historyUrl"
                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-600 transition hover:bg-red-50 hover:border-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
                title="Abrir esta venta en el historial">
                Ver venta
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>
            </Link>
        </div>
    </div>
</template>
