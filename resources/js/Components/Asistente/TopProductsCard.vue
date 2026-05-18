<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtQty = (n) => Number(n || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2">
            <span class="rounded-full bg-emerald-200/60 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-emerald-900">Top productos</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-700 shadow-sm">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div v-if="data.products?.length">
            <ol class="space-y-2">
                <li v-for="(p, i) in data.products" :key="i" class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">{{ i + 1 }}</span>
                        <span class="truncate font-medium text-gray-800">{{ p.product_name }}</span>
                    </span>
                    <span class="flex items-baseline gap-3 text-right">
                        <span class="text-xs text-gray-500">{{ fmtQty(p.quantity) }}</span>
                        <span class="font-semibold text-gray-900">{{ fmt(p.revenue) }}</span>
                    </span>
                </li>
            </ol>
        </div>
        <p v-else class="text-sm italic text-gray-500">Sin ventas en el periodo.</p>
    </div>
</template>
