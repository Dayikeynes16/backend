<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    shift: { type: Object, required: true },
    totals: { type: Object, required: true },
    tenant: { type: Object, required: true },
    paymentMethods: { type: Array, default: () => ['cash', 'card', 'transfer'] },
    // Nombre de ruta Ziggy para cerrar el turno (difiere entre caja y sucursal).
    closeRouteName: { type: String, required: true },
});

const closeForm = useForm({
    declared_amount: '',
    declared_card: '',
    declared_transfer: '',
    notes: '',
});

const showConfirm = ref(false);

// --- Formato ---
const money = (n) => {
    const v = Math.abs(Number(n) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (Number(n) < 0 ? '−$' : '$') + v;
};
const signedMoney = (n) => (Number(n) > 0 ? '+' : '') + money(n);
const formatTime = (iso) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
const formatDuration = (iso) => {
    const ms = Date.now() - new Date(iso).getTime();
    const h = Math.floor(ms / 3600000);
    const m = Math.floor((ms % 3600000) / 60000);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
};

// --- Métodos de pago ---
const ALL_METHODS = [
    { key: 'cash', label: 'Efectivo', sublabel: 'Cuenta el efectivo físico en caja', color: 'emerald', field: 'declared_amount',
      icon: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z' },
    { key: 'card', label: 'Tarjeta', sublabel: 'Verifica el total en tu terminal', color: 'indigo', field: 'declared_card',
      icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    { key: 'transfer', label: 'Transferencia', sublabel: 'Confirma en tu banca móvil', color: 'pink', field: 'declared_transfer',
      icon: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5' },
];

// Métodos habilitados en la sucursal + cualquiera con movimientos. 'cash' siempre (fondo inicial).
const enabledMethods = computed(() => {
    const enabled = new Set(props.paymentMethods ?? ['cash', 'card', 'transfer']);
    enabled.add('cash');
    if ((props.totals?.card ?? 0) > 0) enabled.add('card');
    if ((props.totals?.transfer ?? 0) > 0) enabled.add('transfer');
    return ALL_METHODS.filter((m) => enabled.has(m.key));
});

const fieldOf = (key) => ALL_METHODS.find((m) => m.key === key)?.field;
const expectedFor = (key) => (key === 'cash' ? props.totals.expected_cash : props.totals[key]);

// Campo sin tocar = "pendiente" en UI, pero cuenta como 0 en cálculo (nunca NaN/null).
const isBlank = (key) => {
    const raw = closeForm[fieldOf(key)];
    return raw === '' || raw === null || raw === undefined;
};
const declaredFor = (key) => {
    if (isBlank(key)) return 0;
    const n = parseFloat(closeForm[fieldOf(key)]);
    return isNaN(n) ? 0 : n;
};
const diffFor = (key) => Math.round((declaredFor(key) - expectedFor(key)) * 100) / 100;
const diffStatus = (key) => {
    if (isBlank(key)) return 'pending';
    const d = diffFor(key);
    return d === 0 ? 'ok' : d > 0 ? 'surplus' : 'shortage';
};

const totalDiff = computed(() => {
    let sum = 0;
    for (const m of enabledMethods.value) {
        if (isBlank(m.key)) return null;
        sum += diffFor(m.key);
    }
    return Math.round(sum * 100) / 100;
});
const totalStatus = computed(() => {
    if (totalDiff.value === null) return 'pending';
    if (totalDiff.value === 0) return 'ok';
    return totalDiff.value > 0 ? 'surplus' : 'shortage';
});
const hasBlank = computed(() => enabledMethods.value.some((m) => isBlank(m.key)));
const hasDifference = computed(() => totalDiff.value !== null && totalDiff.value !== 0);

// Efectivo esperado negativo = los gastos/retiros del turno superan el efectivo
// disponible. Es válido, pero se resalta como alerta en lugar de dato neutro.
const expectedNegative = computed(() => Number(props.totals.expected_cash) < 0);

// Chips secundarios del hero: tarjeta/transferencia solo si aplican.
const heroChips = computed(() => {
    const chips = [{ label: 'Ventas', value: props.totals.payment_count, mono: false, tone: 'plain' }];
    chips.push({ label: 'Efectivo cobrado', value: money(props.totals.cash), mono: true, tone: 'emerald' });
    if (enabledMethods.value.some((m) => m.key === 'card')) {
        chips.push({ label: 'Tarjeta', value: money(props.totals.card), mono: true, tone: 'indigo' });
    }
    if (enabledMethods.value.some((m) => m.key === 'transfer')) {
        chips.push({ label: 'Transferencia', value: money(props.totals.transfer), mono: true, tone: 'pink' });
    }
    return chips;
});

const chipDot = {
    plain: 'bg-white/60',
    emerald: 'bg-emerald-400',
    indigo: 'bg-indigo-400',
    pink: 'bg-pink-400',
};

// Completa en 0.00 los métodos vacíos: el cierre nunca se bloquea.
const fillBlanksWithZero = () => {
    for (const m of enabledMethods.value) {
        if (isBlank(m.key)) closeForm[m.field] = '0.00';
    }
};

const handleSubmit = () => {
    fillBlanksWithZero();
    if (hasDifference.value && !showConfirm.value) {
        showConfirm.value = true;
        return;
    }
    closeForm.post(route(props.closeRouteName, props.tenant.slug));
};

const colorMap = {
    emerald: { chip: 'bg-emerald-100 text-emerald-600', text: 'text-emerald-700', soft: 'bg-emerald-50', ring: 'focus:border-emerald-400 focus:ring-emerald-300/60' },
    indigo: { chip: 'bg-indigo-100 text-indigo-600', text: 'text-indigo-700', soft: 'bg-indigo-50', ring: 'focus:border-indigo-400 focus:ring-indigo-300/60' },
    pink: { chip: 'bg-pink-100 text-pink-600', text: 'text-pink-700', soft: 'bg-pink-50', ring: 'focus:border-pink-400 focus:ring-pink-300/60' },
};

const statusPill = {
    ok: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    surplus: 'bg-amber-50 text-amber-700 ring-amber-500/20',
    shortage: 'bg-rose-50 text-rose-700 ring-rose-600/20',
    pending: 'bg-gray-50 text-gray-400 ring-gray-300/60',
};
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <!-- ─────────── HERO ─────────── -->
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-stone-900 via-stone-900 to-neutral-800 px-6 py-7 shadow-xl shadow-stone-900/25 sm:px-8 sm:py-8">
            <!-- glow cálido decorativo -->
            <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-amber-500/15 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-24 -left-10 h-56 w-56 rounded-full bg-rose-600/10 blur-3xl" />

            <div class="relative">
                <div class="flex items-center gap-2 text-xs font-medium text-white/60">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/15 px-2.5 py-1 font-semibold text-emerald-300 ring-1 ring-inset ring-emerald-400/30">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" />
                        Turno activo
                    </span>
                    <span class="text-white/40">·</span>
                    <span>desde {{ formatTime(shift.opened_at) }}</span>
                    <span class="text-white/40">·</span>
                    <span>{{ formatDuration(shift.opened_at) }}</span>
                </div>

                <p class="mt-5 text-sm font-medium text-white/50">Efectivo esperado en caja</p>
                <p class="mt-1 font-mono text-4xl font-extrabold tabular-nums sm:text-5xl" :class="expectedNegative ? 'text-amber-300' : 'text-white'">{{ money(totals.expected_cash) }}</p>
                <p class="mt-2 text-xs leading-relaxed text-white/45">
                    {{ money(shift.opening_amount) }} fondo
                    <span class="text-white/30">+</span> {{ money(totals.cash) }} cobrado
                    <template v-if="(totals.withdrawals ?? 0) > 0"><span class="text-white/30">−</span> {{ money(totals.withdrawals) }} retiros</template>
                    <template v-if="(totals.cash_expenses ?? 0) > 0"><span class="text-white/30">−</span> {{ money(totals.cash_expenses) }} gastos</template>
                    <template v-if="(totals.cash_provider_payments ?? 0) > 0"><span class="text-white/30">−</span> {{ money(totals.cash_provider_payments) }} compras</template>
                </p>
                <p v-if="expectedNegative" class="mt-3 inline-flex items-start gap-1.5 rounded-lg bg-amber-400/10 px-2.5 py-1.5 text-[11px] font-medium leading-snug text-amber-300 ring-1 ring-inset ring-amber-400/25">
                    <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    Los gastos y retiros registrados superan el efectivo del turno. Revísalos.
                </p>

                <div class="mt-6 flex flex-wrap gap-2.5">
                    <div v-for="chip in heroChips" :key="chip.label"
                        class="rounded-2xl bg-white/5 px-3.5 py-2 ring-1 ring-inset ring-white/10 backdrop-blur-sm">
                        <p class="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-white/45">
                            <span :class="['h-1.5 w-1.5 rounded-full', chipDot[chip.tone]]" />
                            {{ chip.label }}
                        </p>
                        <p :class="['mt-0.5 font-bold text-white', chip.mono ? 'font-mono text-sm tabular-nums' : 'text-lg']">{{ chip.value }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Slot opcional (p. ej. retiros en sucursal) -->
        <slot name="extra" />

        <!-- ─────────── CONCILIACIÓN ─────────── -->
        <section class="space-y-1">
            <h2 class="px-1 text-base font-bold text-gray-900">Conciliación de cierre</h2>
            <p class="px-1 text-sm text-gray-400">Declara los montos reales que tienes por cada método de pago</p>
        </section>

        <form @submit.prevent="handleSubmit" class="space-y-4">
            <!-- Tarjetas por método -->
            <div v-for="m in enabledMethods" :key="m.key"
                class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-200/70 transition hover:ring-gray-300/80 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div :class="[colorMap[m.color].chip, 'flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl']">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="m.icon" /></svg>
                        </div>
                        <div>
                            <h3 :class="[colorMap[m.color].text, 'text-sm font-bold']">{{ m.label }}</h3>
                            <p class="text-xs text-gray-400">{{ m.sublabel }}</p>
                        </div>
                    </div>
                    <span :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset', statusPill[diffStatus(m.key)]]">
                        <template v-if="diffStatus(m.key) === 'ok'">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>Cuadra
                        </template>
                        <template v-else-if="diffStatus(m.key) === 'surplus'">Sobrante</template>
                        <template v-else-if="diffStatus(m.key) === 'shortage'">Faltante</template>
                        <template v-else>Pendiente</template>
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-3 gap-3">
                    <!-- Esperado -->
                    <div :class="[colorMap[m.color].soft, 'rounded-xl px-3 py-3 text-center']">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Esperado</p>
                        <p :class="['mt-1 font-mono text-base font-bold tabular-nums', colorMap[m.color].text]">{{ money(expectedFor(m.key)) }}</p>
                    </div>

                    <!-- Declarado (input protagonista) -->
                    <div class="text-center">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Declarado</p>
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-gray-400">$</span>
                            <input v-model="closeForm[m.field]" type="number" step="0.01" min="0" inputmode="decimal" placeholder="0.00"
                                :class="['block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-7 pr-2 text-center font-mono text-lg font-bold tabular-nums text-gray-900 shadow-sm transition focus:ring-2', colorMap[m.color].ring]" />
                        </div>
                    </div>

                    <!-- Diferencia -->
                    <div class="rounded-xl px-3 py-3 text-center"
                        :class="diffStatus(m.key) === 'ok' ? 'bg-emerald-50' : diffStatus(m.key) === 'surplus' ? 'bg-amber-50' : diffStatus(m.key) === 'shortage' ? 'bg-rose-50' : 'bg-gray-50'">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Diferencia</p>
                        <p class="mt-1 font-mono text-base font-bold tabular-nums"
                            :class="diffStatus(m.key) === 'ok' ? 'text-emerald-600' : diffStatus(m.key) === 'surplus' ? 'text-amber-600' : diffStatus(m.key) === 'shortage' ? 'text-rose-600' : 'text-gray-300'">
                            <template v-if="diffStatus(m.key) === 'pending'">—</template>
                            <template v-else>{{ signedMoney(diffFor(m.key)) }}</template>
                        </p>
                    </div>
                </div>

                <p v-if="m.key === 'cash'" class="mt-3 text-[11px] leading-snug text-gray-400">
                    El efectivo esperado descuenta retiros, gastos y compras en efectivo del turno.
                </p>
            </div>

            <!-- ─── Diferencia total (semáforo) ─── -->
            <div class="rounded-2xl px-5 py-4 ring-1 sm:px-6"
                :class="{
                    'bg-gray-50 ring-gray-200': totalStatus === 'pending',
                    'bg-emerald-50 ring-emerald-200': totalStatus === 'ok',
                    'bg-amber-50 ring-amber-200': totalStatus === 'surplus',
                    'bg-rose-50 ring-rose-200': totalStatus === 'shortage',
                }">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl"
                            :class="{ 'bg-gray-200/70 text-gray-400': totalStatus === 'pending', 'bg-emerald-100 text-emerald-600': totalStatus === 'ok', 'bg-amber-100 text-amber-600': totalStatus === 'surplus', 'bg-rose-100 text-rose-600': totalStatus === 'shortage' }">
                            <svg v-if="totalStatus === 'ok'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <svg v-else-if="totalStatus === 'surplus'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" /></svg>
                            <svg v-else-if="totalStatus === 'shortage'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </span>
                        <div>
                            <p class="text-sm font-bold"
                                :class="{ 'text-gray-500': totalStatus === 'pending', 'text-emerald-700': totalStatus === 'ok', 'text-amber-700': totalStatus === 'surplus', 'text-rose-700': totalStatus === 'shortage' }">
                                Diferencia total del turno
                            </p>
                            <p class="text-xs"
                                :class="{ 'text-gray-400': totalStatus === 'pending', 'text-emerald-600': totalStatus === 'ok', 'text-amber-600': totalStatus === 'surplus', 'text-rose-600': totalStatus === 'shortage' }">
                                <template v-if="totalStatus === 'pending'">Captura los montos para ver tu diferencia</template>
                                <template v-else-if="totalStatus === 'ok'">Tu turno cuadra perfecto</template>
                                <template v-else-if="totalStatus === 'surplus'">Sobra dinero en caja</template>
                                <template v-else>Falta dinero en caja</template>
                            </p>
                        </div>
                    </div>
                    <p class="font-mono text-2xl font-extrabold tabular-nums sm:text-3xl"
                        :class="{ 'text-gray-300': totalStatus === 'pending', 'text-emerald-600': totalStatus === 'ok', 'text-amber-600': totalStatus === 'surplus', 'text-rose-600': totalStatus === 'shortage' }">
                        <template v-if="totalStatus === 'pending'">—</template>
                        <template v-else>{{ signedMoney(totalDiff) }}</template>
                    </p>
                </div>
            </div>

            <!-- Notas (si hay diferencia) -->
            <div v-if="hasDifference">
                <label class="px-1 text-xs font-semibold text-gray-500">Observaciones <span class="font-normal text-gray-300">(opcional)</span></label>
                <textarea v-model="closeForm.notes" rows="2" placeholder="Explica las diferencias encontradas…"
                    class="mt-1.5 block w-full rounded-xl border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:border-slate-400 focus:ring-slate-300/60" />
            </div>

            <!-- Confirmación -->
            <div v-if="showConfirm" class="flex gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div>
                    <p class="text-sm font-bold text-amber-800">Hay diferencias en tu cierre</p>
                    <p class="mt-0.5 text-xs text-amber-700">El turno se cerrará con una diferencia de <span class="font-mono font-bold">{{ signedMoney(totalDiff) }}</span>. Esta información quedará registrada en el corte.</p>
                </div>
            </div>

            <!-- CTA -->
            <p v-if="hasBlank && !showConfirm" class="px-1 text-xs text-gray-400">
                Los métodos que dejes sin capturar se cerrarán como <span class="font-semibold text-gray-500">$0.00</span>.
            </p>
            <div class="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center">
                <button type="submit" :disabled="closeForm.processing"
                    :class="['w-full rounded-2xl px-8 py-3.5 text-sm font-bold text-white shadow-sm transition disabled:opacity-50 sm:w-auto',
                        showConfirm ? 'bg-amber-500 hover:bg-amber-600' : 'bg-slate-900 hover:bg-slate-800']">
                    {{ showConfirm ? 'Confirmar y cerrar turno' : 'Cerrar turno y generar corte' }}
                </button>
                <button v-if="showConfirm" type="button" @click="showConfirm = false" class="text-sm font-medium text-gray-500 hover:text-gray-700">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</template>
