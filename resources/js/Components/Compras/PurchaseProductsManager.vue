<script setup>
import ProductoCompraFormModal from '@/Components/Compras/ProductoCompraFormModal.vue';
import ProductoCompraHistorialDrawer from '@/Components/Compras/ProductoCompraHistorialDrawer.vue';
import PurchaseProductCategoriesManager from '@/Components/Compras/PurchaseProductCategoriesManager.vue';
import FusionarProductosModal from '@/Components/Compras/FusionarProductosModal.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    products: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    categoryRows: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, active: 0, inactive: 0, uncategorized: 0 }) },
    routePrefix: { type: String, default: 'empresa' },
    canDelete: { type: Boolean, default: false },
    canMerge: { type: Boolean, default: false },
});

const activeTab = ref('productos');

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const flash = computed(() => page.props.flash || {});

const search = ref(props.filters?.q || '');
const statusFilter = ref(props.filters?.status || 'active');
const categoryFilter = ref(props.filters?.category || '');

let t;
const navigate = () => {
    router.get(route(`${props.routePrefix}.productos-compra.index`, slug.value), {
        q: search.value || undefined,
        status: statusFilter.value !== 'active' ? statusFilter.value : undefined,
        category: categoryFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
watch(search, () => { clearTimeout(t); t = setTimeout(navigate, 300); });
watch(categoryFilter, navigate);
const setStatus = (k) => { statusFilter.value = k; navigate(); };

// Form (crear/editar)
const formOpen = ref(false);
const editing = ref(null);
const openCreate = () => { editing.value = null; formOpen.value = true; };
const openEdit = (p) => { editing.value = { ...p }; formOpen.value = true; openKebab.value = null; };

// Historial
const historyOpen = ref(false);
const historyProduct = ref(null);
const openHistory = (p) => { historyProduct.value = { id: p.id, name: p.name }; historyOpen.value = true; openKebab.value = null; };

// Menú ⋯
const openKebab = ref(null);
const toggleKebab = (id) => { openKebab.value = openKebab.value === id ? null : id; };

// Fusionar duplicados
const showMerge = ref(false);
const onMerged = () => router.reload({ only: ['products', 'stats'] });

// Confirmación de borrado
const confirmDelete = ref(null);
const deleting = ref(false);
const askDelete = (p) => { confirmDelete.value = p; openKebab.value = null; };
const doDelete = () => {
    const p = confirmDelete.value;
    deleting.value = true;
    router.delete(route(`${props.routePrefix}.productos-compra.destroy`, { tenant: slug.value, producto_compra: p.id }), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; confirmDelete.value = null; },
    });
};

const STATUS_TABS = [
    { key: 'active', label: 'Activos' },
    { key: 'inactive', label: 'Inactivos' },
    { key: 'all', label: 'Todos' },
];

// Color del badge autoasignado por id (determinista), sin pedir color al usuario.
const CAT_PALETTE = [
    'bg-red-100 text-red-700', 'bg-pink-100 text-pink-700', 'bg-amber-100 text-amber-700',
    'bg-indigo-100 text-indigo-700', 'bg-teal-100 text-teal-700', 'bg-purple-100 text-purple-700',
    'bg-sky-100 text-sky-700', 'bg-lime-100 text-lime-700',
];
const catClass = (id) => id == null ? 'bg-gray-100 text-gray-400' : CAT_PALETTE[id % CAT_PALETTE.length];

const relTime = (iso) => {
    if (!iso) return null;
    const d = new Date(iso);
    const s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 60) return 'hace un momento';
    const m = Math.floor(s / 60);
    if (m < 60) return `hace ${m} min`;
    const h = Math.floor(m / 60);
    if (h < 24) return `hace ${h} h`;
    const days = Math.floor(h / 24);
    if (days < 30) return `hace ${days} d`;
    return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
};
</script>

