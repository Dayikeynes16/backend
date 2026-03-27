<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({ shift: Object, tenant: Object });

const formatDT = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const diffClass = Number(props.shift.difference) > 0 ? 'text-green-600' : Number(props.shift.difference) < 0 ? 'text-red-600' : 'text-gray-400';
const diffLabel = Number(props.shift.difference) > 0 ? 'Sobrante' : Number(props.shift.difference) < 0 ? 'Faltante' : 'Sin diferencia';
</script>

<template>
    <Head title="Detalle de Corte" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('sucursal.cortes.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Cortes</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">Corte de {{ shift.user?.name }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-8">
            <!-- Header -->
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><p class="text-xs text-gray-400">Cajero</p><p class="mt-1 text-sm font-bold text-gray-900">{{ shift.user?.name }}</p></div>
                    <div><p class="text-xs text-gray-400">Apertura</p><p class="mt-1 text-sm text-gray-700">{{ formatDT(shift.opened_at) }}</p></div>
                    <div><p class="text-xs text-gray-400">Cierre</p><p class="mt-1 text-sm text-gray-700">{{ formatDT(shift.closed_at) }}</p></div>
                </div>
            </div>

            <!-- Cobros por método -->
            <div class="grid grid-cols-2 gap-5 lg:grid-cols-4">
                <div class="rounded-xl border-l-4 border-green-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Efectivo</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ Number(shift.total_cash).toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-blue-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Tarjeta</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ Number(shift.total_card).toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-purple-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Transferencia</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ Number(shift.total_transfer).toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Total cobrado</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ Number(shift.total_sales).toFixed(2) }}</p>
                </div>
            </div>

            <!-- Corte de efectivo -->
            <div class="rounded-xl bg-gradient-to-br from-orange-50/60 to-amber-50/40 p-6 ring-1 ring-orange-200/60">
                <h2 class="mb-4 text-sm font-bold text-gray-900">Corte de Efectivo</h2>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs text-gray-500">Fondo inicial</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">${{ Number(shift.opening_amount).toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Efectivo esperado</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">${{ Number(shift.expected_amount).toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Efectivo declarado</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">${{ Number(shift.declared_amount).toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">{{ diffLabel }}</p>
                        <p class="mt-1 text-lg font-bold" :class="diffClass">
                            {{ Number(shift.difference) > 0 ? '+' : '' }}${{ Number(shift.difference).toFixed(2) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Retiros -->
            <div v-if="shift.withdrawals && shift.withdrawals.length > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Retiros de efectivo</h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <div v-for="w in shift.withdrawals" :key="w.id" class="flex items-center justify-between px-6 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">${{ Number(w.amount).toFixed(2) }}</p>
                            <p class="text-xs text-gray-400">{{ w.reason }}</p>
                        </div>
                        <span class="text-xs text-gray-400">{{ new Date(w.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) }}</span>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <p class="text-xs text-gray-400">{{ shift.sale_count }} cobro{{ shift.sale_count !== 1 ? 's' : '' }} registrado{{ shift.sale_count !== 1 ? 's' : '' }} durante este turno.</p>
            </div>
        </div>
    </SucursalLayout>
</template>
