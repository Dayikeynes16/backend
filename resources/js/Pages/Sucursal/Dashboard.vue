<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { localToday } from '@/utils/date';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    totals: Object,
    hoursData: Array,
    yesterdayHoursData: Array,
    paymentMethods: Array,
    topProducts: Array,
    recentShifts: Array,
    pendingCount: Number,
    cancelRequestCount: Number,
    productCount: Number,
    cajeroCount: Number,
    activeCashierCount: Number,
    expenses: Object,
    selectedDate: String,
    tenant: Object,
});

const date = ref(props.selectedDate || localToday());

watch(date, (v) => {
    router.get(route('sucursal.dashboard', props.tenant.slug), { date: v || undefined }, { preserveState: true, replace: true });
});
</script>

<template>
    <Head title="Dashboard" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
                <div class="flex items-center gap-3">
                    <DatePicker v-model="date" />
                    <span class="hidden rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700 sm:inline-flex">Admin Sucursal</span>
                </div>
            </div>
        </template>

        <DashboardOverview
            context="sucursal"
            :totals="totals"
            :hours-data="hoursData"
            :yesterday-hours-data="yesterdayHoursData"
            :payment-methods="paymentMethods"
            :top-products="topProducts"
            :recent-shifts="recentShifts"
            :pending-count="pendingCount"
            :cancel-request-count="cancelRequestCount"
            :product-count="productCount"
            :cajero-count="cajeroCount"
            :active-cashier-count="activeCashierCount"
            :expenses="expenses"
            :tenant="tenant"
            cancelaciones-route-name="sucursal.cancelaciones.index"
            productos-route-name="sucursal.productos.index"
            usuarios-route-name="sucursal.usuarios.index"
            cortes-route-name="sucursal.cortes.index"
            config-route-name="sucursal.configuracion"
            gastos-route-name="sucursal.gastos.index" />
    </SucursalLayout>
</template>
