<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import ProductDetailModal from '@/Components/Productos/ProductDetailModal.vue';
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

// ─── Modal de detalle ─────────────────────────────────────────────────
const detailModalOpen = ref(false);
const selectedProductId = ref(null);
const selectedProduct = computed(() => {
    if (!selectedProductId.value) return null;
    return (props.productos?.data || []).find(p => p.id === selectedProductId.value) || null;
});
const openDetail = (p) => {
    selectedProductId.value = p.id;
    detailModalOpen.value = true;
};
const closeDetail = () => {
    detailModalOpen.value = false;
    setTimeout(() => { selectedProductId.value = null; }, 300);
};

// ─── Helpers de fila ──────────────────────────────────────────────────
const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const marginFor = (p) => {
    if (!p?.cost_price || !p?.price) return null;
    const cost = Number(p.cost_price);
    const price = Number(p.price);
    if (price <= 0 || cost <= 0) return null;
    const pct = ((price - cost) / price) * 100;
    return { amount: price - cost, pct: Math.round(pct) };
};

const saleModeChip = (mode) => ({
    weight: { label: 'Peso', icon: 'scale' },
    presentation: { label: 'Presentación', icon: 'box' },
    both: { label: 'Peso y presentación', icon: 'layers' },
}[mode] || { label: mode || '—', icon: 'tag' });

const unitTypeShort = (t) => ({ kg: 'kg', piece: 'pz', cut: 'corte' }[t] || t || '');

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
const confirmDeleteCategory = computed(() => (props.categoriesForTab || []).find(c => c.id === confirmDeleteId.value) || null);
const askDelete = (cat) => {
    if (cat.products_count > 0) return; // bloqueo duro
    confirmDeleteId.value = cat.id;
};
const doDelete = () => {
    if (!confirmDeleteId.value) return;
    router.delete(route('sucursal.categorias.destroy', [props.tenant.slug, confirmDeleteId.value]), {
        preserveScroll: true,
        onFinish: () => { confirmDeleteId.value = null; },
    });
};

// Toggle de status inline (sin entrar a edit). Reusa el endpoint update
// que exige name + status; envía el name actual sin cambios.
const togglingStatusId = ref(null);
const toggleCategoryStatus = (cat) => {
    togglingStatusId.value = cat.id;
    router.put(route('sucursal.categorias.update', [props.tenant.slug, cat.id]), {
        name: cat.name,
        status: cat.status === 'active' ? 'inactive' : 'active',
    }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { togglingStatusId.value = null; },
    });
};

