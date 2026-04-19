<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    axisLabel: { type: String, default: null },
});

const page = usePage();
const slug = computed(() => page.props.tenant?.slug ?? page.props.auth?.tenant_slug);

const preservedQuery = computed(() => {
    const r = page.props.range ?? {};
    const q = {};
    if (r.preset && r.preset !== '__custom__') q.preset = r.preset;
    if (r.from && r.to && (!r.preset || r.preset === '__custom__')) {
        q.from = r.from;
        q.to = r.to;
    }
    if (page.props.compare !== undefined) q.compare = page.props.compare ? 1 : 0;
    return q;
});

const metricasHref = computed(() => {
    const base = route('sucursal.metricas.index', slug.value);
    const params = new URLSearchParams(preservedQuery.value).toString();
    return params ? `${base}?${params}` : base;
});
</script>

<template>
    <nav aria-label="Breadcrumb" class="mb-4 flex items-center justify-between gap-3 text-sm">
        <ol class="flex items-center gap-1.5 text-gray-500">
            <li>
                <Link :href="route('sucursal.dashboard', slug)" class="hover:text-gray-700">Sucursal</Link>
            </li>
            <li aria-hidden="true"><span class="text-gray-300">/</span></li>
            <li>
                <Link :href="metricasHref" :class="axisLabel ? 'hover:text-gray-700' : 'font-semibold text-gray-900'">
                    Métricas
                </Link>
            </li>
            <template v-if="axisLabel">
                <li aria-hidden="true"><span class="text-gray-300">/</span></li>
                <li class="font-semibold text-gray-900">{{ axisLabel }}</li>
            </template>
        </ol>

        <Link v-if="axisLabel" :href="metricasHref"
            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            Volver al resumen
        </Link>
    </nav>
</template>
