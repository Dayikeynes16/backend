<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    shift: Object,
    tenant: Object,
    isAdmin: Boolean,
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
});

const formatDT = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

const recalculating = ref(false);
const confirmReopen = ref(false);

const recalculate = () => {
    recalculating.value = true;
    router.post(route('sucursal.cortes.recalculate', [props.tenant.slug, props.shift.id]), {}, {
        preserveScroll: true,
        onFinish: () => { recalculating.value = false; },
    });
};

const doReopen = () => {
    router.post(route('sucursal.cortes.reopen', [props.tenant.slug, props.shift.id]), {}, {
        onFinish: () => { confirmReopen.value = false; },
    });
};

const diffLabel = (val) => {
    const n = Number(val);
    if (n > 0) return 'Sobrante';
    if (n < 0) return 'Faltante';
    return 'Sin diferencia';
};
const diffColor = (val) => {
    const n = Number(val);
    if (n > 0) return 'text-amber-600';
    if (n < 0) return 'text-red-600';
    return 'text-green-600';
};

// Para un corte HISTÓRICO mostramos solo los métodos que efectivamente se
// declararon (declared_* no es NULL) o que tuvieron movimientos (total_* > 0).
// Así respetamos el dato original: si transferencia se desactivó DESPUÉS del
// cierre pero el corte la tenía, la seguimos mostrando.
const ALL_METHODS = [
    { key: 'cash', label: 'Efectivo', color: 'emerald', iconBg: 'bg-emerald-100 text-emerald-600', textColor: 'text-emerald-600',
      declaredField: 'declared_amount', diffField: 'difference', expectedField: 'expected_amount', totalField: 'total_cash',
      icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    { key: 'card', label: 'Tarjeta', color: 'blue', iconBg: 'bg-blue-100 text-blue-600', textColor: 'text-blue-600',
      declaredField: 'declared_card', diffField: 'difference_card', expectedField: 'total_card', totalField: 'total_card',
      icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    { key: 'transfer', label: 'Transferencia', color: 'violet', iconBg: 'bg-violet-100 text-violet-600', textColor: 'text-violet-600',
      declaredField: 'declared_transfer', diffField: 'difference_transfer', expectedField: 'total_transfer', totalField: 'total_transfer',
      icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
];

const conciliation = computed(() => {
    return ALL_METHODS
        .filter(m => {
            const declared = props.shift[m.declaredField];
            const total = Number(props.shift[m.totalField] ?? 0);
            // Mostrar si se declaró el método (no null) o si hubo movimientos.
            return declared !== null && declared !== undefined ? true : total > 0;
        })
        .map(m => ({
            ...m,
            expected: Number(props.shift[m.expectedField] ?? 0),
            declared: Number(props.shift[m.declaredField] ?? props.shift[m.totalField] ?? 0),
            diff: Number(props.shift[m.diffField] ?? 0),
        }));
});

const totalDiff = computed(() => conciliation.value.reduce((sum, m) => sum + m.diff, 0));
</script>

<template>
    <Head title="Detalle de Corte" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-sm">
                    <Link :href="route('sucursal.cortes.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Cortes</Link>
                    <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    <span class="font-bold text-gray-900">Corte de {{ shift.user?.name }}</span>
                </div>
                <div v-if="isAdmin" class="flex items-center gap-2">
                    <button @click="recalculate" :disabled="recalculating"
                        class="rounded-lg bg-orange-100 px-3 py-1.5 text-xs font-semibold text-orange-700 transition hover:bg-orange-200 disabled:opacity-50">
                        {{ recalculating ? 'Recalculando...' : 'Recalcular totales' }}
                    </button>
                    <button @click="confirmReopen = true"
                        class="rounded-lg bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-200">
                        Reabrir turno
                    </button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Header info -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="grid grid-cols-4 divide-x divide-gray-100">
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Cajero</p>
                        <p class="mt-1 text-sm font-bold text-gray-900">{{ shift.user?.name }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Apertura</p>
                        <p class="mt-1 text-sm text-gray-700">{{ formatDT(shift.opened_at) }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Cierre</p>
                        <p class="mt-1 text-sm text-gray-700">{{ formatDT(shift.closed_at) }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Ventas</p>
                        <p class="mt-1 font-mono text-xl font-extrabold tabular-nums text-gray-900">{{ shift.sale_count }}</p>
                    </div>
                </div>
                <div class="border-t border-gray-100 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs text-gray-400">Fondo inicial: <span class="font-semibold text-gray-600">${{ Number(shift.opening_amount).toFixed(2) }}</span></span>
                    <p class="text-sm font-bold text-gray-900">Total cobrado: <span class="font-mono tabular-nums">${{ Number(shift.total_sales).toFixed(2) }}</span></p>
                </div>
            </div>

            <!-- Conciliation by method -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100 overflow-hidden">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Conciliación por método</h2>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="m in conciliation" :key="m.key" class="px-6 py-5">
                        <div class="flex items-start gap-4">
                            <div :class="[m.iconBg, 'mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl']">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="m.icon" /></svg>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 :class="[m.textColor, 'text-sm font-bold']">{{ m.label }}</h3>
                                    <span v-if="m.diff === 0" class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-bold text-green-700 ring-1 ring-inset ring-green-600/20">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Cuadra
                                    </span>
                                    <span v-else-if="m.diff > 0" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-500/20">Sobrante</span>
                                    <span v-else class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700 ring-1 ring-inset ring-red-600/20">Faltante</span>
                                </div>
                                <div class="grid grid-cols-3 gap-3">
                                    <div :class="['rounded-lg px-3 py-2.5 text-center', m.key === 'cash' ? 'bg-emerald-50' : m.key === 'card' ? 'bg-blue-50' : 'bg-violet-50']">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Esperado</p>
                                        <p :class="['font-mono text-base font-bold tabular-nums', m.textColor]">${{ m.expected.toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Declarado</p>
                                        <p class="font-mono text-base font-bold tabular-nums text-gray-900">${{ m.declared.toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-lg px-3 py-2.5 text-center"
                                        :class="m.diff === 0 ? 'bg-green-50' : m.diff > 0 ? 'bg-amber-50' : 'bg-red-50'">
                                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Diferencia</p>
                                        <p class="font-mono text-base font-bold tabular-nums" :class="diffColor(m.diff)">
                                            {{ m.diff > 0 ? '+' : '' }}${{ m.diff.toFixed(2) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Total diff -->
                <div class="border-t border-gray-100 px-6 py-4">
                    <div class="rounded-xl px-5 py-4"
                        :class="totalDiff === 0 ? 'bg-green-50 ring-1 ring-green-200' : 'bg-red-50 ring-1 ring-red-200'">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <svg v-if="totalDiff === 0" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                <svg v-else class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                <p class="text-xs font-bold uppercase tracking-wider"
                                    :class="totalDiff === 0 ? 'text-green-700' : 'text-red-700'">
                                    Diferencia total del turno
                                </p>
                            </div>
                            <p class="font-mono text-xl font-extrabold tabular-nums" :class="diffColor(totalDiff)">
                                {{ totalDiff > 0 ? '+' : '' }}${{ totalDiff.toFixed(2) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="shift.notes" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <h3 class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Observaciones</h3>
                <p class="text-sm text-gray-700">{{ shift.notes }}</p>
            </div>

            <!-- Withdrawals -->
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
        </div>

        <ConfirmDialog v-if="confirmReopen"
            title="Reabrir turno"
            message="El turno se reabrira y el cajero podra seguir operando. Los totales del corte se eliminaran hasta que se cierre nuevamente."
            confirm-label="Reabrir"
            variant="danger"
            @confirm="doReopen"
            @cancel="confirmReopen = false" />

        <FlashToast />
    </SucursalLayout>
</template>
