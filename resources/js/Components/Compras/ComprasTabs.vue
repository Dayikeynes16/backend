<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Barra de navegación del dominio de compras (spec 2026-07-15).
// Cada tab es un Link a la ruta existente de su sección; el prefijo de rol
// se infiere de la ruta actual (solo Empresa y Sucursal montan estas páginas).
const props = defineProps({
    active: {
        type: String,
        required: true,
        validator: (v) => ['compras', 'productos-compra', 'proveedores'].includes(v),
    },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const prefix = computed(() => (route().current() || '').startsWith('empresa.') ? 'empresa' : 'sucursal');

const tabs = computed(() => [
    { key: 'compras', label: 'Compras', href: route(`${prefix.value}.compras.index`, slug.value) },
    { key: 'productos-compra', label: 'Productos de compra', href: route(`${prefix.value}.productos-compra.index`, slug.value) },
    { key: 'proveedores', label: 'Proveedores', href: route(`${prefix.value}.proveedores.index`, slug.value) },
]);
</script>

<template>
    <nav class="mb-5 flex gap-6 overflow-x-auto border-b border-gray-200" aria-label="Secciones de compras">
        <Link v-for="tab in tabs" :key="tab.key" :href="tab.href"
            class="-mb-px whitespace-nowrap border-b-2 pb-2.5 text-sm font-semibold transition"
            :class="tab.key === props.active
                ? 'border-gray-900 text-gray-900'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'">
            {{ tab.label }}
        </Link>
    </nav>
</template>
