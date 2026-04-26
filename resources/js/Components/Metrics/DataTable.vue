<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    columns: { type: Array, required: true }, // [{ key, label, format?, align?, width? }]
    // Acepta Array o Object (defensivo: Inertia a veces serializa Collection
    // como objeto cuando hay caché o claves no consecutivas).
    rows: { type: [Array, Object], default: () => [] },
    pageSize: { type: Number, default: 50 },
    emptyMessage: { type: String, default: 'Sin datos en este rango' },
});

const normalizedRows = computed(() => {
    if (Array.isArray(props.rows)) return props.rows;
    if (props.rows && typeof props.rows === 'object') return Object.values(props.rows);
    return [];
});

const sortKey = ref(null);
const sortDir = ref('desc'); // 'asc' | 'desc'
const page = ref(1);

const toggleSort = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'desc';
    }
    page.value = 1;
};

const sortedRows = computed(() => {
    if (!sortKey.value) return normalizedRows.value;
    const key = sortKey.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...normalizedRows.value].sort((a, b) => {
        const av = a[key];
        const bv = b[key];
        if (av === null || av === undefined) return 1;
        if (bv === null || bv === undefined) return -1;
        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
        return String(av).localeCompare(String(bv)) * dir;
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(sortedRows.value.length / props.pageSize)));

const pagedRows = computed(() => {
    const start = (page.value - 1) * props.pageSize;
    return sortedRows.value.slice(start, start + props.pageSize);
});

const goTo = (p) => {
    if (p < 1 || p > totalPages.value) return;
    page.value = p;
};

const formatters = {
    currency: (v) => v === null || v === undefined ? '—' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(v),
    number: (v) => v === null || v === undefined ? '—' : new Intl.NumberFormat('es-MX').format(v),
    decimal: (v) => v === null || v === undefined ? '—' : Number(v).toFixed(3),
    percent: (v) => v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`,
    date: (v) => v ? new Date(v).toLocaleDateString('es-MX') : '—',
    datetime: (v) => v ? new Date(v).toLocaleString('es-MX') : '—',
};

const formatCell = (col, row) => {
    const v = row[col.key];
    if (col.format && formatters[col.format]) return formatters[col.format](v);
    if (v === null || v === undefined) return '—';
    return v;
};
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th v-for="col in columns" :key="col.key" :style="col.width ? { width: col.width } : {}"
                            :class="['px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500',
                                col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left']">
                            <button type="button" @click="toggleSort(col.key)" class="inline-flex items-center gap-1 transition hover:text-gray-900">
                                {{ col.label }}
                                <span v-if="sortKey === col.key" class="text-red-600">
                                    <svg v-if="sortDir === 'desc'" class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5 8l5 5 5-5H5z"/></svg>
                                    <svg v-else class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5 12l5-5 5 5H5z"/></svg>
                                </span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="(row, idx) in pagedRows" :key="idx" class="transition hover:bg-gray-50">
                        <td v-for="col in columns" :key="col.key"
                            :class="['whitespace-nowrap px-4 py-3 text-sm text-gray-800',
                                col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                                col.strong ? 'font-semibold text-gray-900' : '']">
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ formatCell(col, row) }}
                            </slot>
                        </td>
                    </tr>
                    <tr v-if="!pagedRows.length">
                        <td :colspan="columns.length" class="px-4 py-10 text-center text-sm text-gray-400">{{ emptyMessage }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="totalPages > 1" class="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-4 py-2.5 text-xs text-gray-600">
            <span>Página <b>{{ page }}</b> de <b>{{ totalPages }}</b> · {{ normalizedRows.length }} registros</span>
            <div class="flex gap-1">
                <button @click="goTo(page - 1)" :disabled="page === 1" class="rounded-md px-2 py-1 font-medium text-gray-700 hover:bg-white disabled:opacity-40">← Anterior</button>
                <button @click="goTo(page + 1)" :disabled="page === totalPages" class="rounded-md px-2 py-1 font-medium text-gray-700 hover:bg-white disabled:opacity-40">Siguiente →</button>
            </div>
        </div>
    </div>
</template>
