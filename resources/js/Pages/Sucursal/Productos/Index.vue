<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    productos: Object,
    categories: Array,
    categoriesForTab: Array,
    filters: Object,
    tab: { type: String, default: 'productos' },
    tenant: Object,
});

// ─── Tab state ────────────────────────────────────────────────────────
const activeTab = ref(props.tab === 'categorias' ? 'categorias' : 'productos');
const switchTab = (next) => {
    if (activeTab.value === next) return;
    activeTab.value = next;
    router.get(route('sucursal.productos.index', props.tenant.slug), { tab: next === 'categorias' ? 'categorias' : undefined }, {
        preserveState: false,
        preserveScroll: true,
        replace: true,
    });
};

// ─── Productos tab ────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category_id || '');

let debounce;
const applyFilters = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('sucursal.productos.index', props.tenant.slug), {
            search: search.value || undefined,
            category_id: categoryFilter.value || undefined,
        }, { preserveState: true, replace: true });
    }, 300);
};
watch(search, applyFilters);
watch(categoryFilter, applyFilters);

const visibilityBadge = (v) => v === 'public'
    ? { label: 'Publico', cls: 'bg-green-50 text-green-700 ring-green-600/20' }
    : { label: 'Restringido', cls: 'bg-amber-50 text-amber-700 ring-amber-600/20' };

// ─── Categorías tab ───────────────────────────────────────────────────
const categorySearch = ref('');
const filteredCategories = computed(() => {
    const list = props.categoriesForTab || [];
    const q = categorySearch.value.trim().toLowerCase();
    if (!q) return list;
    return list.filter(c => c.name.toLowerCase().includes(q));
});

const showCreate = ref(false);
const createForm = useForm({ name: '' });
const submitCreate = () => {
    createForm.post(route('sucursal.categorias.store', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => { createForm.reset(); showCreate.value = false; },
    });
};

