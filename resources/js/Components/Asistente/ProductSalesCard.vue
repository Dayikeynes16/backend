<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtQty = (n) => Number(n || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-emerald-900">Ventas de producto</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
        </div>

        <template v-if="data.found">
            <p class="text-base font-semibold text-gray-900">{{ data.product_name }}</p>
            <div class="mt-1.5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">Vendido</div>
                    <div class="text-2xl font-bold tabular-nums text-gray-900">{{ fmtQty(data.total_quantity) }} <span class="text-sm font-medium text-gray-500">{{ data.unit_type }}</span></div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">Ingreso</div>
                    <div class="text-2xl font-bold tabular-nums text-gray-900">{{ fmt(data.total_revenue) }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">Precio prom.</div>
                    <div class="text-2xl font-bold tabular-nums text-gray-900">{{ data.avg_price !== null ? fmt(data.avg_price) : '—' }}</div>
                    <div class="text-xs text-gray-500">lista {{ fmt(data.current_price) }}</div>
                </div>
            </div>

            <div v-if="data.price_breakdown?.length" class="mt-3 border-t border-gray-100 pt-2.5">
                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Por precio de venta
                    <span v-if="data.price_breakdown.length > 1" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-bold normal-case text-amber-800">{{ data.price_breakdown.length }} precios distintos</span>
                </p>
                <ul class="space-y-1">
                    <li v-for="b in data.price_breakdown" :key="b.unit_price" class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-gray-600">{{ fmtQty(b.quantity) }} {{ data.unit_type }} a <span class="font-semibold text-gray-900">{{ fmt(b.unit_price) }}</span></span>
                        <span class="text-right text-gray-500">{{ fmt(b.revenue) }} · {{ b.tickets }} ticket{{ b.tickets === 1 ? '' : 's' }}</span>
                    </li>
                </ul>
            </div>
            <p v-else class="mt-2 text-sm italic text-gray-500">Sin ventas en el periodo.</p>
        </template>

        <template v-else>
            <p class="text-sm text-gray-600">No identifiqué el producto "{{ data.product_name }}".</p>
            <p v-if="data.candidates?.length" class="mt-1 text-xs text-gray-500">¿Te refieres a: {{ data.candidates.join(', ') }}?</p>
        </template>
    </div>
</template>
