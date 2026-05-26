<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CierreTurnoPanel from '@/Components/Turno/CierreTurnoPanel.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});
</script>

<template>
    <Head title="Mi Turno" />
    <CajeroLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Mi Turno</h1>
        </template>

        <CierreTurnoPanel
            :shift="shift"
            :totals="totals"
            :tenant="tenant"
            :payment-methods="paymentMethods"
            close-route-name="caja.turno.close" />

        <FlashToast />
    </CajeroLayout>
</template>
