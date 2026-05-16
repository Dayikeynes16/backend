<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    customer: Object,
    statsSeed: Object,
    products: Array,
    tenant: Object,
    allowedPaymentMethods: Array,
});
</script>

<template>
    <Head :title="customer.name" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">{{ customer.name }}</h1>
        </template>
        <p class="text-sm text-gray-500">Página de detalle en construcción.</p>
    </SucursalLayout>
</template>
