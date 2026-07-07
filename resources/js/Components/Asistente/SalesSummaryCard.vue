<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n || 0).toLocaleString('es-MX');
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-orange-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-orange-900">Ventas</span>
            <span class="text-xs text-gray-500">{{ data.date_from }} → {{ data.date_to }}</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Ventas netas</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmt(data.net_sales) }}</div>
                <div v-if="data.delta_pct !== null && data.delta_pct !== undefined" class="text-xs font-medium" :class="data.delta_pct >= 0 ? 'text-emerald-700' : 'text-red-600'">
                    {{ data.delta_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(data.delta_pct) }}% vs anterior
                </div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Tickets</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmtInt(data.ticket_count) }}</div>
                <div class="text-xs text-gray-500">prom. {{ fmt(data.avg_ticket) }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase text-gray-500">Canceladas</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmtInt(data.cancelled_count) }}</div>
                <div class="text-xs text-gray-500">{{ fmt(data.cancelled_amount) }}</div>
            </div>
        </div>

        <div v-if="Number(data.collected_from_previous_days) > 0" class="mt-2.5 border-t border-gray-100 pt-2 text-xs text-gray-600">
            Cobranza del periodo: <span class="font-semibold text-gray-900">{{ fmt(data.collected_total) }}</span>
            · <span class="font-semibold text-emerald-700">{{ fmt(data.collected_from_previous_days) }}</span> son abonos a ventas de días anteriores
        </div>
    </div>
</template>
