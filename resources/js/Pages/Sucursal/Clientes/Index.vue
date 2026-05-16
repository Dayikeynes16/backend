<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    customers: { type: Object, required: true },
    customersSummary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    tenant: { type: Object, required: true },
});

// --- Filtros ---
const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || 'active');
const withDebt = ref(!!props.filters?.with_debt);
const sort = ref(props.filters?.sort || 'name');

let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(
            route('sucursal.clientes.index', props.tenant.slug),
            {
                search: search.value || undefined,
                status: status.value || undefined,
                with_debt: withDebt.value ? 1 : undefined,
                sort: sort.value !== 'name' ? sort.value : undefined,
            },
            { preserveState: true, replace: true, only: ['customers', 'customersSummary', 'filters'] },
        );
    }, 250);
};

watch([search, status, withDebt, sort], applyFilters);

// --- Formato ---
const money = (v) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v ?? 0));
const number = (v) => new Intl.NumberFormat('es-MX').format(Number(v ?? 0));
const initialOf = (name) => (name || '?').trim().charAt(0).toUpperCase();
const formatRelative = (iso) => {
    if (!iso) return null;
    const d = new Date(iso);
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'ahora';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    if (diff < 86400 * 7) return `hace ${Math.floor(diff / 86400)} días`;
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
};

// --- KPIs ---
const summary = computed(() => props.customersSummary || {});
const hasResults = computed(() => (props.customers?.data?.length ?? 0) > 0);
const totalLabel = computed(() => {
    const total = props.customers?.total ?? 0;
    return total === 1 ? '1 cliente' : `${total} clientes`;
});

// --- Crear cliente ---
const showCreate = ref(false);
const createForm = useForm({ name: '', phone: '', notes: '' });
const submitCreate = () => {
    createForm.post(route('sucursal.clientes.store', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; createForm.reset(); },
    });
};

// --- Editar cliente (modal rápido sin entrar al detalle) ---
const editingCustomer = ref(null);
const editForm = useForm({ name: '', phone: '', notes: '', status: 'active' });
const openEdit = (customer) => {
    editingCustomer.value = customer;
    editForm.name = customer.name;
    editForm.phone = customer.phone;
    editForm.notes = customer.notes || '';
    editForm.status = customer.status;
    editForm.clearErrors();
};
const submitEdit = () => {
    if (!editingCustomer.value) return;
    editForm.put(route('sucursal.clientes.update', [props.tenant.slug, editingCustomer.value.id]), {
        preserveScroll: true,
        onSuccess: () => { editingCustomer.value = null; },
    });
};

// --- Eliminar cliente ---
const deletingCustomer = ref(null);
const confirmDelete = () => {
    if (!deletingCustomer.value) return;
    router.delete(route('sucursal.clientes.destroy', [props.tenant.slug, deletingCustomer.value.id]), {
        preserveScroll: true,
        onFinish: () => { deletingCustomer.value = null; },
    });
};
</script>

