<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2">
            <span class="rounded-full bg-sky-200/60 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-sky-900">Turnos</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-700 shadow-sm">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-600">Abiertos ({{ data.open_shifts?.length || 0 }})</h4>
            <ul v-if="data.open_shifts?.length" class="mt-1 space-y-1">
                <li v-for="s in data.open_shifts" :key="s.id" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm shadow-sm">
                    <span class="truncate font-medium text-gray-900">{{ s.cashier || '—' }} <span class="text-gray-400">·</span> {{ s.branch }}</span>
                    <span class="text-xs text-gray-500">desde {{ fmtDate(s.opened_at) }}</span>
                </li>
            </ul>
            <p v-else class="text-sm italic text-gray-500">Ningún turno abierto.</p>
        </div>

        <div v-if="data.recent_closed_shifts?.length" class="mt-4 border-t border-sky-100 pt-3">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-600">Cortes recientes</h4>
            <ul class="mt-1 space-y-1">
                <li v-for="s in data.recent_closed_shifts" :key="s.id" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm shadow-sm">
                    <span class="truncate text-gray-800">{{ s.cashier }} <span class="text-gray-400">·</span> {{ fmtDate(s.closed_at) }}</span>
                    <span class="flex items-baseline gap-2">
                        <span class="font-medium text-gray-900">{{ fmt(s.total_sales) }}</span>
                        <span v-if="Math.abs(s.difference) > 0.005" class="text-xs font-medium" :class="s.difference < 0 ? 'text-red-600' : 'text-emerald-700'">
                            {{ s.difference > 0 ? '+' : '' }}{{ fmt(s.difference) }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