// Avatar de color por categoría (hash determinístico del nombre).
const categoryColors = [
    { bg: 'from-rose-500 to-red-600', ring: 'ring-rose-100' },
    { bg: 'from-orange-500 to-amber-600', ring: 'ring-orange-100' },
    { bg: 'from-amber-400 to-yellow-500', ring: 'ring-amber-100' },
    { bg: 'from-emerald-500 to-teal-600', ring: 'ring-emerald-100' },
    { bg: 'from-sky-500 to-blue-600', ring: 'ring-sky-100' },
    { bg: 'from-indigo-500 to-violet-600', ring: 'ring-indigo-100' },
    { bg: 'from-purple-500 to-fuchsia-600', ring: 'ring-purple-100' },
    { bg: 'from-pink-500 to-rose-500', ring: 'ring-pink-100' },
];
const colorFor = (name) => {
    let hash = 0;
    const s = String(name || '');
    for (let i = 0; i < s.length; i++) {
        hash = ((hash << 5) - hash) + s.charCodeAt(i);
        hash |= 0;
    }
    return categoryColors[Math.abs(hash) % categoryColors.length];
};
const initialsFor = (name) => {
    const words = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
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
                <div class="space-y-3">
                    <!-- Search + create -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <input v-model="search" type="text" placeholder="Buscar producto..." class="w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 transition focus:border-red-400 focus:ring-red-300 sm:w-72" />
                        </div>
                        <Link :href="route('sucursal.productos.create', tenant.slug)" class="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 active:bg-red-800">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo Producto
                        </Link>
                    </div>

                    <!-- Category filter chips (horizontal scroll, no scrollbar) -->
                    <div v-if="(categories || []).length > 0" class="relative -mx-1">
                        <div class="flex items-center gap-2 overflow-x-auto scroll-smooth px-1 pb-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                            <button type="button" @click="categoryFilter = ''"
                                :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                                    categoryFilter === ''
                                        ? 'bg-gray-900 text-white shadow-sm'
                                        : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50 hover:ring-gray-300']">
                                Todas
                                <span :class="['rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                                    categoryFilter === '' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500']">
                                    {{ productos?.total ?? 0 }}
                                </span>
                            </button>
                            <button v-for="c in categories" :key="c.id" type="button" @click="categoryFilter = String(c.id)"
                                :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                                    String(categoryFilter) === String(c.id)
                                        ? 'bg-orange-600 text-white shadow-sm'
                                        : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-orange-50 hover:text-orange-700 hover:ring-orange-200']">
                                {{ c.name }}
                            </button>
                        </div>
                        <!-- Edge fade hint for overflow -->
                        <div class="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-gray-50 to-transparent" />
                    </div>
                </div>

                <!-- Product list -->
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="divide-y divide-gray-50">
                        <button v-for="p in productos.data" :key="p.id" type="button" @click="openDetail(p)"
                            class="group flex w-full items-start gap-4 px-5 py-4 text-left transition hover:bg-gray-50/80 active:bg-gray-100/80">
                            <!-- Thumbnail (80px) -->
                            <div class="relative flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-gray-100 to-gray-50 ring-1 ring-gray-200/60">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" class="h-full w-full object-cover transition group-hover:scale-105" />
                                <svg v-else class="h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 0 0 2.25-2.25V5.25a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                                <span v-if="p.status === 'inactive'" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-gray-900/55 text-[10px] font-bold uppercase tracking-wider text-white">Inactivo</span>
                            </div>

                            <!-- Center: name + description -->
                            <div class="min-w-0 flex-1 pt-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="truncate text-base font-bold text-gray-900">{{ p.name }}</h3>
                                    <span v-if="p.category" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-semibold text-orange-700 ring-1 ring-inset ring-orange-600/15">
                                        {{ p.category.name }}
                                    </span>
                                </div>
                                <p :class="['mt-1 text-xs leading-relaxed', p.description ? 'text-gray-500' : 'italic text-gray-300']" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                    {{ p.description || 'Sin descripción' }}
                                </p>

                                <!-- Metadata chips row -->
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <!-- Sale mode -->
                                    <span class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700">
                                        <svg v-if="p.sale_mode === 'weight'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                                        <svg v-else-if="p.sale_mode === 'presentation'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25" /></svg>
                                        <svg v-else class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25" /></svg>
                                        {{ saleModeChip(p.sale_mode).label }}
                                    </span>
                                    <!-- Presentations count -->
                                    <span v-if="p.presentations_count > 0" class="inline-flex items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.5 16a3.5 3.5 0 0 1-1.4-6.7L13 4.7a3.5 3.5 0 1 1 4 4l-4.6 4.6A3.5 3.5 0 0 1 5.5 16Z" clip-rule="evenodd" /></svg>
                                        {{ p.presentations_count }} presentación{{ p.presentations_count !== 1 ? 'es' : '' }}
                                    </span>
                                    <!-- Online -->
                                    <span v-if="p.visible_online" class="inline-flex items-center gap-1 rounded-md bg-cyan-50 px-1.5 py-0.5 text-[10px] font-semibold text-cyan-700">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582" /></svg>
                                        Online
                                    </span>
                                    <!-- Restricted -->
                                    <span v-if="p.visibility !== 'public'" class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" /></svg>
                                        Restringido
                                    </span>
                                </div>
                            </div>

                            <!-- Right: price + margin -->
                            <div class="shrink-0 pt-1 text-right">
                                <p class="font-mono text-lg font-extrabold tabular-nums text-gray-900">{{ money(p.price) }}</p>
                                <p v-if="unitTypeShort(p.unit_type)" class="text-[11px] font-medium text-gray-400">/{{ unitTypeShort(p.unit_type) }}</p>
                                <div v-if="marginFor(p)" class="mt-1.5 inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>
                                    {{ marginFor(p).pct }}% margen
                                </div>
                            </div>

                            <!-- Chevron -->
                            <svg class="ml-1 mt-2 h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>

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
            <div v-else-if="activeTab === 'categorias'" class="mx-auto max-w-2xl space-y-4">
                <!-- Toolbar -->
                <div class="flex items-center gap-3">
                    <div class="relative flex-1">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="categorySearch" type="text" placeholder="Buscar categoría..." class="w-full rounded-xl border-gray-200 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-700 placeholder-gray-400 transition focus:border-red-400 focus:ring-red-300" />
                    </div>
                    <button @click="showCreate = !showCreate"
                        :class="['inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold shadow-sm transition active:scale-95',
                            showCreate ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-red-600 text-white hover:bg-red-700']">
                        <svg :class="['h-4 w-4 transition', showCreate ? 'rotate-45' : '']" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ showCreate ? 'Cancelar' : 'Nueva' }}
                    </button>
                </div>

                <!-- Inline create -->
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    leave-active-class="transition duration-150 ease-in"
                    enter-from-class="opacity-0 -translate-y-2"
                    leave-to-class="opacity-0 -translate-y-2">
                    <form v-if="showCreate" @submit.prevent="submitCreate"
                        class="rounded-2xl bg-gradient-to-br from-red-50/80 to-orange-50/60 p-4 ring-1 ring-red-100">
                        <label class="text-[10px] font-bold uppercase tracking-[0.15em] text-red-700/70">Nombre de la nueva categoría</label>
                        <div class="mt-2 flex items-center gap-2">
                            <TextInput v-model="createForm.name" type="text" class="flex-1 border-red-200 bg-white" required autofocus placeholder="Ej: Res, Cerdo, Pollo..." />
                            <button type="submit" :disabled="createForm.processing || !createForm.name"
                                class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40">
                                Crear
                            </button>
                        </div>
                        <InputError :message="createForm.errors.name" class="mt-2" />
                    </form>
                </Transition>

                <!-- List -->
                <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="divide-y divide-gray-50">
                        <div v-for="cat in filteredCategories" :key="cat.id"
                            :class="['transition', editingId === cat.id ? 'bg-gradient-to-r from-red-50/40 to-transparent' : 'hover:bg-gray-50/70']">

                            <!-- Edit mode -->
                            <form v-if="editingId === cat.id" @submit.prevent="submitEdit(cat.id)" class="space-y-3 px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-sm font-bold text-white shadow-sm ring-2', colorFor(editForm.name || cat.name).bg, colorFor(editForm.name || cat.name).ring]">
                                        {{ initialsFor(editForm.name || cat.name) }}
                                    </div>
                                    <div class="flex-1">
                                        <label class="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">Nombre</label>
                                        <TextInput v-model="editForm.name" type="text" class="mt-1 block w-full" required />
                                        <InputError :message="editForm.errors.name" class="mt-1" />
                                    </div>
                                </div>
                                <div class="flex items-center justify-between gap-3 pl-[60px]">
                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                        <input type="checkbox" :checked="editForm.status === 'active'"
                                            @change="editForm.status = ($event.target.checked ? 'active' : 'inactive')"
                                            class="rounded border-gray-300 text-red-600 focus:ring-red-300" />
                                        Activa
                                    </label>
                                    <div class="flex gap-2">
                                        <button type="button" @click="cancelEdit" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-100">Cancelar</button>
                                        <button type="submit" :disabled="editForm.processing" class="rounded-xl bg-red-600 px-5 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95 disabled:opacity-50">Guardar</button>
                                    </div>
                                </div>
                            </form>

                            <!-- Read mode -->
                            <div v-else class="group flex items-center gap-4 px-5 py-3.5">
                                <!-- Avatar gradient -->
                                <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-sm font-bold text-white shadow-sm ring-2 transition group-hover:scale-105', colorFor(cat.name).bg, colorFor(cat.name).ring, cat.status !== 'active' && 'opacity-50 grayscale']">
                                    {{ initialsFor(cat.name) }}
                                </div>
                                <!-- Info -->
                                <div class="min-w-0 flex-1">
                                    <p :class="['text-sm font-bold', cat.status === 'active' ? 'text-gray-900' : 'text-gray-500']">{{ cat.name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">
                                        <span class="tabular-nums">{{ cat.products_count }}</span>
                                        producto{{ cat.products_count !== 1 ? 's' : '' }}
                                        <span v-if="cat.status !== 'active'" class="ml-1 text-amber-600">· inactiva</span>
                                    </p>
                                </div>
                                <!-- Status toggle (iOS-style) -->
                                <button type="button" @click="toggleCategoryStatus(cat)"
                                    :disabled="togglingStatusId === cat.id"
                                    :title="cat.status === 'active' ? 'Desactivar categoría' : 'Activar categoría'"
                                    class="shrink-0 disabled:opacity-50">
                                    <span :class="['relative inline-flex h-7 w-[52px] items-center rounded-full transition-colors', cat.status === 'active' ? 'bg-emerald-500' : 'bg-gray-300']">
                                        <span :class="['inline-block h-6 w-6 transform rounded-full bg-white shadow-md transition', cat.status === 'active' ? 'translate-x-[24px]' : 'translate-x-0.5']" />
                                    </span>
                                </button>
                                <!-- Actions: tamaño táctil estándar (44px) y siempre visibles -->
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button type="button" @click="startEdit(cat)" title="Editar"
                                        class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gray-50 text-gray-500 transition hover:bg-blue-50 hover:text-blue-600 active:scale-95">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" @click="askDelete(cat)" :disabled="cat.products_count > 0"
                                        :title="cat.products_count > 0 ? `Tiene ${cat.products_count} producto${cat.products_count !== 1 ? 's' : ''}: no se puede eliminar` : 'Eliminar'"
                                        :class="['flex h-11 w-11 items-center justify-center rounded-2xl transition active:scale-95',
                                            cat.products_count > 0
                                                ? 'cursor-not-allowed bg-gray-50 text-gray-300'
                                                : 'bg-gray-50 text-gray-500 hover:bg-red-50 hover:text-red-600']">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div v-if="filteredCategories.length === 0" class="px-6 py-16 text-center">
                            <template v-if="categorySearch">
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100">
                                    <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                </div>
                                <p class="mt-3 text-sm font-medium text-gray-500">Sin coincidencias</p>
                                <p class="mt-1 text-xs text-gray-400">Ninguna categoría coincide con "{{ categorySearch }}".</p>
                            </template>
                            <template v-else>
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-red-100 to-orange-100">
                                    <svg class="h-7 w-7 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" /></svg>
                                </div>
                                <p class="mt-4 text-sm font-bold text-gray-900">Aún no tienes categorías</p>
                                <p class="mt-1 text-xs text-gray-500">Crea al menos una para organizar tus productos.</p>
                                <button type="button" @click="showCreate = true"
                                    class="mt-5 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 active:scale-95">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    Crear primera categoría
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Confirm delete -->
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    leave-active-class="transition duration-150 ease-in"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0">
                    <div v-if="confirmDeleteId" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" @click.self="confirmDeleteId = null">
                        <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl">
                            <div class="flex items-center gap-3">
                                <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-sm font-bold text-white shadow-sm', confirmDeleteCategory ? colorFor(confirmDeleteCategory.name).bg : 'from-gray-400 to-gray-500']">
                                    {{ confirmDeleteCategory ? initialsFor(confirmDeleteCategory.name) : '?' }}
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-base font-bold text-gray-900">Eliminar categoría</h3>
                                    <p class="text-sm text-gray-500">{{ confirmDeleteCategory?.name }}</p>
                                </div>
                            </div>
                            <p class="mt-4 text-sm leading-relaxed text-gray-600">Esta acción no se puede deshacer.</p>
                            <div class="mt-5 flex justify-end gap-2">
                                <button type="button" @click="confirmDeleteId = null" class="rounded-xl bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200">Cancelar</button>
                                <button type="button" @click="doDelete" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">Eliminar</button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <ProductDetailModal :show="detailModalOpen" :product="selectedProduct" :tenant="tenant" @close="closeDetail" />

        <FlashToast />
    </SucursalLayout>
</template>
