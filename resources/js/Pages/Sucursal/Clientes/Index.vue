<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    customers: Array,
    products: Array,
    filters: Object,
    tenant: Object,
});

// --- Filters ---
const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('sucursal.clientes.index', props.tenant.slug), {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};
watch(search, applyFilters);
watch(statusFilter, () => { clearTimeout(debounceTimer); applyFilters(); });

// --- Selection ---
const selectedId = ref(null);
const selected = computed(() => props.customers.find(c => c.id === selectedId.value) || null);
const selectCustomer = (c) => { selectedId.value = c.id; };

watch(() => props.customers, () => {
    if (selectedId.value && !props.customers.find(c => c.id === selectedId.value)) {
        selectedId.value = null;
    }
});

// --- Create customer ---
const showCreateModal = ref(false);
const createForm = useForm({ name: '', phone: '', notes: '' });
const submitCreate = () => {
    createForm.post(route('sucursal.clientes.store', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => { showCreateModal.value = false; createForm.reset(); },
    });
};

// --- Edit customer ---
const editing = ref(false);
const editForm = useForm({ name: '', phone: '', notes: '', status: '' });
const startEdit = () => {
    if (!selected.value) return;
    editForm.name = selected.value.name;
    editForm.phone = selected.value.phone;
    editForm.notes = selected.value.notes || '';
    editForm.status = selected.value.status;
    editing.value = true;
};
const submitEdit = () => {
    editForm.put(route('sucursal.clientes.update', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
};

// --- Delete customer ---
const confirmDelete = ref(false);
const deleteCustomer = () => {
    router.delete(route('sucursal.clientes.destroy', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { confirmDelete.value = false; selectedId.value = null; },
    });
};

// --- Prices ---
const showAddPrice = ref(false);
const priceForm = useForm({ product_id: '', price: '' });

const assignedProductIds = computed(() =>
    (selected.value?.prices || []).map(p => p.product_id)
);
const availableProducts = computed(() =>
    props.products.filter(p => !assignedProductIds.value.includes(p.id))
);

const submitPrice = () => {
    priceForm.post(route('sucursal.clientes.precios.store', [props.tenant.slug, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { showAddPrice.value = false; priceForm.reset(); },
    });
};

const editingPriceId = ref(null);
const editPriceForm = useForm({ price: '' });
const startEditPrice = (p) => { editingPriceId.value = p.id; editPriceForm.price = parseFloat(p.price); };
const submitEditPrice = (priceId) => {
    editPriceForm.put(route('sucursal.clientes.precios.update', [props.tenant.slug, selected.value.id, priceId]), {
        preserveScroll: true,
        onSuccess: () => { editingPriceId.value = null; },
    });
};

const deletePrice = (priceId) => {
    router.delete(route('sucursal.clientes.precios.destroy', [props.tenant.slug, selected.value.id, priceId]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Clientes" />
    <SucursalLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Clientes</h1></template>

        <div class="flex h-[calc(100vh-8rem)] gap-5">
            <!-- LEFT: Customer list -->
            <div class="flex w-[380px] shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="space-y-3 border-b border-gray-100 px-5 py-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold text-gray-900">Clientes</h2>
                        <button @click="showCreateModal = true" class="flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-red-700">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo
                        </button>
                    </div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar nombre o telefono..." class="w-full rounded-lg border-gray-200 py-2 pl-10 pr-4 text-sm placeholder-gray-400 focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <div class="flex gap-1.5">
                        <button v-for="f in [{v:'',l:'Activos'},{v:'inactive',l:'Inactivos'},{v:'all',l:'Todos'}]"
                            :key="f.v" @click="statusFilter = f.v === 'all' ? 'all' : f.v"
                            :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                (statusFilter === f.v || (!statusFilter && f.v === '')) ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                            {{ f.l }}
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <div v-for="c in customers" :key="c.id" @click="selectCustomer(c)"
                        :class="['cursor-pointer rounded-xl p-4 transition-all',
                            selectedId === c.id ? 'ring-2 ring-red-500 bg-red-50/40' : 'ring-1 ring-gray-100 hover:ring-gray-200 hover:bg-gray-50/50']">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-gray-900">{{ c.name }}</span>
                            <span v-if="c.status === 'inactive'" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-500">Inactivo</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ c.phone }}</p>
                        <p v-if="c.prices?.length" class="mt-1 text-xs text-red-500">{{ c.prices.length }} precio{{ c.prices.length > 1 ? 's' : '' }} preferencial{{ c.prices.length > 1 ? 'es' : '' }}</p>
                    </div>
                    <div v-if="customers.length === 0" class="flex flex-col items-center justify-center py-20 text-center">
                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay clientes.</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Detail -->
            <div class="flex flex-1 flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!selected" class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        <p class="mt-4 text-sm font-medium text-gray-400">Selecciona un cliente para ver el detalle</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header -->
                    <div class="border-b border-gray-100 px-6 py-4">
                        <template v-if="editing">
                            <form @submit.prevent="submitEdit" class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-gray-500">Nombre</label>
                                        <input v-model="editForm.name" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-gray-500">Telefono</label>
                                        <input v-model="editForm.phone" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                        <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Notas</label>
                                    <textarea v-model="editForm.notes" rows="2" class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Estado</label>
                                    <select v-model="editForm.status" class="rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="active">Activo</option>
                                        <option value="inactive">Inactivo</option>
                                    </select>
                                </div>
                                <div class="flex gap-3">
                                    <button type="submit" :disabled="editForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                                    <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                                </div>
                            </form>
                        </template>
                        <template v-else>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="flex items-center gap-3">
                                        <h2 class="text-lg font-bold text-gray-900">{{ selected.name }}</h2>
                                        <span v-if="selected.status === 'inactive'" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-500 ring-1 ring-inset ring-gray-200">Inactivo</span>
                                    </div>
                                    <p class="mt-0.5 text-sm text-gray-500">{{ selected.phone }}</p>
                                    <p v-if="selected.notes" class="mt-1 text-xs text-gray-400">{{ selected.notes }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button @click="startEdit" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">Editar</button>
                                    <button @click="confirmDelete = true" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-100">Eliminar</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Prices -->
                    <div class="flex-1 overflow-y-auto p-6 space-y-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-gray-700">Precios Preferenciales</h3>
                            <button @click="showAddPrice = true; priceForm.reset();" class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                Agregar precio
                            </button>
                        </div>

                        <!-- Add price inline form -->
                        <div v-if="showAddPrice" class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-200">
                            <form @submit.prevent="submitPrice" class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Producto</label>
                                    <select v-model="priceForm.product_id" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="">Seleccionar producto...</option>
                                        <option v-for="p in availableProducts" :key="p.id" :value="p.id">{{ p.name }} — ${{ parseFloat(p.price).toFixed(2) }}</option>
                                    </select>
                                    <p v-if="priceForm.errors.product_id" class="mt-1 text-xs text-red-600">{{ priceForm.errors.product_id }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-gray-500">Precio preferencial</label>
                                    <input v-model.number="priceForm.price" type="number" step="0.01" min="0" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                    <p v-if="priceForm.errors.price" class="mt-1 text-xs text-red-600">{{ priceForm.errors.price }}</p>
                                </div>
                                <div class="flex gap-3">
                                    <button type="submit" :disabled="priceForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Asignar</button>
                                    <button type="button" @click="showAddPrice = false" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                                </div>
                            </form>
                        </div>

                        <!-- Prices table -->
                        <div v-if="selected.prices && selected.prices.length > 0" class="overflow-hidden rounded-lg ring-1 ring-gray-100">
                            <table class="min-w-full divide-y divide-gray-50">
                                <thead><tr class="bg-gray-50">
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Producto</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Precio estandar</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Precio preferencial</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Acciones</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="pp in selected.prices" :key="pp.id">
                                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900">{{ pp.product?.name || 'Producto eliminado' }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm text-gray-500">${{ pp.product ? parseFloat(pp.product.price).toFixed(2) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <template v-if="editingPriceId === pp.id">
                                                <form @submit.prevent="submitEditPrice(pp.id)" class="inline-flex items-center gap-2">
                                                    <input v-model.number="editPriceForm.price" type="number" step="0.01" min="0" class="w-24 rounded-lg border-gray-200 py-1 text-right text-sm focus:border-red-400 focus:ring-red-300" />
                                                    <button type="submit" :disabled="editPriceForm.processing" class="text-xs font-semibold text-red-600 hover:text-red-700">Ok</button>
                                                    <button type="button" @click="editingPriceId = null" class="text-xs text-gray-400 hover:text-gray-600">X</button>
                                                </form>
                                            </template>
                                            <template v-else>
                                                <span class="text-sm font-semibold" :class="pp.product && parseFloat(pp.price) < parseFloat(pp.product.price) ? 'text-green-600' : pp.product && parseFloat(pp.price) > parseFloat(pp.product.price) ? 'text-red-600' : 'text-gray-900'">
                                                    ${{ parseFloat(pp.price).toFixed(2) }}
                                                </span>
                                            </template>
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <template v-if="editingPriceId !== pp.id">
                                                <button @click="startEditPrice(pp)" class="text-xs font-semibold text-orange-600 hover:text-orange-700">Editar</button>
                                                <button @click="deletePrice(pp.id)" class="ml-2 text-xs text-gray-400 hover:text-red-600">Eliminar</button>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else-if="!showAddPrice" class="rounded-xl border border-dashed border-gray-200 px-6 py-10 text-center">
                            <p class="text-sm text-gray-400">Sin precios preferenciales asignados.</p>
                            <button @click="showAddPrice = true; priceForm.reset();" class="mt-3 text-xs font-semibold text-red-600 hover:text-red-700">Agregar el primero</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Create customer modal -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showCreateModal = false">
                    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl" @click.stop>
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-base font-bold text-gray-900">Nuevo Cliente</h3>
                        </div>
                        <form @submit.prevent="submitCreate" class="px-6 py-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Nombre</label>
                                <input v-model="createForm.name" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-600">{{ createForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Telefono</label>
                                <input v-model="createForm.phone" type="text" required class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="createForm.errors.phone" class="mt-1 text-xs text-red-600">{{ createForm.errors.phone }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-gray-500">Notas (opcional)</label>
                                <textarea v-model="createForm.notes" rows="2" class="w-full rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
                                <button type="button" @click="showCreateModal = false" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancelar</button>
                                <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Registrar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Delete confirm -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="confirmDelete = false">
                    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-center" @click.stop>
                        <p class="text-sm font-semibold text-gray-900">Eliminar cliente "{{ selected?.name }}"?</p>
                        <p class="mt-1 text-xs text-gray-500">Si tiene ventas asociadas, sera desactivado en vez de eliminado.</p>
                        <div class="mt-4 flex justify-center gap-3">
                            <button @click="confirmDelete = false" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancelar</button>
                            <button @click="deleteCustomer" class="rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700">Eliminar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <FlashToast />
    </SucursalLayout>
</template>
