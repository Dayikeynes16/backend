<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n || 0).toLocaleString('es-MX');
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-violet-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-violet-900">Compras</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
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

        <div v-if="data.top_providers?.length" class="mt-3 border-t border-gray-100 pt-2.5">
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