<template>
    <Head title="Clientes" />
    <SucursalLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Clientes</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Cartera de clientes registrados en esta sucursal.</p>
                </div>
                <button type="button" @click="showCreate = true"
                    class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-[.98]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nuevo cliente
                </button>
            </div>
        </template>

        <div class="space-y-5">
            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500">Total clientes</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ number(summary.total ?? 0) }}</p>
                    <p class="mt-0.5 text-xs text-gray-400">Activos + inactivos</p>
                </div>
                <div class="rounded-2xl bg-gradient-to-br from-emerald-50/50 to-white px-5 py-4 shadow-sm ring-1 ring-emerald-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Activos</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-700">{{ number(summary.active ?? 0) }}</p>
                    <p class="mt-0.5 text-xs text-emerald-600/70">Con compras o disponibles</p>
                </div>
                <div class="rounded-2xl bg-gradient-to-br from-amber-50/60 to-white px-5 py-4 shadow-sm ring-1 ring-amber-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Con deuda</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-amber-700">{{ number(summary.customers_with_debt ?? 0) }}</p>
                    <p class="mt-0.5 text-xs text-amber-600/70">Clientes con saldo pendiente</p>
                </div>
                <div class="rounded-2xl bg-gradient-to-br from-red-50/60 to-white px-5 py-4 shadow-sm ring-1 ring-red-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-red-700">Deuda total</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-red-700">{{ money(summary.total_debt ?? 0) }}</p>
                    <p class="mt-0.5 text-xs text-red-600/70">Suma de saldos pendientes</p>
                </div>
            </div>

            <!-- Card principal: toolbar + tabla -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div class="relative w-full sm:w-80">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input v-model="search" type="text" placeholder="Buscar por nombre o teléfono…"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-700 placeholder-gray-400 shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-xl bg-gray-100 p-1 shadow-inner">
                            <button v-for="opt in [
                                { v: 'active', l: 'Activos' },
                                { v: 'inactive', l: 'Inactivos' },
                                { v: '', l: 'Todos' },
                            ]" :key="opt.v" type="button" @click="status = opt.v"
                                :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                    status === opt.v ? 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-200/60' : 'text-gray-500 hover:text-gray-700']">
                                {{ opt.l }}
                            </button>
                        </div>
                        <button type="button" @click="withDebt = !withDebt"
                            :class="['inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition',
                                withDebt ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            <svg v-if="withDebt" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Con deuda
                        </button>
                        <select v-model="sort" class="rounded-xl border-gray-200 bg-white py-1.5 text-xs font-semibold text-gray-600 shadow-sm focus:border-red-400 focus:ring-red-300">
                            <option value="name">Nombre A-Z</option>
                            <option value="debt">Más deuda primero</option>
                            <option value="last_sale">Compra más reciente</option>
                        </select>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ totalLabel }}</span>
                    </div>
                </div>

                <!-- Tabla -->
                <div v-if="hasResults" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Cliente</th>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Teléfono</th>
                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Deuda</th>
                                <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Última compra</th>
                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500"># compras</th>
                                <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Precios pref.</th>
                                <th class="px-2 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="c in customers.data" :key="c.id" class="group">
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <Link :href="route('sucursal.clientes.show', [tenant.slug, c.id])"
                                        class="flex items-center gap-3 transition group-hover:underline">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-sm font-bold text-white shadow-sm">
                                            {{ initialOf(c.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ c.name }}</p>
                                            <span v-if="c.status === 'inactive'" class="mt-0.5 inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-600 ring-1 ring-inset ring-gray-300/50">Inactivo</span>
                                        </div>
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-gray-600">{{ c.phone || '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm tabular-nums">
                                    <span v-if="Number(c.total_owed) > 0" class="font-bold text-red-600">{{ money(c.total_owed) }}</span>
                                    <span v-else class="text-gray-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-gray-600">
                                    <span v-if="c.last_sale_at">{{ formatRelative(c.last_sale_at) }}</span>
                                    <span v-else class="text-gray-300">Sin compras</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm tabular-nums text-gray-600">{{ number(c.sales_count ?? 0) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm tabular-nums text-gray-600">
                                    <span v-if="c.preferential_prices_count > 0">{{ c.preferential_prices_count }}</span>
                                    <span v-else class="text-gray-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-2 py-3.5">
                                    <div class="flex items-center justify-end gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                        <button type="button" @click="openEdit(c)" title="Editar"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-orange-50 hover:text-orange-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                        </button>
                                        <button type="button" @click="deletingCustomer = c" title="Eliminar"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-100 to-red-100 text-red-600">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                    </div>
                    <p class="mt-4 text-sm font-semibold text-gray-700">
                        {{ search || withDebt ? 'No encontramos clientes con esos filtros.' : 'Aún no hay clientes registrados.' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{ search || withDebt ? 'Prueba con otra búsqueda o limpia los filtros.' : 'Registra el primer cliente para empezar a llevar su historial.' }}
                    </p>
                    <button v-if="!search && !withDebt" type="button" @click="showCreate = true"
                        class="mt-4 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Crear el primero
                    </button>
                </div>

                <!-- Paginación -->
                <div v-if="hasResults && (customers.last_page ?? 1) > 1"
                    class="flex items-center justify-between border-t border-gray-100 bg-gray-50/40 px-5 py-3 text-xs text-gray-600">
                    <span>Mostrando <b>{{ customers.from }}</b>–<b>{{ customers.to }}</b> de <b>{{ customers.total }}</b></span>
                    <div class="flex gap-1.5">
                        <Link v-if="customers.prev_page_url" :href="customers.prev_page_url" preserve-scroll
                            class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">← Anterior</Link>
                        <span v-else class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-300 ring-1 ring-gray-200">← Anterior</span>
                        <Link v-if="customers.next_page_url" :href="customers.next_page_url" preserve-scroll
                            class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100">Siguiente →</Link>
                        <span v-else class="rounded-lg bg-white px-3 py-1.5 font-semibold text-gray-300 ring-1 ring-gray-200">Siguiente →</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: nuevo cliente -->
        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="showCreate = false">
            <div class="w-full max-w-md rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">Nuevo cliente</h3>
                    <button @click="showCreate = false" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <form @submit.prevent="submitCreate" class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre</label>
                        <input v-model="createForm.name" type="text" required maxlength="255" placeholder="Ej. Lucía Martínez"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-600">{{ createForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Teléfono</label>
                        <input v-model="createForm.phone" type="tel" required maxlength="20" placeholder="55 1234 5678"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="createForm.errors.phone" class="mt-1 text-xs text-red-600">{{ createForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Notas (opcional)</label>
                        <textarea v-model="createForm.notes" rows="2" maxlength="1000"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </form>
                <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                    <button type="button" @click="showCreate = false" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                    <button type="button" @click="submitCreate" :disabled="createForm.processing" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                        {{ createForm.processing ? 'Creando…' : 'Crear cliente' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal: editar cliente -->
        <div v-if="editingCustomer" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="editingCustomer = null">
            <div class="w-full max-w-md rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @click.stop>
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">Editar cliente</h3>
                    <button @click="editingCustomer = null" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <form @submit.prevent="submitEdit" class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre</label>
                        <input v-model="editForm.name" type="text" required maxlength="255"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Teléfono</label>
                        <input v-model="editForm.phone" type="tel" required maxlength="20"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-gray-600">Notas</label>
                        <textarea v-model="editForm.notes" rows="2" maxlength="1000"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>
                </form>
                <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                    <button type="button" @click="editingCustomer = null" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                    <button type="button" @click="submitEdit" :disabled="editForm.processing" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                        {{ editForm.processing ? 'Guardando…' : 'Guardar cambios' }}
                    </button>
                </div>
            </div>
        </div>

        <ConfirmDialog v-if="deletingCustomer"
            title="Eliminar cliente"
            :message="`Vas a eliminar a ${deletingCustomer.name}. Si tiene ventas registradas se marcará como inactivo en su lugar.`"
            confirm-label="Eliminar"
            variant="danger"
            @confirm="confirmDelete"
            @cancel="deletingCustomer = null" />

        <FlashToast />
    </SucursalLayout>
</template>
