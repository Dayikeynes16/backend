<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // 'empresa' | 'sucursal' — prefijo de los nombres de ruta Ziggy.
    prefix: { type: String, required: true },
});

const slug = computed(() => usePage().props.auth.tenant_slug);

const tabs = computed(() => [
    { label: 'Compras', route: `${props.prefix}.compras.index`, match: `${props.prefix}.compras` },
    { label: 'Proveedores', route: `${props.prefix}.proveedores.index`, match: `${props.prefix}.proveedores` },
    { label: 'Productos', route: `${props.prefix}.productos-compra.index`, match: `${props.prefix}.productos-compra` },
]);

const isActive = (tab) => route().current(tab.match + '*');
</script>

<template>
    <div class="overflow-x-auto border-b border-gray-200">
        <nav class="-mb-px flex gap-6" aria-label="Secciones de compras">
            <Link v-for="tab in tabs" :key="tab.route" :href="route(tab.route, slug)"
                :class="['shrink-0 whitespace-nowrap border-b-2 px-1 pb-3 pt-1 text-sm font-semibold transition',
                    isActive(tab)
                        ? 'border-red-600 text-red-600'
                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700']">
                {{ tab.label }}
            </Link>
        </nav>
    </div>
</template>
