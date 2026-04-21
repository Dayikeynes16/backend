<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    shift: Object,
    totals: Object,
    tenant: Object,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

// --- Withdrawal management ---
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

// --- Close form ---
const closeForm = useForm({
    declared_amount: '',
    declared_card: '',
    declared_transfer: '',
    notes: '',
});

const showConfirm = ref(false);

const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
const formatDuration = (iso) => {
    const ms = Date.now() - new Date(iso).getTime();
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
};

// --- Conciliation ---
const ALL_METHODS = [
    { key: 'cash', label: 'Efectivo', sublabel: 'Cuenta el efectivo físico en caja', icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z',
      color: 'emerald', field: 'declared_amount' },
    { key: 'card', label: 'Tarjeta', sublabel: 'Verifica el total en tu terminal', icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
      color: 'blue', field: 'declared_card' },
    { key: 'transfer', label: 'Transferencia', sublabel: 'Confirma en tu banca móvil', icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
      color: 'violet', field: 'declared_transfer' },
];

// Se muestran los métodos habilitados en la sucursal + cualquiera con
// movimientos durante el turno (si se desactivó a mitad y hay dinero que
// conciliar). 'cash' siempre está — hay fondo inicial que cuadrar.
const enabledMethods = computed(() => {
    const enabled = new Set(props.paymentMethods ?? ['cash', 'card', 'transfer']);
    enabled.add('cash');
    if ((props.totals?.card ?? 0) > 0) enabled.add('card');
    if ((props.totals?.transfer ?? 0) > 0) enabled.add('transfer');
    return ALL_METHODS.filter(m => enabled.has(m.key));
});

const expectedFor = (key) => {
    if (key === 'cash') return props.totals.expected_cash;
    return props.totals[key];
};

const diffFor = (key) => {
    const method = ALL_METHODS.find(m => m.key === key);
    if (!method) return null;
    const declared = parseFloat(closeForm[method.field]);
    if (isNaN(declared)) return null;
    return Math.round((declared - expectedFor(key)) * 100) / 100;
};

const diffStatus = (key) => {
    const d = diffFor(key);
    if (d === null) return 'pending';
    if (d === 0) return 'ok';
    return d > 0 ? 'surplus' : 'shortage';
};

const totalDiff = computed(() => {
    let sum = 0;
    let allComplete = true;
    for (const m of enabledMethods.value) {
        const d = diffFor(m.key);
        if (d === null) { allComplete = false; continue; }
        sum += d;
    }
    return allComplete ? Math.round(sum * 100) / 100 : null;
});

const allFilled = computed(() =>
    enabledMethods.value.every(m => closeForm[m.field] !== '' && !isNaN(parseFloat(closeForm[m.field])))
);

const hasDifference = computed(() => totalDiff.value !== null && totalDiff.value !== 0);

