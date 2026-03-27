<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ shift: Object, totals: Object, tenant: Object });
const showClose = ref(false);
const closeForm = useForm({ declared_amount: '' });
const submitClose = () => closeForm.post(route('caja.turno.close', props.tenant.slug));
const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Mi Turno" />
    <CajeroLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Mi Turno</h1>
                <span class="text-xs text-gray-400">Desde {{ formatTime(shift.opened_at) }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-8">
            <div class="grid grid-cols-2 gap-5 lg:grid-cols-4">
                <div class="rounded-xl border-l-4 border-green-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Efectivo</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ totals.cash.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-blue-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Tarjeta</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ totals.card.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-purple-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Transferencia</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ totals.transfer.toFixed(2) }}</p>
                </div>
                <div class="rounded-xl border-l-4 border-red-500 bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-gray-500">Total</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ totals.total.toFixed(2) }}</p>
                </div>
            </div>

            <div class="rounded-xl bg-gradient-to-br from-orange-50/60 to-amber-50/40 p-6 ring-1 ring-orange-200/60">
                <div class="grid grid-cols-3 gap-6">
                    <div><p class="text-xs text-gray-500">Fondo</p><p class="mt-1 text-lg font-bold text-gray-900">${{ parseFloat(shift.opening_amount).toFixed(2) }}</p></div>
                    <div><p class="text-xs text-gray-500">Retiros</p><p class="mt-1 text-lg font-bold text-red-600">-${{ totals.withdrawals.toFixed(2) }}</p></div>
                    <div><p class="text-xs text-gray-500">Efectivo esperado</p><p class="mt-1 text-lg font-bold text-gray-900">${{ totals.expected_cash.toFixed(2) }}</p></div>
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ totals.payment_count }} cobro{{ totals.payment_count !== 1 ? 's' : '' }}</p>
            </div>

            <div class="rounded-xl border-2 border-red-200 bg-red-50">
                <div class="px-6 py-5">
                    <h2 class="text-base font-bold text-red-900">Cerrar Turno</h2>
                    <p class="mt-1 text-sm text-red-600/80">Cuenta tu efectivo y declaralo.</p>
                </div>
                <div class="border-t border-red-200 px-6 py-5">
                    <div v-if="!showClose">
                        <button @click="showClose = true" class="rounded-lg border-2 border-red-300 bg-white px-6 py-2.5 text-sm font-bold text-red-700 transition hover:bg-red-50">Iniciar cierre</button>
                    </div>
                    <form v-else @submit.prevent="submitClose" class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-red-900">Efectivo fisico en caja</label>
                            <div class="relative mt-1.5"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-red-400">$</span>
                            <input v-model="closeForm.declared_amount" type="number" step="0.01" min="0" required autofocus placeholder="0.00" class="block w-full rounded-lg border-red-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" /></div>
                            <p class="mt-1 text-xs text-red-600/70">Esperado: <span class="font-bold">${{ totals.expected_cash.toFixed(2) }}</span></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" :disabled="closeForm.processing" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Cerrar turno</button>
                            <button type="button" @click="showClose = false" class="text-sm text-gray-500">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <FlashToast />
    </CajeroLayout>
</template>
