<script setup>
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    shift: Object,
    tenant: Object,
    whatsappUrl: { type: String, default: null },
    hasOwnerWhatsapp: { type: Boolean, default: false },
    autoOpenWhatsapp: { type: Boolean, default: false },
});

const formatDT = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Auto-open WhatsApp si venimos del cierre reciente. Los navegadores bloquean
// window.open si no hay gesto del usuario; por eso banner fallback.
const autoOpenBlocked = ref(false);
onMounted(() => {
    if (!props.autoOpenWhatsapp || !props.hasOwnerWhatsapp || !props.whatsappUrl) return;
    const win = window.open(props.whatsappUrl, '_blank', 'noopener,noreferrer');
    if (!win || win.closed || typeof win.closed === 'undefined') {
        autoOpenBlocked.value = true;
    }
});

const diffColor = (val) => {
    const n = Number(val);
    if (n > 0) return 'text-amber-600';
    if (n < 0) return 'text-red-600';
    return 'text-green-600';
};

// Igual que Sucursal/Cortes/Show.vue: solo mostramos métodos que tuvieron
// movimiento o fueron declarados. Si declared_* es NULL, el método "no aplica".
const ALL_METHODS = [
    { key: 'cash', label: 'Efectivo', color: 'emerald',
      declaredField: 'declared_amount', diffField: 'difference', expectedField: 'expected_amount', totalField: 'total_cash' },
    { key: 'card', label: 'Tarjeta', color: 'blue',
      declaredField: 'declared_card', diffField: 'difference_card', expectedField: 'total_card', totalField: 'total_card' },
    { key: 'transfer', label: 'Transferencia', color: 'violet',
      declaredField: 'declared_transfer', diffField: 'difference_transfer', expectedField: 'total_transfer', totalField: 'total_transfer' },
];

const conciliation = computed(() => {
    return ALL_METHODS
        .filter(m => {
            const declared = props.shift[m.declaredField];
            const total = Number(props.shift[m.totalField] ?? 0);
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
const hasDifference = computed(() => Math.abs(totalDiff.value) > 0.001);
const withdrawalsTotal = computed(() => (props.shift.withdrawals || []).reduce((s, w) => s + Number(w.amount), 0));
</script>

<template>
    <Head title="Corte cerrado" />
    <CajeroLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Corte cerrado</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 pb-8">
            <!-- Success banner -->
            <div class="flex items-center gap-3 rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 px-5 py-4 ring-1 ring-green-100">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-500 text-white shadow-sm">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </div>
                <div>
                    <p class="text-sm font-bold text-green-900">Tu turno se cerró correctamente.</p>
                    <p class="text-xs text-green-700">Antes de salir, envia el reporte al dueno por WhatsApp.</p>
                </div>
            </div>

            <!-- WhatsApp report to owner -->
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center gap-4 px-6 py-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#25D366]/10">
                        <svg class="h-6 w-6 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-gray-900">Enviar reporte al dueno por WhatsApp</p>
                        <p v-if="hasOwnerWhatsapp" class="mt-0.5 text-xs text-gray-500">Abre WhatsApp con el resumen del corte prellenado.</p>
                        <p v-else class="mt-0.5 text-xs text-amber-600">El dueno aun no ha configurado su WhatsApp.</p>
                    </div>
                    <a v-if="hasOwnerWhatsapp" :href="whatsappUrl" target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-[#1ebe5b] focus:outline-none focus:ring-2 focus:ring-[#25D366]/40">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.03-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.002-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.892 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                        </svg>
                        Enviar por WhatsApp
                    </a>
                    <button v-else type="button" disabled
                        class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-bold text-gray-400">
                        Enviar por WhatsApp
                    </button>
                </div>
                <div v-if="autoOpenBlocked" class="flex items-center gap-2 border-t border-amber-100 bg-amber-50 px-6 py-2.5">
                    <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                    <p class="text-xs text-amber-800">Tu navegador bloqueo la apertura automatica. Haz click en <span class="font-semibold">Enviar por WhatsApp</span>.</p>
                </div>
            </div>

            <!-- Header info -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="grid grid-cols-3 divide-x divide-gray-100">
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-medium uppercase tracking-wider text-gray-400">Cajero</p>
                        <p class="mt-1 text-sm font-bold text-gray-900">{{ shift.user?.name }}</p>
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
                    <span class="text-xs text-gray-400">Fondo inicial: <span class="font-semibold text-gray-600">{{ money(shift.opening_amount) }}</span></span>
                    <p class="text-sm font-bold text-gray-900">Total cobrado: <span class="font-mono tabular-nums">{{ money(shift.total_sales) }}</span></p>
                </div>
            </div>

            <!-- Methods conciliation -->
            <div v-if="conciliation.length > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100 overflow-hidden">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Resumen por metodo</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <div v-for="m in conciliation" :key="m.key" class="grid grid-cols-4 items-center gap-3 px-6 py-4">
                        <p class="text-sm font-semibold text-gray-900">{{ m.label }}</p>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-wider text-gray-400">Esperado</p>
                            <p class="font-mono text-sm font-bold tabular-nums text-gray-700">{{ money(m.expected) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-wider text-gray-400">Declarado</p>
                            <p class="font-mono text-sm font-bold tabular-nums text-gray-900">{{ money(m.declared) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-wider text-gray-400">Diferencia</p>
                            <p class="font-mono text-sm font-bold tabular-nums" :class="diffColor(m.diff)">
                                {{ m.diff > 0 ? '+' : '' }}{{ money(m.diff) }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="border-t border-gray-100 px-6 py-3"
                    :class="hasDifference ? 'bg-red-50/60' : 'bg-green-50/60'">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-bold uppercase tracking-wider"
                            :class="hasDifference ? 'text-red-700' : 'text-green-700'">
                            Diferencia total
                        </p>
                        <p class="font-mono text-base font-extrabold tabular-nums" :class="diffColor(totalDiff)">
                            {{ totalDiff > 0 ? '+' : '' }}{{ money(totalDiff) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Withdrawals -->
            <div v-if="shift.withdrawals && shift.withdrawals.length > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Retiros de efectivo</h2>
                    <p class="text-xs text-gray-400">Total: <span class="font-semibold text-gray-700">{{ money(withdrawalsTotal) }}</span></p>
                </div>
                <div class="divide-y divide-gray-50">
                    <div v-for="w in shift.withdrawals" :key="w.id" class="flex items-center justify-between px-6 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ money(w.amount) }}</p>
                            <p class="text-xs text-gray-400">{{ w.reason }}</p>
                        </div>
                        <span class="text-xs text-gray-400">{{ new Date(w.created_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) }}</span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div v-if="shift.notes" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <h3 class="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Observaciones</h3>
                <p class="text-sm text-gray-700">{{ shift.notes }}</p>
            </div>

            <!-- Footer actions -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <Link :href="route('caja.workbench', tenant.slug)"
                    class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-50">
                    Ir a mesa de trabajo
                </Link>
                <Link :href="route('caja.turno', tenant.slug)"
                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                    Abrir nuevo turno
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" /></svg>
                </Link>
            </div>
        </div>

        <FlashToast />
    </CajeroLayout>
</template>
