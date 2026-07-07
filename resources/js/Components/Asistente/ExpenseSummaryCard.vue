<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-red-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-red-900">Gastos</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Total</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmt(data.total) }}</div>
                <div class="text-xs text-gray-500">{{ data.count }} movimientos</div>
            </div>
            <div v-if="data.top_subcategories?.length">
                <div class="text-xs font-medium uppercase text-gray-500">Top subcategorías</div>
                <ul class="mt-1 space-y-1">
                    <li v-for="(s, i) in data.top_subcategories" :key="i" class="flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-gray-800">{{ s.subcategory || '—' }}</span>
                        <span class="font-medium text-gray-900">{{ fmt(s.total) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="data.by_payment_method?.length" class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-2.5">
            <span v-for="m in data.by_payment_method" :key="m.method" class="rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600">
                {{ m.method }}: {{ fmt(m.total) }}
            </span>
        </div>
    </div>
</template>
