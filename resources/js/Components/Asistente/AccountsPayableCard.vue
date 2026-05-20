<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-rose-100 bg-gradient-to-br from-rose-50 to-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2">
            <span class="rounded-full bg-rose-200/60 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-rose-900">Cuentas por pagar</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-700 shadow-sm">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <div class="mb-3">
            <div class="text-xs font-medium uppercase text-gray-500">Total adeudado</div>
            <div class="text-3xl font-bold" :class="data.total_debt > 0 ? 'text-rose-700' : 'text-gray-400'">
                {{ data.total_debt > 0 ? fmt(data.total_debt) : '$0.00' }}
            </div>
            <div class="text-xs text-gray-500">{{ data.purchase_count }} compras pendientes</div>
        </div>

        <div v-if="data.top_providers?.length" class="border-t border-rose-100 pt-3">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">Proveedores con saldo</h4>
            <ul class="space-y-1">
                <li v-for="(p, i) in data.top_providers" :key="i" class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 truncate">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xs font-bold text-rose-800">{{ i + 1 }}</span>
                        <span class="truncate font-medium text-gray-800">{{ p.provider_name }}</span>
                    </span>
                    <span class="flex items-baseline gap-2 text-right">
                        <span class="text-xs text-gray-500">{{ p.purchase_count }} compra{{ p.purchase_count === 1 ? '' : 's' }}</span>
                        <span class="font-semibold text-rose-700">{{ fmt(p.debt) }}</span>
                    </span>
                </li>
            </ul>
        </div>

        <p v-else class="text-sm italic text-gray-500">Sin proveedores con saldo pendiente.</p>
    </div>
</template>
