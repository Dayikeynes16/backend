<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import DateField from '@/Components/DateField.vue';
import GastoFormModal from '@/Components/Gastos/GastoFormModal.vue';
import GastoDetailModal from '@/Components/Gastos/GastoDetailModal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { localToday } from '@/utils/date';

const props = defineProps({
    expenses: Object,
    totals: Object,
    categories: Array,
    branches: Array,
    filters: Object,
    tab: { type: String, default: 'gastos' },
    tenant: Object,
});

// --- Tabs ---
const activeTab = ref(props.tab || 'gastos');
const switchTab = (t) => {
    activeTab.value = t;
    router.get(route('empresa.gastos.index', props.tenant.slug), {
        ...currentFilters(), tab: t,
    }, { preserveState: true, replace: true });
};

// --- Filters ---
const search = ref(props.filters?.search || '');
const branchFilter = ref(props.filters?.branch_id || '');
const categoryFilter = ref(props.filters?.expense_category_id || '');
const subcategoryFilter = ref(props.filters?.expense_subcategory_id || '');
const dateRange = ref({
    from: props.filters?.from || localToday(),
    to: props.filters?.to || localToday(),
});

const subcategoriesForFilter = computed(() => {
    if (!categoryFilter.value) return [];
    const cat = props.categories.find(c => c.id === Number(categoryFilter.value));
    return cat?.subcategories || [];
});

watch(categoryFilter, () => { subcategoryFilter.value = ''; });

const currentFilters = () => ({
    search: search.value || undefined,
    branch_id: branchFilter.value || undefined,
    expense_category_id: categoryFilter.value || undefined,
    expense_subcategory_id: subcategoryFilter.value || undefined,
    from: dateRange.value?.from || undefined,
    to: dateRange.value?.to || undefined,
});

let debounceTimer;
const applyFilters = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('empresa.gastos.index', props.tenant.slug), {
            ...currentFilters(), tab: activeTab.value,
        }, { preserveState: true, replace: true });
    }, 300);
};
watch([search, branchFilter, categoryFilter, subcategoryFilter, dateRange], applyFilters, { deep: true });

const clearFilters = () => {
    search.value = '';
    branchFilter.value = '';
    categoryFilter.value = '';
    subcategoryFilter.value = '';
    dateRange.value = { from: localToday(), to: localToday() };
};

// --- Form modal ---
const formOpen = ref(false);
const formMode = ref('create');
const editingExpense = ref(null);
const openCreate = () => { formMode.value = 'create'; editingExpense.value = null; formOpen.value = true; };
const openEdit = (e) => { formMode.value = 'edit'; editingExpense.value = e; formOpen.value = true; detailOpen.value = false; };
const submitRouteName = computed(() => formMode.value === 'edit' ? 'empresa.gastos.update' : 'empresa.gastos.store');

// --- Detail modal ---
const detailOpen = ref(false);
const detailExpense = ref(null);
const openDetail = (e) => { detailExpense.value = e; detailOpen.value = true; };

// --- Delete ---
const confirmDeleteOpen = ref(false);
const deleteForm = useForm({ cancellation_reason: '' });
const askDelete = () => { confirmDeleteOpen.value = true; deleteForm.reset(); };
const performDelete = () => {
    if (deleteForm.processing) return;
    deleteForm.delete(route('empresa.gastos.destroy', [props.tenant.slug, detailExpense.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            confirmDeleteOpen.value = false;
            detailOpen.value = false;
        },
    });
};

// --- Categorías ---
const newCatName = ref('');
const newCatErr = ref('');
const submitNewCategory = () => {
    newCatErr.value = '';
    if (!newCatName.value.trim()) return;
    router.post(route('empresa.gastos.categorias.store', props.tenant.slug), {
        name: newCatName.value.trim(),
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { newCatName.value = ''; },
        onError: (errors) => { newCatErr.value = errors.name || 'Error'; },
    });
};