const editingId = ref(null);
const editForm = useForm({ name: '', status: 'active' });
const startEdit = (cat) => { editingId.value = cat.id; editForm.name = cat.name; editForm.status = cat.status; editForm.clearErrors(); };
const cancelEdit = () => { editingId.value = null; };
const submitEdit = (id) => {
    editForm.put(route('sucursal.categorias.update', [props.tenant.slug, id]), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};

const confirmDeleteId = ref(null);
const askDelete = (cat) => {
    if (cat.products_count > 0) {
        // Bloqueo duro replicado en frontend para feedback inmediato.
        // El backend también lo bloquea; este chequeo evita el round-trip.
        return;
    }
    confirmDeleteId.value = cat.id;
};
const doDelete = () => {
    if (!confirmDeleteId.value) return;
    router.delete(route('sucursal.categorias.destroy', [props.tenant.slug, confirmDeleteId.value]), {
        preserveScroll: true,
        onFinish: () => { confirmDeleteId.value = null; },
    });
};

const productCount = computed(() => props.productos?.total ?? 0);
const categoryCount = computed(() => props.categoriesForTab?.length ?? 0);
</script>

<template>
    <Head title="Catalogo" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Catalogo</h1>
        </template>

        <div class="space-y-6">
            <!-- Tabs -->
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex gap-6" aria-label="Secciones del catálogo">
                    <button type="button" @click="switchTab('productos')"
                        :class="['relative inline-flex items-center gap-2 border-b-2 px-1 pb-3 pt-1 text-sm font-semibold transition',
                            activeTab === 'productos'
                                ? 'border-red-600 text-red-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700']">
                        Productos
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-bold tabular-nums',
                            activeTab === 'productos' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600']">
                            {{ productCount }}
                        </span>
                    </button>
                    <button type="button" @click="switchTab('categorias')"
                        :class="['relative inline-flex items-center gap-2 border-b-2 px-1 pb-3 pt-1 text-sm font-semibold transition',
                            activeTab === 'categorias'
                                ? 'border-red-600 text-red-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700']">
                        Categorías
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-bold tabular-nums',
                            activeTab === 'categorias' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600']">
                            {{ categoryCount }}
                        </span>
                    </button>
                </nav>
            </div>

            <!-- ========== TAB: PRODUCTOS ========== -->
            <div v-if="activeTab === 'productos'" class="space-y-6">
                <!-- Toolbar -->
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex gap-3">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <input v-model="search" type="text" placeholder="Buscar producto..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-64" />
                        </div>
                        <select v-model="categoryFilter" class="rounded-lg border-gray-200 py-2.5 text-sm text-gray-700 focus:border-red-400 focus:ring-red-300">
                            <option value="">Todas las categorias</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <Link :href="route('sucursal.productos.create', tenant.slug)" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nuevo Producto
                    </Link>
                </div>

                <!-- Product list -->
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="divide-y divide-gray-50">
                        <div v-for="p in productos.data" :key="p.id" class="flex items-center gap-4 px-6 py-4 transition hover:bg-gray-50">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-200/50">
                                <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" />
                                <svg v-else class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 0 0 2.25-2.25V5.25a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-bold text-gray-900">{{ p.name }}</p>
                                    <span v-if="p.category" class="shrink-0 rounded-full bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-700 ring-1 ring-orange-600/10">{{ p.category.name }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400">{{ p.description || 'Sin descripcion' }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-base font-bold text-gray-900">${{ parseFloat(p.price).toFixed(2) }}</p>
                                <p v-if="p.cost_price" class="text-xs text-gray-400">Costo: ${{ parseFloat(p.cost_price).toFixed(2) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span :class="visibilityBadge(p.visibility).cls" class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ visibilityBadge(p.visibility).label }}</span>
                                <span :class="p.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ p.status === 'active' ? 'Activo' : 'Inactivo' }}</span>
                            </div>
                            <Link :href="route('sucursal.productos.edit', [tenant.slug, p.id])" class="shrink-0 text-sm font-semibold text-red-600 transition hover:text-red-700">Editar</Link>
                        </div>

                        <div v-if="productos.data.length === 0" class="px-6 py-20 text-center">
                            <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                            <p class="mt-3 text-sm font-medium text-gray-400">No se encontraron productos.</p>
                            <button v-if="categoryCount === 0" type="button" @click="switchTab('categorias')"
                                class="mt-4 inline-flex items-center gap-2 rounded-lg bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100">
                                Crear primero una categoría
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25 21 12m0 0-3.75 3.75M21 12H3" /></svg>
                            </button>
                        </div>
                    </div>

                    <div v-if="productos.last_page > 1" class="flex justify-center border-t border-gray-100 px-6 py-4">
                        <div class="flex gap-1">
                            <Link v-for="link in productos.links" :key="link.label" :href="link.url || '#'"
                                :class="['rounded-lg px-3.5 py-2 text-sm font-medium transition', link.active ? 'bg-red-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100', !link.url && 'pointer-events-none opacity-40']"
                                v-html="link.label" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== TAB: CATEGORÍAS ========== -->
            <div v-else-if="activeTab === 'categorias'" class="mx-auto max-w-3xl space-y-6">
                <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="flex flex-col gap-3 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <input v-model="categorySearch" type="text" placeholder="Buscar categoria..." class="w-full rounded-lg border-gray-200 py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 focus:border-red-400 focus:ring-red-300 sm:w-64" />
                        </div>
                        <button @click="showCreate = !showCreate" class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nueva Categoría
                        </button>
                    </div>

                    <!-- Inline create -->
                    <div v-if="showCreate" class="border-b border-gray-100 px-6 py-4">
                        <form @submit.prevent="submitCreate" class="flex items-end gap-3">
                            <div class="flex-1">
                                <label class="text-xs font-medium text-gray-500">Nombre</label>
                                <TextInput v-model="createForm.name" type="text" class="mt-1 block w-full" required autofocus placeholder="Ej: Res, Cerdo, Pollo..." />
                                <InputError :message="createForm.errors.name" class="mt-1" />
                            </div>
                            <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">Crear</button>
                            <button type="button" @click="showCreate = false; createForm.reset(); createForm.clearErrors();" class="rounded-lg px-3 py-2.5 text-sm text-gray-500 hover:bg-gray-100">Cancelar</button>
                        </form>
                    </div>

                    <!-- List -->
                    <div class="divide-y divide-gray-50">
                        <div v-for="cat in filteredCategories" :key="cat.id" class="flex items-center gap-4 px-6 py-4 transition hover:bg-gray-50">
                            <template v-if="editingId === cat.id">
                                <form @submit.prevent="submitEdit(cat.id)" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end">
                                    <div class="flex-1">
                                        <TextInput v-model="editForm.name" type="text" class="block w-full" required />
                                        <InputError :message="editForm.errors.name" class="mt-1" />
                                    </div>
                                    <select v-model="editForm.status" class="rounded-lg border-gray-200 text-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="active">Activa</option>
                                        <option value="inactive">Inactiva</option>
                                    </select>
                                    <div class="flex gap-2">
                                        <button type="submit" :disabled="editForm.processing" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                                        <button type="button" @click="cancelEdit" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                                    </div>
                                </form>
                            </template>
                            <template v-else>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-gray-900">{{ cat.name }}</p>
                                    <p class="text-xs text-gray-400">{{ cat.products_count }} producto{{ cat.products_count !== 1 ? 's' : '' }}</p>
                                </div>
                                <span :class="cat.status === 'active' ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'" class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ cat.status === 'active' ? 'Activa' : 'Inactiva' }}</span>
                                <button @click="startEdit(cat)" class="text-sm font-semibold text-red-600 hover:text-red-700">Editar</button>
                                <button type="button" @click="askDelete(cat)" :disabled="cat.products_count > 0"
                                    :title="cat.products_count > 0 ? `No se puede eliminar: tiene ${cat.products_count} producto${cat.products_count !== 1 ? 's' : ''}.` : 'Eliminar categoría'"
                                    :class="['text-sm transition', cat.products_count > 0 ? 'cursor-not-allowed text-gray-300' : 'text-gray-400 hover:text-red-600']">
                                    Eliminar
                                </button>
                            </template>
                        </div>
                        <div v-if="filteredCategories.length === 0" class="px-6 py-16 text-center text-sm text-gray-400">
                            <template v-if="categorySearch">Ninguna categoría coincide con "{{ categorySearch }}".</template>
                            <template v-else>
                                <p class="font-medium text-gray-500">Aún no tienes categorías.</p>
                                <p class="mt-1 text-xs">Crea al menos una para poder organizar tus productos.</p>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Confirm delete -->
                <div v-if="confirmDeleteId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="confirmDeleteId = null">
                    <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
                        <h3 class="text-base font-bold text-gray-900">Eliminar categoría</h3>
                        <p class="mt-2 text-sm text-gray-500">Esta acción no se puede deshacer.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" @click="confirmDeleteId = null" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Cancelar</button>
                            <button type="button" @click="doDelete" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Eliminar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <FlashToast />
    </SucursalLayout>
</template>
