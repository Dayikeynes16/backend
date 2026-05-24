<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import AgendaTodayWidget from '@/Components/Agenda/AgendaTodayWidget.vue';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview.vue';
import DatePicker from '@/Components/DatePicker.vue';
import StatusFilterChips from '@/Components/Metrics/StatusFilterChips.vue';
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
    selectedStatuses: { type: Array, default: () => ['completed'] },
    tenant: Object,
});

const date = ref(props.selectedDate || localToday());

// Adapter para reusar StatusFilterChips (espera filters.statuses.value y
// filters.toggleStatus). Sólo permitimos completed/pending en el dashboard.
const statuses = ref([...(props.selectedStatuses || ['completed'])]);
const statusFilters = {
    statuses,
    toggleStatus(key) {
        const has = statuses.value.includes(key);
        statuses.value = has
            ? statuses.value.filter(s => s !== key)
            : [...statuses.value, key];
    },
};

const reload = () => {
    router.get(
        route('sucursal.dashboard', props.tenant.slug),
        {
            date: date.value || undefined,
            statuses: statuses.value.length ? statuses.value : undefined,
        },
        { preserveState: true, replace: true },
    );
};

watch(date, reload);
watch(statuses, reload, { deep: true });
</script>

<template>
    <Head title="Dashboard" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <StatusFilterChips :filters="statusFilters" compact />
                    <DatePicker v-model="date" />
                </div>
                <!-- El chip "Admin Sucursal" lo pinta SucursalLayout en el header global; no se duplica aquí. -->
            </div>
        </template>

        <AgendaTodayWidget class="mb-6" :tenant-slug="tenant.slug" />

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
