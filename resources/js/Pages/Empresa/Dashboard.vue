<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
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
    selectedBranch: { type: Number, default: null },
    branches: Array,
    branchCount: Number,
    tenant: Object,
});

const date = ref(props.selectedDate || localToday());
const branchId = ref(props.selectedBranch || '');

const navigate = () => {
    router.get(route('empresa.dashboard', props.tenant.slug), {
        date: date.value || undefined,
        branch_id: branchId.value || undefined,
    }, { preserveState: true, replace: true });
};

watch(date, navigate);
watch(branchId, navigate);
</script>

<template>
    <Head title="Dashboard" />
    <EmpresaLayout>
        <template #header>
            <div class="flex flex-1 items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
                <div class="flex items-center gap-2">
                    <!-- Pill iOS para sucursal -->
                    <div class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-white px-1 py-1 ring-1 ring-gray-200 shadow-sm">
                        <svg class="ml-2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614" /></svg>
                        <select v-model="branchId"
                            class="h-8 rounded-lg border-0 bg-transparent pl-1 pr-7 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-0">
                            <option value="">Todas las sucursales</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <DatePicker v-model="date" />
                </div>
            </div>
        </template>

        <DashboardOverview
            context="empresa"
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
            :selected-branch-id="selectedBranch"
            :branches="branches"
            cancelaciones-route-name="sucursal.cancelaciones.index"
            productos-route-name="empresa.sucursales.index"
            usuarios-route-name="empresa.usuarios.index"
            cortes-route-name="sucursal.cortes.index"
            config-route-name="empresa.configuracion"
            gastos-route-name="empresa.gastos.index" />
    </EmpresaLayout>
</template>
