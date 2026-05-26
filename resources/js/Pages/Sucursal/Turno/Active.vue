<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import CierreTurnoPanel from '@/Components/Turno/CierreTurnoPanel.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
const money = (n) => '$' + (Number(n) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// --- Retiros de efectivo (solo sucursal) ---
const showWithdrawal = ref(false);
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
                <!-- ─── Retiros de efectivo ─── -->
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/70">
                    <div class="flex items-center justify-between px-5 py-4 sm:px-6">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-rose-100 text-rose-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75 14.25 12m0 0 2.25 2.25M14.25 12l2.25-2.25M14.25 12 12 14.25m-2.58 4.92-6.374-6.375a1.125 1.125 0 0 1 0-1.59L9.42 4.83c.21-.211.497-.33.795-.33H19.5a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-9.284c-.298 0-.585-.119-.795-.33Z" /></svg>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">Retiros de efectivo</h2>
                                <p class="text-xs text-gray-400">Salidas de caja durante el turno</p>
                            </div>
                        </div>
                        <button @click="showWithdrawal = !showWithdrawal" type="button"
                            class="rounded-xl px-3 py-1.5 text-sm font-semibold transition"
                            :class="showWithdrawal ? 'text-gray-500 hover:bg-gray-100' : 'bg-slate-900 text-white hover:bg-slate-800'">
                            {{ showWithdrawal ? 'Cancelar' : '+ Registrar retiro' }}
                        </button>
                    </div>

                    <div v-if="showWithdrawal" class="border-t border-gray-100 bg-gray-50/60 px-5 py-4 sm:px-6">
                        <form @submit.prevent="submitWithdrawal" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="sm:w-36">
                                <label class="text-xs font-semibold text-gray-500">Monto</label>
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                    <input v-model="withdrawalForm.amount" type="number" step="0.01" min="0.01" required placeholder="0.00"
                                        class="block w-full rounded-xl border-gray-200 pl-7 font-mono text-sm tabular-nums focus:border-slate-400 focus:ring-slate-300/60" />
                                </div>
                            </div>
                            <div class="flex-1">
                                <label class="text-xs font-semibold text-gray-500">Motivo</label>
                                <input v-model="withdrawalForm.reason" type="text" required placeholder="Ej. Compra de bolsas"
                                    class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-slate-400 focus:ring-slate-300/60" />
                            </div>
                            <button type="submit" :disabled="withdrawalForm.processing"
                                class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-50">Registrar</button>
                        </form>
                    </div>

                    <div class="divide-y divide-gray-100">
                        <div v-for="w in shift.withdrawals" :key="w.id" class="flex items-center justify-between px-5 py-3 sm:px-6">
                            <div>
                                <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(w.amount) }}</p>
                                <p class="text-xs text-gray-400">{{ w.reason }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-400">{{ formatTime(w.created_at) }}</span>
                                <button @click="deleteWithdrawal(w.id)" type="button" class="text-xs font-medium text-gray-400 transition hover:text-rose-600">Eliminar</button>
                            </div>
                        </div>
                        <div v-if="!shift.withdrawals || shift.withdrawals.length === 0" class="px-6 py-6 text-center text-sm text-gray-400">Sin retiros registrados.</div>
                    </div>
                </div>
            </template>
        </CierreTurnoPanel>

        <FlashToast />
    </SucursalLayout>
</template>
