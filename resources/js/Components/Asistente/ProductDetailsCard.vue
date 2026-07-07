<script setup>
const props = defineProps({ data: { type: Object, required: true } });

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const margin = (price, cost) => {
    const p = Number(price || 0); const c = Number(cost || 0);
    if (p <= 0 || c <= 0) return null;
    return Math.round(((p - c) / p) * 100);
};
</script>

<template>
    <div class="rounded-xl border border-gray-200/80 bg-white px-4 py-3">
        <div class="mb-2.5 flex items-center gap-2">
            <span class="rounded-md bg-indigo-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-indigo-900">Productos</span>
            <span v-if="data.name_query" class="text-xs text-gray-600">buscando "{{ data.name_query }}"</span>
            <span v-if="data.category_name" class="text-xs text-gray-600">en categoría "{{ data.category_name }}"</span>
            <span v-if="data.branch_name" class="ml-auto rounded-full border border-gray-100 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ data.branch_name }}</span>
            <span v-else class="ml-auto text-xs italic text-gray-500">todas las sucursales</span>
        </div>

        <p v-if="!data.products?.length" class="text-sm italic text-gray-500">Sin coincidencias.</p>

        <!-- Single product: detalle expandido -->
        <div v-else-if="data.products.length === 1" class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-lg font-bold text-gray-900">{{ data.products[0].name }}</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-600 shadow-sm">{{ data.products[0].category || 'sin categoría' }}</span>
            </div>
            <p v-if="data.products[0].description" class="text-sm text-gray-700">{{ data.products[0].description }}</p>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">Precio</div>
                    <div class="text-xl font-bold text-gray-900">{{ fmt(data.products[0].price) }}</div>
                    <div class="text-xs text-gray-500">por {{ data.products[0].unit_type }}</div>
                </div>
                <div v-if="data.products[0].cost_price > 0">
                    <div class="text-xs font-medium uppercase text-gray-500">Costo</div>
                    <div class="text-xl font-bold text-gray-900">{{ fmt(data.products[0].cost_price) }}</div>
                    <div v-if="margin(data.products[0].price, data.products[0].cost_price) !== null" class="text-xs text-emerald-700">{{ margin(data.products[0].price, data.products[0].cost_price) }}% margen</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase text-gray-500">Modo de venta</div>
                    <div class="text-sm font-semibold text-gray-900">{{ data.products[0].sale_mode || data.products[0].unit_type }}</div>
                </div>
            </div>
            <div v-if="data.products[0].presentations?.length" class="border-t border-gray-100 pt-2.5">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">Presentaciones</div>
                <ul class="space-y-1">
                    <li v-for="(p, i) in data.products[0].presentations" :key="i" class="flex items-center justify-between text-sm">
                        <span class="text-gray-800">{{ p.name }} <span v-if="p.content && p.unit" class="text-gray-500">· {{ p.content }} {{ p.unit }}</span></span>
                        <span class="font-medium text-gray-900">{{ fmt(p.price) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Multiple products: lista compacta -->
        <ul v-else class="divide-y divide-indigo-100">
            <li v-for="p in data.products" :key="p.id" class="flex items-baseline justify-between gap-3 py-2 text-sm">
                <span class="flex min-w-0 flex-col">
                    <span class="truncate font-medium text-gray-900">{{ p.name }}</span>
                    <span class="text-xs text-gray-500">{{ p.category || 'sin categoría' }} · por {{ p.unit_type }}</span>
                </span>
                <span class="text-right">
                    <span class="font-semibold text-gray-900">{{ fmt(p.price) }}</span>
                    <span v-if="p.presentations?.length" class="ml-2 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">+{{ p.presentations.length }} pres.</span>
                </span>
            </li>
        </ul>
    </div>
</template>