const editingCatId = ref(null);
const editCatForm = useForm({ name: '', status: 'active' });
const startEditCat = (c) => {
    editingCatId.value = c.id;
    editCatForm.name = c.name;
    editCatForm.status = c.status;
    editCatForm.clearErrors();
};
const submitEditCat = (c) => {
    editCatForm.put(route('empresa.gastos.categorias.update', [props.tenant.slug, c.id]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editingCatId.value = null; },
    });
};
const deleteCat = (c) => {
    if (!confirm(`¿Eliminar categoría "${c.name}"?`)) return;
    router.delete(route('empresa.gastos.categorias.destroy', [props.tenant.slug, c.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

const newSubByCat = ref({});
const submitNewSubcategory = (catId) => {
    const name = (newSubByCat.value[catId] || '').trim();
    if (!name) return;
    router.post(route('empresa.gastos.subcategorias.store', props.tenant.slug), {
        expense_category_id: catId,
        name,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { newSubByCat.value[catId] = ''; },
    });
};

const editingSubId = ref(null);
const editSubForm = useForm({ name: '', status: 'active' });
const startEditSub = (s) => {
    editingSubId.value = s.id;
    editSubForm.name = s.name;
    editSubForm.status = s.status;
    editSubForm.clearErrors();
};
const submitEditSub = (s) => {
    editSubForm.put(route('empresa.gastos.subcategorias.update', [props.tenant.slug, s.id]), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editingSubId.value = null; },
    });
};
const deleteSub = (s) => {
    if (!confirm(`¿Eliminar subcategoría "${s.name}"?`)) return;
    router.delete(route('empresa.gastos.subcategorias.destroy', [props.tenant.slug, s.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

// Hay categorías activas con al menos una subcategoría?
const hasUsableCategories = computed(() =>
    props.categories.some(c => c.status === 'active' && (c.subcategories || []).some(s => s.status === 'active'))
);

// --- Formatters ---
const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (v) => v ? new Date(v).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const fmtTime = (v) => v ? new Date(v).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }) : '';

const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <Head title="Gastos" />
    <EmpresaLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Gastos</h1></template>

        <!-- Tabs -->
        <div class="mb-5 flex gap-1 border-b border-gray-200">
            <button @click="switchTab('gastos')"
                :class="['relative px-5 py-2.5 text-sm font-semibold transition',
                    activeTab === 'gastos' ? 'text-red-600' : 'text-gray-500 hover:text-gray-700']">
                Gastos
                <span v-if="activeTab === 'gastos'" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-red-600"></span>
            </button>
            <button @click="switchTab('categorias')"
                :class="['relative px-5 py-2.5 text-sm font-semibold transition',
                    activeTab === 'categorias' ? 'text-red-600' : 'text-gray-500 hover:text-gray-700']">
                Categorías
                <span v-if="activeTab === 'categorias'" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-red-600"></span>
            </button>
        </div>

        <!-- TAB: GASTOS -->
        <div v-if="activeTab === 'gastos'" class="space-y-5">
            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Total filtrado</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ money(totals.amount) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400"># Gastos</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ totals.count }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Promedio</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ money(totals.count ? totals.amount / totals.count : 0) }}</p>
                </div>
            </div>

            <!-- Filters bar -->
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <div class="flex flex-wrap items-center gap-3">
                    <DateField v-model="dateRange" mode="range" align="left" :max="localToday()" />

                    <select v-model="categoryFilter" class="h-10 rounded-xl border-gray-200 bg-white text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300">
                        <option value="">Todas las categorías</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>

                    <select v-model="subcategoryFilter" :disabled="!categoryFilter"
                        class="h-10 rounded-xl border-gray-200 bg-white text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300 disabled:bg-gray-50 disabled:text-gray-400">
                        <option value="">Todas las subcategorías</option>
                        <option v-for="s in subcategoriesForFilter" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>

                    <select v-model="branchFilter" class="h-10 rounded-xl border-gray-200 bg-white text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300">
                        <option value="">Todas las sucursales</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>

                    <div class="relative flex-1 min-w-[200px]">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input v-model="search" type="text" placeholder="Buscar concepto o notas..."
                            class="block h-10 w-full rounded-xl border-gray-200 bg-white pl-10 pr-3 text-sm font-medium shadow-sm focus:border-red-400 focus:ring-red-300" />
                    </div>

                    <button @click="clearFilters" class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-xs font-medium text-gray-600 hover:bg-gray-50">Limpiar</button>

                    <button @click="openCreate" :disabled="!hasUsableCategories"
                        :title="!hasUsableCategories ? 'Crea primero una categoría con subcategoría' : ''"
                        class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-red-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:shadow-none">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Registrar gasto
                    </button>
                </div>
            </div>

            <!-- Empty state — sin categorías -->
            <div v-if="!hasUsableCategories" class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/40 p-6 text-center">
                <p class="text-sm font-bold text-amber-800">Aún no hay categorías de gastos</p>
                <p class="mt-1 text-xs text-amber-700">Ve a la pestaña <button @click="switchTab('categorias')" class="font-bold underline">Categorías</button> y crea las que aplican a tu operación (Servicios, Insumos, Renta, etc.).</p>
            </div>

            <!-- Table -->
            <div v-else class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div v-if="!expenses.data.length" class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-500">No hay gastos en este filtro.</p>
                    <p class="mt-1 text-xs text-gray-400">Cambia el rango o registra un nuevo gasto.</p>
                </div>
                <table v-else class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/60"><tr>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Fecha</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Concepto</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Subcategoría</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Sucursal</th>
                        <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">Usuario</th>
                        <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-gray-500">Monto</th>
                        <th class="px-5 py-3 text-center text-[11px] font-bold uppercase tracking-wider text-gray-500">Adj.</th>
                        <th class="w-12"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50 bg-white">
                        <tr v-for="e in expenses.data" :key="e.id" class="cursor-pointer transition hover:bg-red-50/30" @click="openDetail(e)">
                            <td class="px-5 py-3 text-sm text-gray-700">
                                <div class="font-semibold">{{ fmtDate(e.expense_at) }}</div>
                                <div class="text-xs text-gray-400">{{ fmtTime(e.expense_at) }}</div>
                            </td>
                            <td class="px-5 py-3 text-sm font-bold text-gray-900">{{ e.concept }}</td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                <div>{{ e.subcategory?.name || '—' }}</div>
                                <div class="text-xs text-gray-400">{{ e.subcategory?.category?.name }}</div>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600">{{ e.branch?.name || '—' }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600">{{ e.user?.name || '—' }}</td>
                            <td class="px-5 py-3 text-right text-sm font-bold tabular-nums text-gray-900">{{ money(e.amount) }}</td>
                            <td class="px-5 py-3 text-center">
                                <span v-if="e.attachments?.length" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
                                    {{ e.attachments.length }}
                                </span>
                                <span v-else class="text-xs text-gray-300">—</span>
                            </td>
                            <td class="pr-5 text-gray-300">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="expenses.last_page > 1" class="flex items-center justify-between">
                <p class="text-xs text-gray-500">Página <span class="font-semibold">{{ expenses.current_page }}</span> de {{ expenses.last_page }} · {{ expenses.total }} gastos</p>
                <div class="flex gap-1.5">
                    <button v-for="link in expenses.links" :key="link.label" @click="goToPage(link.url)"
                        :disabled="!link.url || link.active" v-html="link.label"
                        :class="['h-9 min-w-[36px] rounded-lg px-3 text-xs font-bold transition',
                            link.active ? 'bg-red-600 text-white' :
                            link.url ? 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50' :
                            'bg-gray-50 text-gray-300 cursor-not-allowed']" />
                </div>
            </div>
        </div>

        <!-- TAB: CATEGORIAS -->
        <div v-else-if="activeTab === 'categorias'" class="space-y-5">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                <h3 class="mb-3 text-sm font-bold text-gray-700">Nueva categoría</h3>
                <form @submit.prevent="submitNewCategory" class="flex gap-2">
                    <input v-model="newCatName" type="text" required maxlength="120" placeholder="Ej. Servicios"
                        class="flex-1 rounded-xl border-gray-200 bg-white py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                    <button type="submit" class="rounded-xl bg-red-600 px-5 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                        Crear
                    </button>
                </form>
                <p v-if="newCatErr" class="mt-1 text-xs text-red-600">{{ newCatErr }}</p>
            </div>

            <!-- Empty state -->
            <div v-if="!categories.length" class="rounded-2xl border border-dashed border-gray-200 px-6 py-16 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z" /></svg>
                </div>
                <p class="mt-3 text-sm font-bold text-gray-700">Crea tu primera categoría de gastos</p>
                <p class="mt-1 text-xs text-gray-500">Define las categorías que aplican a tu operación. Por ejemplo: Servicios, Insumos, Nómina, Renta.</p>
            </div>

            <!-- Categorías -->
            <div v-for="c in categories" :key="c.id" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-3.5">
                    <template v-if="editingCatId === c.id">
                        <input v-model="editCatForm.name" type="text" maxlength="120"
                            class="flex-1 rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <select v-model="editCatForm.status" class="rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                        <button @click="submitEditCat(c)" :disabled="editCatForm.processing" class="rounded-xl bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                        <button @click="editingCatId = null" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
                    </template>
                    <template v-else>
                        <h3 class="flex-1 text-sm font-bold text-gray-900">{{ c.name }}</h3>
                        <span v-if="c.status === 'inactive'" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">Inactiva</span>
                        <button @click="startEditCat(c)" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200">Editar</button>
                        <button @click="deleteCat(c)" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100">Eliminar</button>
                    </template>
                </div>

                <div class="space-y-2 px-5 py-3">
                    <div v-if="!c.subcategories?.length" class="text-xs text-gray-400">Sin subcategorías.</div>
                    <div v-for="s in c.subcategories" :key="s.id" class="flex items-center gap-2 rounded-xl bg-gray-50 px-3 py-2">
                        <template v-if="editingSubId === s.id">
                            <input v-model="editSubForm.name" type="text" maxlength="120" class="flex-1 rounded-lg border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                            <select v-model="editSubForm.status" class="rounded-lg border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                            <button @click="submitEditSub(s)" :disabled="editSubForm.processing" class="rounded-lg bg-red-600 px-3 py-1 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                            <button @click="editingSubId = null" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
                        </template>
                        <template v-else>
                            <span class="flex-1 text-sm text-gray-700">{{ s.name }}</span>
                            <span v-if="s.status === 'inactive'" class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-500">Inactiva</span>
                            <button @click="startEditSub(s)" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-200" title="Editar">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                            </button>
                            <button @click="deleteSub(s)" class="rounded-lg p-1.5 text-red-500 hover:bg-red-100" title="Eliminar">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                        </template>
                    </div>
                    <form @submit.prevent="submitNewSubcategory(c.id)" class="flex gap-2 pt-1">
                        <input v-model="newSubByCat[c.id]" type="text" maxlength="120" placeholder="Nueva subcategoría..."
                            class="flex-1 rounded-lg border-gray-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <button type="submit" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-bold text-gray-700 hover:bg-gray-200">+ Agregar</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Form modal -->
        <GastoFormModal
            :show="formOpen"
            :mode="formMode"
            :tenant-slug="tenant.slug"
            :categories="categories"
            :branches="branches"
            :allow-branch-select="true"
            :expense="editingExpense"
            :submit-route-name="submitRouteName"
            attachment-destroy-route-name="empresa.gastos.adjuntos.destroy"
            attachment-preview-route-name="empresa.gastos.adjuntos.preview"
            attachment-download-route-name="empresa.gastos.adjuntos.download"
            @close="formOpen = false"
            @success="formOpen = false" />

        <!-- Detail modal -->
        <GastoDetailModal
            :show="detailOpen"
            :expense="detailExpense"
            :tenant-slug="tenant.slug"
            preview-route-name="empresa.gastos.adjuntos.preview"
            download-route-name="empresa.gastos.adjuntos.download"
            :can-edit="true"
            :can-delete="true"
            @close="detailOpen = false"
            @edit="openEdit(detailExpense)"
            @delete="askDelete" />

        <!-- Delete confirm -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-if="confirmDeleteOpen" class="fixed inset-0 z-[55] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" @click.self="confirmDeleteOpen = false">
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" @click.stop>
                        <p class="text-base font-bold text-gray-900">¿Eliminar gasto?</p>
                        <p class="mt-1 text-sm text-gray-500">El gasto se conservará en el sistema como cancelado para auditoría.</p>
                        <div class="mt-4">
                            <label class="mb-1.5 block text-xs font-semibold text-gray-600">Motivo (opcional)</label>
                            <textarea v-model="deleteForm.cancellation_reason" rows="2" maxlength="255"
                                class="w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <div class="mt-4 flex justify-end gap-3">
                            <button @click="confirmDeleteOpen = false" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancelar</button>
                            <button @click="performDelete" :disabled="deleteForm.processing" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">Eliminar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <FlashToast />
    </EmpresaLayout>
</template>
