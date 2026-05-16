<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    topProducts: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['load']);

onMounted(() => emit('load', 10));

const sortKey = ref('total_quantity');
const sortDir = ref('desc');

const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));
const number = (v) => new Intl.NumberFormat('es-MX').format(Number(v ?? 0));
const decimal = (v) => Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 3 });

const rows = computed(() => {
    const data = props.topProducts?.data || [];
    return [...data].sort((a, b) => {
        const factor = sortDir.value === 'asc' ? 1 : -1;
        const av = Number(a[sortKey.value] ?? 0);
        const bv = Number(b[sortKey.value] ?? 0);

        return (av - bv) * factor;
    });
});

const totalSaved = computed(() => (props.topProducts?.data || []).reduce((acc, r) => acc + Number(r.total_saved || 0), 0));
const topProduct = computed(() => {
    const data = props.topProducts?.data || [];
    return [...data].sort((a, b) => Number(b.total_spent ?? 0) - Number(a.total_spent ?? 0))[0] || null;
});

const toggleSort = (k) => {
    if (sortKey.value === k) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    else { sortKey.value = k; sortDir.value = 'desc'; }
};

const sortIcon = (k) => sortKey.value === k ? (sortDir.value === 'asc' ? '↑' : '↓') : '';
</script>

<template>
    <div class="space-y-4">
        <!-- KPIs -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Producto preferido</p>
                <p v-if="topProduct" class="mt-1 truncate text-base font-bold text-gray-900">{{ topProduct.product_name }}</p>
                <p v-else class="mt-1 text-sm text-gray-400">Sin compras todavía</p>
                <p v-if="topProduct" class="mt-1 text-xs text-gray-500">{{ money(topProduct.total_spent) }} gastados · {{ topProduct.times_bought }} compras</p>
            </div>
            <div class="rounded-2xl bg-gradient-to-br from-emerald-50/50 to-white px-5 py-4 shadow-sm ring-1 ring-emerald-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Ahorro acumulado</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-emerald-700">{{ money(totalSaved) }}</p>
                <p class="mt-1 text-xs text-emerald-600/70">Suma de descuentos vs precio catálogo</p>
            </div>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
            <div v-if="loading" class="flex items-center justify-center px-6 py-12 text-sm text-gray-400">
                <svg class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                Cargando productos…
            </div>
            <div v-else-if="error" class="px-6 py-10 text-center text-sm text-red-600">{{ error }}</div>
            <div v-else-if="rows.length === 0" class="px-6 py-12 text-center">
                <p class="text-sm font-semibold text-gray-700">Aún no hay productos comprados</p>
                <p class="mt-1 text-xs text-gray-400">Cuando este cliente compre algo, aparecerá aquí.</p>
            </div>
            <table v-else class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Producto</th>
                        <th class="cursor-pointer px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-700" @click="toggleSort('times_bought')">
                            Veces {{ sortIcon('times_bought') }}
                        </th>
                        <th class="cursor-pointer px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-700" @click="toggleSort('total_quantity')">
                            Cantidad {{ sortIcon('total_quantity') }}
                        </th>
                        <th class="cursor-pointer px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-700" @click="toggleSort('total_spent')">
                            Gastado {{ sortIcon('total_spent') }}
                        </th>
                        <th class="cursor-pointer px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500 hover:text-gray-700" @click="toggleSort('total_saved')">
                            Ahorro {{ sortIcon('total_saved') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-for="row in rows" :key="row.product_id" class="transition hover:bg-gray-50/60">
                        <td class="whitespace-nowrap px-5 py-3 text-sm font-semibold text-gray-900">{{ row.product_name }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums text-gray-600">{{ number(row.times_bought) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums text-gray-600">{{ decimal(row.total_quantity) }} {{ row.unit_type }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ money(row.total_spent) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-right text-sm tabular-nums"
                            :class="Number(row.total_saved) > 0 ? 'font-semibold text-emerald-700' : 'text-gray-400'">
                            {{ Number(row.total_saved) > 0 ? money(row.total_saved) : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
