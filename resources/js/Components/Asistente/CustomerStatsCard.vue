<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-900">
                {{ data.metric === 'outstanding_debt' ? 'Saldo por cobrar' : 'Top clientes' }}
            </span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
        </div>

        <div v-if="data.metric === 'outstanding_debt'">
            <div class="mb-2">
                <div class="text-xs font-medium uppercase text-gray-500">Total adeudado</div>
                <div class="text-2xl font-bold text-gray-900">{{ fmt(data.total_debt) }}</div>
            </div>
            <ul v-if="data.top_customers?.length" class="space-y-1">
                <li v-for="c in data.top_customers" :key="c.customer_id" class="flex items-center justify-between text-sm">
                    <span class="truncate text-gray-800">{{ c.name }}</span>
                    <span class="font-semibold text-gray-900">{{ fmt(c.debt) }}</span>
                </li>
            </ul>
        </div>

        <div v-else-if="data.metric === 'top_buyers'">
            <ul v-if="data.customers?.length" class="space-y-1">
                <li v-for="(c, i) in data.customers" :key="c.customer_id" class="flex items-center justify-between text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-800">{{ i + 1 }}</span>
                        <span class="truncate text-gray-800">{{ c.name }}</span>
                    </span>
                    <span class="font-semibold text-gray-900">{{ fmt(c.total_bought) }}</span>
                </li>
            </ul>
            <p v-else class="text-sm italic text-gray-500">Sin compras registradas.</p>
        </div>
    </div>
</template>
