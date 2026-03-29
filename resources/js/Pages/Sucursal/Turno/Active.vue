<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ shift: Object, totals: Object, tenant: Object });

const showWithdrawal = ref(false);
const showClose = ref(false);

const withdrawalForm = useForm({ amount: '', reason: '' });
const submitWithdrawal = () => {
    withdrawalForm.post(route('sucursal.turno.withdrawal.store', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => { withdrawalForm.reset(); showWithdrawal.value = false; },
    });
};

const deleteWithdrawal = (id) => {
    if (confirm('¿Eliminar este retiro?')) {
        router.delete(route('sucursal.turno.withdrawal.destroy', [props.tenant.slug, id]), { preserveScroll: true });
    }
};

const closeForm = useForm({ declared_amount: '' });
const submitClose = () => {
    closeForm.post(route('sucursal.turno.close', props.tenant.slug));
};

const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Turno Activo" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Turno Activo</h1>
                <span class="text-xs text-gray-400">Desde {{ formatTime(shift.opened_at) }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-8">
            <!-- Metrics -->
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
                    <p class="text-xs font-medium text-gray-500">Total cobrado</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">${{ totals.total.toFixed(2) }}</p>
                </div>
            </div>

            <!-- Cash expected -->
            <div class="rounded-xl bg-gradient-to-br from-orange-50/60 to-amber-50/40 p-6 ring-1 ring-orange-200/60">
                <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
                    <div>
                        <p class="text-xs font-medium text-gray-500">Fondo inicial</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">${{ parseFloat(shift.opening_amount).toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500">Efectivo cobrado</p>
                        <p class="mt-1 text-lg font-bold text-green-600">${{ totals.cash.toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500">Retiros</p>
                        <p class="mt-1 text-lg font-bold text-red-600">-${{ totals.withdrawals.toFixed(2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500">Efectivo esperado</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">${{ totals.expected_cash.toFixed(2) }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-400">{{ totals.payment_count }} venta{{ totals.payment_count !== 1 ? 's' : '' }} registrada{{ totals.payment_count !== 1 ? 's' : '' }}</p>
            </div>

            <!-- Withdrawals -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Retiros de efectivo</h2>
                    <button @click="showWithdrawal = !showWithdrawal" class="text-sm font-semibold text-red-600 hover:text-red-700">
                        {{ showWithdrawal ? 'Cancelar' : '+ Registrar retiro' }}
                    </button>
                </div>

                <div v-if="showWithdrawal" class="border-b border-gray-100 px-6 py-4">
                    <form @submit.prevent="submitWithdrawal" class="flex items-end gap-3">
                        <div class="w-32">
                            <label class="text-xs font-medium text-gray-500">Monto</label>
                            <div class="relative mt-1">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                <input v-model="withdrawalForm.amount" type="number" step="0.01" min="0.01" required placeholder="0.00" class="block w-full rounded-lg border-gray-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                        </div>
                        <div class="flex-1">
                            <label class="text-xs font-medium text-gray-500">Motivo</label>
                            <input v-model="withdrawalForm.reason" type="text" required placeholder="Ej: Compra de bolsas" class="mt-1 block w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <button type="submit" :disabled="withdrawalForm.processing" class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Registrar</button>
                    </form>
                </div>

                <div class="divide-y divide-gray-50">
                    <div v-for="w in shift.withdrawals" :key="w.id" class="flex items-center justify-between px-6 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">${{ parseFloat(w.amount).toFixed(2) }}</p>
                            <p class="text-xs text-gray-400">{{ w.reason }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400">{{ new Date(w.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) }}</span>
                            <button @click="deleteWithdrawal(w.id)" class="text-xs text-gray-400 hover:text-red-600">Eliminar</button>
                        </div>
                    </div>
                    <div v-if="!shift.withdrawals || shift.withdrawals.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">Sin retiros registrados.</div>
                </div>
            </div>

            <!-- Close shift -->
            <div class="rounded-xl border-2 border-red-200 bg-red-50">
                <div class="px-6 py-5">
                    <h2 class="text-base font-bold text-red-900">Cerrar Turno</h2>
                    <p class="mt-1 text-sm text-red-600/80">Cuenta tu efectivo fisico y declaralo para generar el corte.</p>
                </div>
                <div class="border-t border-red-200 px-6 py-5">
                    <div v-if="!showClose">
                        <button @click="showClose = true" class="rounded-lg border-2 border-red-300 bg-white px-6 py-2.5 text-sm font-bold text-red-700 transition hover:bg-red-50">
                            Iniciar cierre de turno
                        </button>
                    </div>
                    <form v-else @submit.prevent="submitClose" class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-red-900">¿Cuanto efectivo tienes fisicamente?</label>
                            <div class="relative mt-1.5">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-red-400">$</span>
                                <input v-model="closeForm.declared_amount" type="number" step="0.01" min="0" required placeholder="0.00" autofocus
                                    class="block w-full rounded-lg border-red-200 pl-7 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <p class="mt-1.5 text-xs text-red-600/70">Efectivo esperado: <span class="font-bold">${{ totals.expected_cash.toFixed(2) }}</span></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" :disabled="closeForm.processing" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">
                                Cerrar turno y generar corte
                            </button>
                            <button type="button" @click="showClose = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
