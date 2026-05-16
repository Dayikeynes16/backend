<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatCard from '@/Components/Clientes/StatCard.vue';

const props = defineProps({
    customer: { type: Object, required: true },
    tenantSlug: { type: String, required: true },
    /** Seed inicial sincrónico (CustomerController@show). */
    statsSeed: { type: Object, required: true },
    /** Stats vivos del composable (sobrescriben el seed cuando llegan). */
    stats: { type: Object, default: null },
    canRegisterPayment: { type: Boolean, default: false },
    paymentDisabledReason: { type: String, default: '' },
});

const emit = defineEmits(['register-payment', 'edit', 'toggle-status', 'destroy']);

// Helpers de formato
const formatMoney = (v) => v === null || v === undefined ? '—'
    : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v);

const formatNumber = (v) => v === null || v === undefined ? '—'
    : new Intl.NumberFormat('es-MX').format(v);

const formatRelative = (iso) => {
    if (!iso) return null;
    const date = new Date(iso);
    const diff = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diff < 60) return 'hace unos segundos';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    if (diff < 86400 * 7) return `hace ${Math.floor(diff / 86400)} días`;
    return date.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatFullDate = (iso) => iso
    ? new Date(iso).toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' })
    : null;

// Datos derivados — preferimos stats vivos sobre el seed cuando llegan
const data = computed(() => props.stats || props.statsSeed || {});
const totalOwed = computed(() => Number(data.value.total_owed || 0));
const totalSpent = computed(() => Number(data.value.total_spent || 0));
const saleCount = computed(() => Number(data.value.sale_count || 0));
const pendingCount = computed(() => Number(data.value.pending_sales_count || 0));
const avgTicket = computed(() => Number(data.value.avg_ticket || 0));
const totalSaved = computed(() => Number(data.value.total_saved || 0));
const lastSaleAt = computed(() => data.value.last_sale_at || data.value.last_sale?.created_at);
const firstSaleAt = computed(() => data.value.first_sale_at);
const topProduct = computed(() => data.value.top_product || null);

const initial = computed(() => (props.customer.name || '?').trim().charAt(0).toUpperCase());

