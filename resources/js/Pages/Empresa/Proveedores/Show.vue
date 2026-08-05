<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
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
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('empresa.proveedores.index', slug)" class="text-sm text-orange-700 hover:underline">← Proveedores</Link>
                <h1 class="text-lg font-bold text-gray-900">{{ provider.name }}</h1>
            </div>
        </template>

        <ComprasTabs active="proveedores" />

        <ProviderDetail :provider="provider" :seed="seed" :purchase-products="purchaseProducts" route-prefix="empresa" :can-register-payment="true" />
    </EmpresaLayout>
</template>