<template>
    <div class="space-y-5">
        <!-- Pestañas -->
        <div class="flex gap-1 border-b border-gray-200">
            <button type="button" @click="activeTab = 'productos'"
                :class="['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition', activeTab === 'productos' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700']">
                Productos
            </button>
            <button type="button" @click="activeTab = 'categorias'"
                :class="['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition', activeTab === 'categorias' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700']">
                Categorías
            </button>
        </div>

        <div v-show="activeTab === 'productos'" class="space-y-5">
        <!-- Resumen -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-2xl font-extrabold leading-none text-gray-900">{{ stats.total }}</div>
                <div class="mt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Total</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-2xl font-extrabold leading-none text-emerald-600">{{ stats.active }}</div>
                <div class="mt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Activos</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-2xl font-extrabold leading-none text-gray-500">{{ stats.inactive }}</div>
                <div class="mt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Inactivos</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-2xl font-extrabold leading-none text-orange-600">{{ stats.uncategorized }}</div>
                <div class="mt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Sin categoría</div>
            </div>
        </div>

        <!-- Barra de herramientas -->
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
                <input v-model="search" type="text" placeholder="Buscar producto…"
                    class="w-full rounded-xl border-gray-300 pl-9 text-sm focus:border-orange-500 focus:ring-orange-500" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1 rounded-xl bg-gray-100 p-1">
                    <button v-for="tab in STATUS_TABS" :key="tab.key" @click="setStatus(tab.key)"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-semibold transition', statusFilter === tab.key ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900']">
                        {{ tab.label }}
                    </button>
                </div>
                <select v-model="categoryFilter"
                    class="rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                    <option value="">Todas las categorías</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
                <div class="ml-auto flex gap-2">
                    <button v-if="canMerge" @click="showMerge = true"
                        class="rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        Fusionar duplicados
                    </button>
                    <button @click="openCreate"
                        class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">
                        + Nuevo producto
                    </button>
                </div>
            </div>
        </div>

        <div v-if="flash.success" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flash.success }}</div>

        <!-- Tabla -->
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Producto</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Categoría</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Compras</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Última edición</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Estado</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="p in products.data" :key="p.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900">{{ p.name }}</div>
                            <div class="text-xs uppercase text-gray-400">{{ p.unit }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['inline-block rounded-full px-2.5 py-0.5 text-xs font-bold', catClass(p.category_id)]">
                                {{ p.category_label || 'Sin categoría' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="inline-block min-w-[28px] rounded-lg bg-gray-100 px-2 py-0.5 text-center text-xs font-bold text-gray-700">{{ p.purchase_items_count }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <button v-if="p.last_edited" @click="openHistory(p)"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-800">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" stroke-linecap="round" /></svg>
                                <span class="border-b border-dotted border-blue-300">{{ p.last_edited.by || 'Usuario' }} · {{ relTime(p.last_edited.at) }}</span>
                            </button>
                            <span v-else class="text-xs text-gray-300">— sin editar —</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="p.status === 'active'" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Activo
                            </span>
                            <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-500">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Inactivo
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="openEdit(p)" title="Editar"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-orange-600 hover:border-gray-200 hover:bg-gray-50">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16.86 4.49l1.69-1.69a1.875 1.875 0 1 1 2.65 2.65L10.58 16.07a4.5 4.5 0 0 1-1.9 1.13L6 18l.8-2.69a4.5 4.5 0 0 1 1.13-1.9l8.93-8.92z" stroke-linejoin="round" /></svg>
                                </button>
                                <div class="relative">
                                    <button @click="toggleKebab(p.id)" title="Más"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-gray-500 hover:border-gray-200 hover:bg-gray-50">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="19" r="1.6" /></svg>
                                    </button>
                                    <div v-if="openKebab === p.id" class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
                                        <button @click="openHistory(p)" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                            <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" stroke-linecap="round" /></svg>
                                            Ver historial
                                        </button>
                                        <button v-if="canDelete" @click="askDelete(p)" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 7h12M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7m-7 0 .7 11a2 2 0 0 0 2 1.9h4.6a2 2 0 0 0 2-1.9L18 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length">
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-500">
                            Sin productos.
                            <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Agregar el primero</button>.
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="products.last_page > 1" class="flex flex-col items-center gap-2 border-t border-gray-100 px-4 py-4 sm:flex-row sm:justify-between">
                <p class="text-xs text-gray-500">{{ products.from }}–{{ products.to }} de {{ products.total }}</p>
                <div class="flex flex-wrap justify-center gap-1">
                    <Link v-for="link in products.links" :key="link.label" :href="link.url || '#'" preserve-scroll
                        :class="['rounded-lg px-3 py-1.5 text-sm font-medium transition', link.active ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                        v-html="link.label" />
                </div>
            </div>
        </div>

        <!-- Backdrop para cerrar el menú ⋯ al hacer clic fuera -->
        <div v-if="openKebab !== null" class="fixed inset-0 z-10" @click="openKebab = null"></div>

        <ProductoCompraFormModal :open="formOpen" :product="editing" :categories="categories" :route-prefix="routePrefix" @close="formOpen = false" />
        <ProductoCompraHistorialDrawer :open="historyOpen" :product="historyProduct" :route-prefix="routePrefix" @close="historyOpen = false" />
        <FusionarProductosModal :open="showMerge" :tenant-slug="slug" @close="showMerge = false" @merged="onMerged" />

        <!-- Confirmación de borrado -->
        <Teleport to="body">
            <Transition enter-active-class="transition" leave-active-class="transition" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click="confirmDelete = null">
                    <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl" @click.stop>
                        <h3 class="text-base font-bold text-gray-900">Eliminar producto</h3>
                        <p class="mt-2 text-sm text-gray-600">¿Eliminar <span class="font-semibold">{{ confirmDelete.name }}</span>? Esta acción no se puede deshacer.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button @click="confirmDelete = null" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                            <button @click="doDelete" :disabled="deleting" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">{{ deleting ? 'Eliminando…' : 'Eliminar' }}</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
        </div>

        <div v-show="activeTab === 'categorias'">
            <PurchaseProductCategoriesManager :categories="categoryRows" :route-prefix="routePrefix" :can-delete="canDelete" />
        </div>
    </div>
</template>