const statusBadge = computed(() => props.customer.status === 'active'
    ? { label: 'Activo', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' }
    : { label: 'Inactivo', cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' });

const whatsappHref = computed(() => {
    if (!props.customer.phone) return null;
    const digits = String(props.customer.phone).replace(/\D/g, '');
    if (!digits) return null;
    const normalized = digits.startsWith('52') ? digits : `52${digits}`;
    return `https://wa.me/${normalized}`;
});

const showMenu = ref(false);
</script>

<template>
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
        <!-- Top row: breadcrumb -->
        <div class="flex items-center gap-2 border-b border-gray-100 px-6 py-3 text-sm">
            <Link :href="route('sucursal.clientes.index', tenantSlug)"
                class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" title="Volver">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <Link :href="route('sucursal.clientes.index', tenantSlug)" class="text-gray-500 hover:text-gray-700">Clientes</Link>
            <svg class="h-3.5 w-3.5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            <span class="font-semibold text-gray-900 truncate">{{ customer.name }}</span>
        </div>

        <!-- Identidad + acciones -->
        <div class="grid gap-4 px-6 py-5 lg:grid-cols-[1fr_auto] lg:items-start">
            <div class="flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-xl font-bold text-white shadow-sm">
                    {{ initial }}
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">{{ customer.name }}</h1>
                        <span :class="[statusBadge.cls, 'rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset']">{{ statusBadge.label }}</span>
                        <span v-if="totalOwed > 0" class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700 ring-1 ring-inset ring-red-600/20">
                            Con deuda · {{ formatMoney(totalOwed) }}
                        </span>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600">
                        <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-gray-700 transition hover:text-green-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                            {{ customer.phone }}
                        </a>
                        <span v-else-if="customer.phone" class="text-gray-700">{{ customer.phone }}</span>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        <span v-if="firstSaleAt">Cliente desde {{ formatFullDate(firstSaleAt) }}</span>
                        <span v-else>Sin compras registradas</span>
                        <span v-if="lastSaleAt"> · Última compra {{ formatRelative(lastSaleAt) }}</span>
                    </div>
                    <p v-if="topProduct" class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20">
                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.5a.75.75 0 0 1 .68.434l1.83 3.93 4.32.514a.75.75 0 0 1 .416 1.281l-3.18 2.974.86 4.272a.75.75 0 0 1-1.103.79L10 14.347l-3.823 2.348a.75.75 0 0 1-1.104-.79l.86-4.272-3.18-2.974a.75.75 0 0 1 .416-1.281l4.32-.514L9.32 2.934A.75.75 0 0 1 10 2.5Z" /></svg>
                        Compra más: <span class="ml-1 font-bold">{{ topProduct.product_name }}</span>
                    </p>
                    <p v-if="customer.notes" class="mt-2 max-w-prose rounded-lg bg-gray-50 px-3 py-2 text-sm italic text-gray-600 ring-1 ring-gray-100">
                        {{ customer.notes }}
                    </p>
                </div>
            </div>

            <!-- Acciones -->
            <div class="flex flex-wrap items-center gap-2 lg:flex-nowrap">
                <button type="button"
                    :disabled="!canRegisterPayment"
                    :title="paymentDisabledReason"
                    @click="$emit('register-payment')"
                    class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Registrar cobro
                </button>
                <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2.5 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-200 transition hover:bg-emerald-50">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.2c5.46 0 9.91-4.44 9.91-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 1.65a8.25 8.25 0 0 1 5.85 14.1 8.25 8.25 0 0 1-5.85 2.41 8.21 8.21 0 0 1-4.21-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.25 8.25 0 0 1 7-12.65zm-3.27 4.6c-.16 0-.41.06-.62.31-.21.24-.83.81-.83 1.97 0 1.17.85 2.29.97 2.45.12.16 1.65 2.62 4.06 3.57 1.99.79 2.39.63 2.83.59.43-.04 1.4-.57 1.6-1.13.2-.55.2-1.03.14-1.13-.06-.1-.21-.16-.45-.28-.23-.12-1.39-.69-1.61-.77-.21-.08-.37-.12-.52.12-.16.24-.6.77-.74.93-.14.16-.27.18-.5.06-.23-.12-.97-.36-1.85-1.14-.69-.61-1.15-1.37-1.28-1.61-.14-.24-.01-.36.1-.48.11-.11.24-.27.35-.41.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.5-1.31-.7-1.79-.18-.46-.37-.4-.5-.41h-.41z" /></svg>
                    WhatsApp
                </a>
                <div class="relative">
                    <button type="button" @click="showMenu = !showMenu"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white text-gray-500 ring-1 ring-gray-200 transition hover:bg-gray-50 hover:text-gray-700"
                        title="Más acciones">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" /></svg>
                    </button>
                    <!-- Overlay invisible para cerrar al hacer click fuera -->
                    <div v-if="showMenu" class="fixed inset-0 z-10" @click="showMenu = false"></div>
                    <Transition enter-active-class="transition duration-100" leave-active-class="transition duration-75"
                        enter-from-class="opacity-0 scale-95" leave-to-class="opacity-0 scale-95">
                        <div v-if="showMenu"
                            class="absolute right-0 top-12 z-20 w-48 origin-top-right rounded-xl bg-white py-1 shadow-lg ring-1 ring-gray-200">
                            <button type="button" @click="showMenu = false; $emit('edit')"
                                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                Editar datos
                            </button>
                            <button type="button" @click="showMenu = false; $emit('toggle-status')"
                                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                {{ customer.status === 'active' ? 'Marcar inactivo' : 'Marcar activo' }}
                            </button>
                            <button type="button" @click="showMenu = false; $emit('destroy')"
                                class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                Eliminar cliente
                            </button>
                        </div>
                    </Transition>
                </div>
            </div>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-2 gap-3 border-t border-gray-100 bg-gray-50/40 px-6 py-4 sm:grid-cols-3 lg:grid-cols-5">
            <StatCard label="Deuda actual" :value="formatMoney(totalOwed)" :tone="totalOwed > 0 ? 'negative' : 'neutral'"
                :hint="totalOwed > 0 ? `${pendingCount} ${pendingCount === 1 ? 'venta pendiente' : 'ventas pendientes'}` : 'Al corriente'" />
            <StatCard label="Total gastado" :value="formatMoney(totalSpent)" tone="positive"
                hint="Histórico, sin canceladas" />
            <StatCard label="Compras" :value="formatNumber(saleCount)" tone="neutral"
                :hint="pendingCount > 0 ? `${pendingCount} con saldo` : ''" />
            <StatCard label="Ticket promedio" :value="formatMoney(avgTicket)" tone="neutral"
                :hint="saleCount > 0 ? `entre ${saleCount} ${saleCount === 1 ? 'compra' : 'compras'}` : ''" />
            <StatCard label="Ahorro acumulado" :value="formatMoney(totalSaved)" :tone="totalSaved > 0 ? 'positive' : 'neutral'"
                :hint="totalSaved > 0 ? 'Vs. precio de catálogo' : 'Sin descuentos aún'" />
        </div>
    </section>
</template>