const methodVisibleInSummary = (key) => enabledMethods.value.some(m => m.key === key);
const summaryGridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${1 + enabledMethods.value.length}, minmax(0, 1fr))`,
}));

const handleSubmit = () => {
    if (hasDifference.value && !showConfirm.value) {
        showConfirm.value = true;
        return;
    }
    closeForm.post(route('sucursal.turno.close', props.tenant.slug));
};

const colorMap = {
    emerald: { iconBg: 'bg-emerald-100 text-emerald-600', ringActive: 'focus:border-emerald-400 focus:ring-emerald-300', text: 'text-emerald-600', bg: 'bg-emerald-50' },
    blue:    { iconBg: 'bg-blue-100 text-blue-600', ringActive: 'focus:border-blue-400 focus:ring-blue-300', text: 'text-blue-600', bg: 'bg-blue-50' },
    violet:  { iconBg: 'bg-violet-100 text-violet-600', ringActive: 'focus:border-violet-400 focus:ring-violet-300', text: 'text-violet-600', bg: 'bg-violet-50' },
};
</script>

<template>
    <Head title="Turno Activo" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-gray-900">Turno Activo</h1>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700 ring-1 ring-inset ring-green-600/20">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse" />
                        Activo
                    </span>
                    <span class="text-sm text-gray-400">desde {{ formatTime(shift.opened_at) }} · {{ formatDuration(shift.opened_at) }}</span>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">

            <!-- ─── Shift summary ─── -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="grid divide-x divide-gray-100" :style="summaryGridStyle">
                    <div class="px-5 py-4 text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Ventas</p>
                        <p class="mt-1 font-mono text-2xl font-extrabold tabular-nums text-gray-900">{{ totals.payment_count }}</p>
                    </div>
                    <div v-if="methodVisibleInSummary('cash')" class="px-5 py-4 text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-emerald-500">Efectivo</p>
                        <p class="mt-1 font-mono text-lg font-bold tabular-nums text-emerald-600">${{ totals.cash.toFixed(2) }}</p>
                    </div>
                    <div v-if="methodVisibleInSummary('card')" class="px-5 py-4 text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-blue-500">Tarjeta</p>
                        <p class="mt-1 font-mono text-lg font-bold tabular-nums text-blue-600">${{ totals.card.toFixed(2) }}</p>
                    </div>
                    <div v-if="methodVisibleInSummary('transfer')" class="px-5 py-4 text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-violet-500">Transferencia</p>
                        <p class="mt-1 font-mono text-lg font-bold tabular-nums text-violet-600">${{ totals.transfer.toFixed(2) }}</p>
                    </div>
                </div>
                <div class="border-t border-gray-100 px-5 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-4 text-xs text-gray-400">
                        <span>Fondo: <span class="font-semibold text-gray-600">${{ parseFloat(shift.opening_amount).toFixed(2) }}</span></span>
                        <span>Retiros: <span class="font-semibold text-red-500">-${{ totals.withdrawals.toFixed(2) }}</span></span>
                    </div>
                    <p class="text-sm font-bold text-gray-900">Total: <span class="font-mono tabular-nums">${{ totals.total.toFixed(2) }}</span></p>
                </div>
            </div>

            <!-- ─── Withdrawals ─── -->
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
                            <span class="text-xs text-gray-400">{{ formatTime(w.created_at) }}</span>
                            <button @click="deleteWithdrawal(w.id)" class="text-xs text-gray-400 hover:text-red-600">Eliminar</button>
                        </div>
                    </div>
                    <div v-if="!shift.withdrawals || shift.withdrawals.length === 0" class="px-6 py-6 text-center text-sm text-gray-400">Sin retiros registrados.</div>
                </div>
            </div>

            <!-- ─── Conciliation ─── -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100 overflow-hidden">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Conciliación de cierre</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Declara los montos reales por cada método de pago</p>
                </div>

                <form @submit.prevent="handleSubmit" class="divide-y divide-gray-100">
                    <div v-for="m in enabledMethods" :key="m.key" class="px-6 py-5">
                        <div class="flex items-start gap-4">
                            <div :class="[colorMap[m.color].iconBg, 'mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl']">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="m.icon" /></svg>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 :class="[colorMap[m.color].text, 'text-sm font-bold']">{{ m.label }}</h3>
                                        <p class="text-xs text-gray-400">{{ m.sublabel }}</p>
                                    </div>
                                    <span v-if="diffStatus(m.key) === 'ok'" class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-bold text-green-700 ring-1 ring-inset ring-green-600/20">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Cuadra
                                    </span>
                                    <span v-else-if="diffStatus(m.key) === 'surplus'" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-500/20">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" /></svg>
                                        Sobrante
                                    </span>
                                    <span v-else-if="diffStatus(m.key) === 'shortage'" class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700 ring-1 ring-inset ring-red-600/20">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" /></svg>
                                        Faltante
                                    </span>
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div :class="[colorMap[m.color].bg, 'rounded-lg px-3 py-2.5 text-center']">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Esperado</p>
                                        <p :class="['font-mono text-base font-bold tabular-nums', colorMap[m.color].text]">${{ expectedFor(m.key).toFixed(2) }}</p>
                                        <template v-if="m.key === 'cash'">
                                            <p class="mt-1 text-[9px] text-gray-400 leading-tight">
                                                ${{ parseFloat(shift.opening_amount).toFixed(0) }} fondo
                                                + ${{ totals.cash.toFixed(0) }} cobrado
                                                <template v-if="totals.withdrawals > 0"> - ${{ totals.withdrawals.toFixed(0) }} retiros</template>
                                            </p>
                                        </template>
                                    </div>

                                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Declarado</p>
                                        <div class="relative mt-1">
                                            <span class="absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400">$</span>
                                            <input v-model="closeForm[m.field]" type="number" step="0.01" min="0" required
                                                placeholder="0.00"
                                                :class="['block w-full rounded-lg border-gray-200 bg-white py-1.5 pl-5 pr-2 text-center font-mono text-base font-bold tabular-nums text-gray-900', colorMap[m.color].ringActive]" />
                                        </div>
                                    </div>

                                    <div class="rounded-lg px-3 py-2.5 text-center"
                                        :class="diffStatus(m.key) === 'ok' ? 'bg-green-50' : diffStatus(m.key) === 'surplus' ? 'bg-amber-50' : diffStatus(m.key) === 'shortage' ? 'bg-red-50' : 'bg-gray-50'">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Diferencia</p>
                                        <p class="font-mono text-base font-bold tabular-nums"
                                            :class="diffFor(m.key) === null ? 'text-gray-300' : diffFor(m.key) === 0 ? 'text-green-600' : diffFor(m.key) > 0 ? 'text-amber-600' : 'text-red-600'">
                                            <template v-if="diffFor(m.key) === null">—</template>
                                            <template v-else>{{ diffFor(m.key) > 0 ? '+' : '' }}${{ diffFor(m.key).toFixed(2) }}</template>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ─── Total difference & CTA ─── -->
                    <div class="px-6 py-5 space-y-4">
                        <div class="rounded-xl px-5 py-4"
                            :class="totalDiff === null ? 'bg-gray-50 ring-1 ring-gray-100' : totalDiff === 0 ? 'bg-green-50 ring-1 ring-green-200' : 'bg-red-50 ring-1 ring-red-200'">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <svg v-if="totalDiff === 0" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                    <svg v-else-if="totalDiff !== null" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                    <p class="text-xs font-bold uppercase tracking-wider"
                                        :class="totalDiff === null ? 'text-gray-400' : totalDiff === 0 ? 'text-green-700' : 'text-red-700'">
                                        Diferencia total del turno
                                    </p>
                                </div>
                                <p class="font-mono text-xl font-extrabold tabular-nums"
                                    :class="totalDiff === null ? 'text-gray-300' : totalDiff === 0 ? 'text-green-600' : 'text-red-600'">
                                    <template v-if="totalDiff === null">—</template>
                                    <template v-else>{{ totalDiff > 0 ? '+' : '' }}${{ totalDiff.toFixed(2) }}</template>
                                </p>
                            </div>
                        </div>

                        <div v-if="hasDifference">
                            <label class="text-xs font-medium text-gray-500">Observaciones <span class="text-gray-300">(opcional)</span></label>
                            <textarea v-model="closeForm.notes" rows="2" placeholder="Explica las diferencias encontradas..."
                                class="mt-1.5 block w-full rounded-lg border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                        </div>

                        <div v-if="showConfirm" class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-4">
                            <div class="flex gap-3">
                                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                <div>
                                    <p class="text-sm font-bold text-amber-800">Hay diferencias en tu cierre</p>
                                    <p class="mt-0.5 text-xs text-amber-700">El turno se cerrará con una diferencia de <span class="font-bold font-mono">${{ totalDiff?.toFixed(2) }}</span>. Esta información quedará registrada.</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-1">
                            <button type="submit" :disabled="closeForm.processing || !allFilled"
                                :class="['rounded-xl px-8 py-3 text-sm font-bold transition',
                                    showConfirm
                                        ? 'bg-amber-500 text-white hover:bg-amber-600 disabled:opacity-50'
                                        : 'bg-red-600 text-white hover:bg-red-700 disabled:opacity-50']">
                                {{ showConfirm ? 'Confirmar y cerrar turno' : 'Cerrar turno y generar corte' }}
                            </button>
                            <button v-if="showConfirm" type="button" @click="showConfirm = false" class="text-sm text-gray-500 hover:text-gray-700">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
