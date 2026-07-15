<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import ComprasTabs from '@/Components/Compras/ComprasTabs.vue';
import ProviderDetail from '@/Components/Proveedores/ProviderDetail.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    provider: { type: Object, required: true },
    seed: { type: Object, default: () => ({}) },
    purchaseProducts: { type: Array, default: () => [] },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
</script>

<template>
    <Head :title="provider.name" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('sucursal.proveedores.index', slug)" class="text-sm text-orange-700 hover:underline">← Proveedores</Link>
                <h1 class="text-lg font-bold text-gray-900">{{ provider.name }}</h1>
            </div>
        </template>

        <ComprasTabs active="proveedores" />

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="sucursal" :can-register-payment="true" />
    </SucursalLayout>
</template>
