<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    totals: Object,
    branches: Array,
    branchCount: Number,
    userCount: Number,
    pendingCount: Number,
    tenant: Object,
    selectedBranch: Number,
});

const branchFilter = ref(props.selectedBranch || '');

const filterByBranch = () => {
    router.get(route('empresa.dashboard', props.tenant.slug), {
        branch_id: branchFilter.value || undefined,
    }, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="Dashboard" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
        </template>

        <div class="space-y-8">
            <!-- Branch selector -->
            <div class="flex items-center gap-3">
                <label for="branch_filter" class="text-sm font-medium text-gray-600">Sucursal:</label>
                <select id="branch_filter" v-model="branchFilter" @change="filterByBranch"
                    class="rounded-lg border-gray-200 py-2 pl-3 pr-8 text-sm text-gray-700 focus:border-red-400 focus:ring-red-300">
                    <option value="">Todas las sucursales</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-5 lg:grid-cols-5">
                <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 shadow-sm">
                    <p class="text-2xl font-bold text-gray-900">${{ totals.total_sales.toFixed(2) }}</p>
                    <p class="text-xs font-medium text-gray-500">Ventas hoy</p>
                </div>
                <div class="rounded-xl border-l-4 border-orange-500 bg-white p-5 shadow-sm">
                    <p class="text-2xl font-bold text-gray-900">{{ totals.sale_count }}</p>
                    <p class="text-xs font-medium text-gray-500">Transacciones</p>
                </div>
                <div class="rounded-xl border-l-4 border-amber-500 bg-white p-5 shadow-sm">
                    <p class="text-2xl font-bold text-orange-600">{{ pendingCount }}</p>
                    <p class="text-xs font-medium text-gray-500">Pendientes</p>
                </div>
                <div class="rounded-xl border-l-4 border-green-500 bg-white p-5 shadow-sm">
                    <p class="text-2xl font-bold text-gray-900">{{ branchCount }}</p>
                    <p class="text-xs font-medium text-gray-500">Sucursales</p>
                </div>
                <div class="rounded-xl border-l-4 border-emerald-500 bg-white p-5 shadow-sm">
                    <p class="text-2xl font-bold text-gray-900">{{ userCount }}</p>
                    <p class="text-xs font-medium text-gray-500">Usuarios</p>
                </div>
            </div>

            <!-- Payment breakdown -->
            <div class="grid grid-cols-3 gap-5">
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-green-600">Efectivo</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">${{ totals.total_cash.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Tarjeta</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">${{ totals.total_card.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-xs font-bold uppercase tracking-wider text-purple-600">Transferencia</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">${{ totals.total_transfer.toFixed(2) }}</p>
                </div>
            </div>

            <!-- Branch table -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ventas por sucursal (hoy)</h2>
                </div>
                <table class="min-w-full divide-y divide-gray-100">
                    <thead><tr class="bg-gray-50">
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Sucursal</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Estado</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Ventas</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="b in branches" :key="b.id" class="transition hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ b.name }}</td>
                            <td class="px-6 py-4"><span :class="b.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ b.status === 'active' ? 'Activa' : 'Inactiva' }}</span></td>
                            <td class="px-6 py-4 text-right text-sm text-gray-600">{{ b.sale_count }}</td>
                            <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900">${{ b.total.toFixed(2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <FlashToast />
    </EmpresaLayout>
</template>
