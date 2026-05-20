<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n || 0).toLocaleString('es-MX');
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-violet-100 bg-gradient-to-br from-violet-50 to-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2">
            <span class="rounded-full bg-violet-200/60 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-violet-900">Compras</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-700 shadow-sm">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Total comprado</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmt(data.total_amount) }}</div>
                <div class="text-xs text-gray-500">{{ fmtInt(data.count) }} compras</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Promedio</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmt(data.avg_amount) }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Pendiente</div>
                <div class="text-2xl font-bold" :class="data.pending_total > 0 ? 'text-amber-700' : 'text-gray-400'">
                    {{ data.pending_total > 0 ? fmt(data.pending_total) : '—' }}
                </div>
            </div>
        </div>

        <div v-if="data.top_providers?.length" class="mt-4 border-t border-violet-100 pt-3">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">Top proveedores</h4>
            <ul class="space-y-1">
                <li v-for="(p, i) in data.top_providers" :key="i" class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 truncate">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-800">{{ i + 1 }}</span>
                        <span class="truncate font-medium text-gray-800">{{ p.provider_name }}</span>
                    </span>
                    <span class="flex items-baseline gap-2 text-right">
                        <span class="text-xs text-gray-500">{{ p.count }}×</span>
                        <span class="font-semibold text-gray-900">{{ fmt(p.total) }}</span>
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
