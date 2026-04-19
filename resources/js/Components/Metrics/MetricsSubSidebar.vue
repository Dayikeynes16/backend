<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    activeAxis: { type: String, default: null }, // null = Resumen (Index)
});

const page = usePage();
const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

const axes = [
    { key: null,         label: 'Resumen',   route: 'sucursal.metricas.index',     iconPath: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z' },
    { key: 'ventas',     label: 'Ventas',    route: 'sucursal.metricas.ventas',    iconPath: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { key: 'margen',     label: 'Margen',    route: 'sucursal.metricas.margen',    iconPath: 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941' },
    { key: 'productos',  label: 'Productos', route: 'sucursal.metricas.productos', iconPath: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z' },
    { key: 'clientes',   label: 'Clientes',  route: 'sucursal.metricas.clientes',  iconPath: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z' },
    { key: 'cajeros',    label: 'Cajeros',   route: 'sucursal.metricas.cajeros',   iconPath: 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z' },
    { key: 'turnos',     label: 'Turnos',    route: 'sucursal.metricas.turnos',    iconPath: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { key: 'cobranza',   label: 'Cobranza',  route: 'sucursal.metricas.cobranza',  iconPath: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
];

// Preserva preset/from/to/compare/branch_id al saltar entre ejes.
const preservedQuery = computed(() => {
    const r = page.props.range ?? {};
    const q = {};
    if (r.preset && r.preset !== '__custom__') q.preset = r.preset;
    if (r.from && r.to && (!r.preset || r.preset === '__custom__')) {
        q.from = r.from;
        q.to = r.to;
    }
    if (page.props.compare !== undefined) q.compare = page.props.compare ? 1 : 0;
    if (page.props.selected_branch_id) q.branch_id = page.props.selected_branch_id;
    return q;
});

const hrefFor = (routeName) => {
    const base = route(routeName, slug.value);
    const params = new URLSearchParams(preservedQuery.value).toString();
    return params ? `${base}?${params}` : base;
};
</script>

<template>
    <aside class="hidden w-56 shrink-0 lg:block">
        <nav class="sticky top-24 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm">
            <p class="mb-1 px-3 pt-2 text-[11px] font-bold uppercase tracking-wider text-gray-400">Módulo</p>
            <Link :href="hrefFor(axes[0].route)"
                :class="['group flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition',
                    activeAxis === null ? 'bg-red-50 text-red-700' : 'text-gray-700 hover:bg-gray-50']">
                <svg class="h-4 w-4 shrink-0" :class="activeAxis === null ? 'text-red-600' : 'text-gray-400 group-hover:text-gray-600'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="axes[0].iconPath" />
                </svg>
                {{ axes[0].label }}
            </Link>

            <p class="mb-1 mt-3 px-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Ejes</p>
            <div class="space-y-0.5">
                <Link v-for="axis in axes.slice(1)" :key="axis.key" :href="hrefFor(axis.route)"
                    :class="['group flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition',
                        activeAxis === axis.key ? 'bg-red-50 text-red-700' : 'text-gray-700 hover:bg-gray-50']">
                    <svg class="h-4 w-4 shrink-0" :class="activeAxis === axis.key ? 'text-red-600' : 'text-gray-400 group-hover:text-gray-600'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="axis.iconPath" />
                    </svg>
                    {{ axis.label }}
                </Link>
            </div>
        </nav>
    </aside>

    <div class="mb-4 lg:hidden">
        <label class="text-xs font-semibold uppercase tracking-wider text-gray-500">Ver eje</label>
        <select :value="activeAxis ?? ''" @change="(e) => { const v = e.target.value; window.location.href = hrefFor(v === '' ? axes[0].route : axes.find(a => a.key === v).route) }"
            class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm focus:border-red-500 focus:ring-red-500">
            <option v-for="axis in axes" :key="axis.key ?? 'resumen'" :value="axis.key ?? ''">{{ axis.label }}</option>
        </select>
    </div>
</template>
