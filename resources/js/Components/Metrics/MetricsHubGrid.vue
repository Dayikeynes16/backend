<script setup>
// Hub de Métricas: grid 4×2 con los 8 ejes. Sin KPIs, sin gráficos —
// el detalle vive en cada eje. Los filtros del header (preset/from/to/
// statuses/branch_id) se propagan al eje destino vía la URL.

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    scope: { type: String, required: true }, // 'sucursal' | 'empresa'
});

const page = usePage();
const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

// Preserva los query params actuales para que entrar a un eje conserve
// los filtros activos en Resumen.
const currentQuery = computed(() => {
    if (typeof window === 'undefined') return {};
    const params = new URLSearchParams(window.location.search);
    const q = {};
    for (const [key, value] of params.entries()) {
        q[key] = value;
    }
    return q;
});

const subpages = computed(() => {
    const prefix = props.scope === 'empresa' ? 'empresa' : 'sucursal';
    const items = [
        { key: 'ventas', label: 'Ventas', hint: 'Volumen y tendencias', icon: 'trend' },
        { key: 'margen', label: 'Margen', hint: 'Rentabilidad', icon: 'chart' },
        { key: 'productos', label: 'Productos', hint: 'Top y sin movimiento', icon: 'box' },
        { key: 'clientes', label: 'Clientes', hint: 'Top y saldos', icon: 'user' },
        { key: 'cajeros', label: 'Cajeros', hint: 'Desempeño', icon: 'badge' },
        { key: 'turnos', label: 'Turnos', hint: 'Diferencias de caja', icon: 'shift' },
        { key: 'cobranza', label: 'Cobranza', hint: 'Cuentas por cobrar', icon: 'money' },
        { key: 'cancelaciones', label: 'Cancelaciones', hint: 'Motivos y tiempo de respuesta', icon: 'cancel' },
    ];
    return items.map((s) => ({
        ...s,
        href: route(`${prefix}.metricas.${s.key}`, { tenant: slug.value, ...currentQuery.value }),
    }));
});

const iconPaths = {
    trend: 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941',
    chart: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75Z',
    box: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0',
    badge: 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818',
    shift: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    money: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12',
    cancel: 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
};
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Link v-for="s in subpages" :key="s.key" :href="s.href"
            class="group flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-red-300 hover:shadow-md">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600 transition group-hover:bg-red-600 group-hover:text-white">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[s.icon]" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">{{ s.label }}</p>
                <p class="truncate text-xs text-gray-500">{{ s.hint }}</p>
            </div>
        </Link>
    </div>
</template>
