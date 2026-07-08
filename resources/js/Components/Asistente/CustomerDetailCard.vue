<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtQty = (n) => Number(n || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });

const STATUS_LABELS = { active: 'Activa', completed: 'Pagada', pending: 'Pendiente', fulfilled: 'Cumplida' };
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-900">Cliente</span>
            <span v-if="data.found" class="truncate text-sm font-semibold text-gray-900">{{ data.customer_name }}</span>
            <span v-if="data.found && data.total_owed > 0" class="ml-auto rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700">debe {{ fmt(data.total_owed) }}</span>
            <span v-else-if="data.found" class="ml-auto rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">al corriente</span>
        </div>

        <template v-if="data.found">
            <div v-if="data.sales?.length" class="space-y-2.5">
                <div v-for="s in data.sales" :key="s.folio" class="rounded-lg bg-gray-50 px-3 py-2">
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="font-semibold text-gray-800">{{ s.folio }} <span class="font-normal text-gray-400">· {{ s.date }}</span></span>
                        <span class="tabular-nums font-bold text-gray-900">{{ fmt(s.total) }}</span>
                    </div>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="(i, idx) in s.items" :key="idx" class="flex items-center justify-between gap-2 text-xs text-gray-600">
                            <span class="min-w-0 truncate">{{ fmtQty(i.quantity) }} × {{ i.product }}</span>
                            <span class="shrink-0 tabular-nums">{{ fmt(i.unit_price) }} → {{ fmt(i.subtotal) }}</span>
                        </li>
                    </ul>
                    <p v-if="s.amount_pending > 0" class="mt-1 text-xs font-semibold text-red-700">Pendiente: {{ fmt(s.amount_pending) }}</p>
                </div>
            </div>
            <p v-else class="text-sm italic text-gray-500">Sin ventas registradas.</p>

            <div v-if="data.recent_payments?.length" class="mt-3 border-t border-gray-100 pt-2.5">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Últimos abonos</p>
                <ul class="space-y-0.5">
                    <li v-for="p in data.recent_payments" :key="p.folio" class="flex items-center justify-between text-xs text-gray-600">
                        <span :class="p.cancelled ? 'line-through opacity-60' : ''">{{ p.folio }} · {{ p.date }} · {{ p.method }}</span>
                        <span class="tabular-nums font-semibold" :class="p.cancelled ? 'line-through opacity-60' : 'text-emerald-700'">{{ fmt(p.amount_applied) }}</span>
                    </li>
                </ul>
            </div>
        </template>

        <template v-else>
            <p class="text-sm text-gray-600">No identifiqué al cliente "{{ data.customer_name }}".</p>
            <p v-if="data.candidates?.length" class="mt-1 text-xs text-gray-500">¿Te refieres a: {{ data.candidates.join(', ') }}?</p>
        </template>
    </div>
</template>
