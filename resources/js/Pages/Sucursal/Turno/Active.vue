<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CierreTurnoPanel from '@/Components/Turno/CierreTurnoPanel.vue';
import WithdrawalsPanel from '@/Components/Turno/WithdrawalsPanel.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});
</script>

<template>
    <Head title="Turno Activo" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Turno Activo</h1>
        </template>

        <CierreTurnoPanel
            :shift="shift"
            :totals="totals"
            :tenant="tenant"
            :payment-methods="paymentMethods"
            close-route-name="sucursal.turno.close">
            <template #extra>
                <WithdrawalsPanel
                    :withdrawals="shift.withdrawals"
                    store-route-name="sucursal.turno.withdrawal.store"
                    destroy-route-name="sucursal.turno.withdrawal.destroy"
                    :tenant-slug="tenant.slug" />
            </template>
        </CierreTurnoPanel>

        <FlashToast />
    </SucursalLayout>
</template>
