<script setup>
import { formatCurrency } from '@/composables/useCurrency';
import { computed } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    payment: { type: Object, default: null },
});
defineEmits(['close']);

const fmt = (n) => formatCurrency(n);
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m || '—');

const isCancelled = computed(() => !!props.payment?.cancelled_at);
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open && payment" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Detalle del pago</h2>
                            <p class="text-xs text-gray-500">{{ fmtDate(payment.paid_at) }}</p>
                        </div>
                        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <div class="max-h-[80vh] space-y-5 overflow-y-auto px-5 py-5">
                        <!-- Monto + estado -->
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Monto</div>
                                <div class="text-3xl font-bold text-gray-900">{{ fmt(payment.amount) }}</div>
                            </div>
                            <span :class="['rounded-full px-3 py-1 text-xs font-semibold', isCancelled ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800']">
                                {{ isCancelled ? 'Cancelado' : 'Vigente' }}
                            </span>
                        </div>

                        <!-- Datos -->
                        <div class="grid grid-cols-1 gap-4 rounded-xl bg-gray-50 p-4 sm:grid-cols-2">
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Método</div>
                                <div class="text-sm font-semibold text-gray-900">{{ methodLabel(payment.payment_method) }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Aplicado a</div>
                                <div class="text-sm font-semibold text-gray-900">
                                    <span v-if="payment.purchase">Compra {{ payment.purchase.folio }}</span>
                                    <span v-else class="italic text-gray-500">A cuenta / excedente</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Referencia</div>
                                <div class="text-sm font-semibold text-gray-900">{{ payment.reference || '—' }}</div>
                            </div>
                            <div v-if="payment.user">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Registró</div>
                                <div class="text-sm font-semibold text-gray-900">{{ payment.user.name }}</div>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div v-if="payment.notes" class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Notas</div>
                            {{ payment.notes }}
                        </div>

                        <!-- Cancelación -->
                        <div v-if="isCancelled" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <div class="font-semibold">Pago cancelado el {{ fmtDate(payment.cancelled_at) }}</div>
                            <div v-if="payment.cancel_reason">{{ payment.cancel_reason }}</div>
                        </div>
                    </div>

                    <footer class="flex justify-end border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button @click="$emit('close')" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cerrar</button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
