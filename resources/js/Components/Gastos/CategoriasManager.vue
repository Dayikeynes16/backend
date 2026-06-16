<script setup>
import CategoryAICaptureModal from '@/Components/Gastos/CategoryAICaptureModal.vue';
import CategoryAIReviewModal from '@/Components/Gastos/CategoryAIReviewModal.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Gestión del catálogo de categorías/subcategorías de gasto (tenant-wide),
 * reutilizable por el panel de empresa y el de sucursal. El catálogo es el
 * mismo para ambos; cambian solo el prefijo de rutas y si se permite borrar.
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    tenantSlug: { type: String, required: true },
    // 'empresa' | 'sucursal' — prefijo de los nombres de ruta de escritura.
    routePrefix: { type: String, default: 'empresa' },
    // El admin-sucursal NO puede borrar (queda reservado a empresa/superadmin).
    canDelete: { type: Boolean, default: false },
});

const r = (name, params) => route(`${props.routePrefix}.${name}`, params);

const parseAliases = (text) => (text || '')
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);

// --- Crear categoría manual ---
const newCatName = ref('');
const newCatErr = ref('');
const submitNewCategory = () => {
    newCatErr.value = '';
    if (!newCatName.value.trim()) return;
    router.post(r('gastos.categorias.store', props.tenantSlug), {
        name: newCatName.value.trim(),
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { newCatName.value = ''; },
        onError: (errors) => { newCatErr.value = errors.name || 'Error'; },
    });
};

// --- Editar categoría ---
const editingCatId = ref(null);
const editCatForm = useForm({ name: '', description: '', aliases_text: '', status: 'active' });
const startEditCat = (c) => {
    editingCatId.value = c.id;
    editCatForm.name = c.name;
    editCatForm.description = c.description || '';
    editCatForm.aliases_text = (c.aliases || []).join(', ');
    editCatForm.status = c.status;
    editCatForm.clearErrors();
};
const submitEditCat = (c) => {
    editCatForm
        .transform(d => ({
            name: d.name,
            description: d.description || null,
            aliases: parseAliases(d.aliases_text),
            status: d.status,
        }))
        .put(r('gastos.categorias.update', [props.tenantSlug, c.id]), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { editingCatId.value = null; },
        });
};
const deleteCat = (c) => {
    if (!confirm(`¿Eliminar categoría "${c.name}"?`)) return;
    router.delete(r('gastos.categorias.destroy', [props.tenantSlug, c.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

// --- Crear categoría con IA ---
const catIAStep = ref('idle'); // 'idle' | 'capture' | 'review'
const catIADraft = ref(null);
const openCategoryIA = () => { catIADraft.value = null; catIAStep.value = 'capture'; };
const onCategoryIAProposal = (result) => { catIADraft.value = result; catIAStep.value = 'review'; };
const onCategoryIASaved = () => {
    catIAStep.value = 'idle';
    catIADraft.value = null;
    router.reload({ only: ['categories'], preserveScroll: true });
};

// --- Subcategorías ---
const newSubByCat = ref({});
const submitNewSubcategory = (catId) => {
    const name = (newSubByCat.value[catId] || '').trim();
    if (!name) return;
    router.post(r('gastos.subcategorias.store', props.tenantSlug), {
        expense_category_id: catId,
        name,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { newSubByCat.value[catId] = ''; },
    });
};

const editingSubId = ref(null);
const editSubForm = useForm({ name: '', description: '', aliases_text: '', status: 'active' });
const startEditSub = (s) => {
    editingSubId.value = s.id;
    editSubForm.name = s.name;
    editSubForm.description = s.description || '';
    editSubForm.aliases_text = (s.aliases || []).join(', ');
    editSubForm.status = s.status;
    editSubForm.clearErrors();
};
const submitEditSub = (s) => {
    editSubForm
        .transform(d => ({
            name: d.name,
            description: d.description || null,
            aliases: parseAliases(d.aliases_text),
            status: d.status,
        }))
        .put(r('gastos.subcategorias.update', [props.tenantSlug, s.id]), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { editingSubId.value = null; },
        });
};
const deleteSub = (s) => {
    if (!confirm(`¿Eliminar subcategoría "${s.name}"?`)) return;
    router.delete(r('gastos.subcategorias.destroy', [props.tenantSlug, s.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <div class="space-y-5">
        <!-- Crear con IA -->
        <button @click="openCategoryIA"
            class="group flex w-full items-center justify-between gap-3 rounded-2xl border-2 border-dashed border-violet-200 bg-gradient-to-r from-violet-50 to-fuchsia-50 px-5 py-4 text-left transition hover:border-violet-300 hover:from-violet-100 hover:to-fuchsia-100">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white shadow-sm">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09Z" /></svg>
                </div>
                <div>
                    <p class="text-sm font-bold text-gray-900">Crear categoría con IA</p>
                    <p class="mt-0.5 text-xs text-gray-600">Describe qué gastos quieres agrupar — la IA propone nombre, descripción y subcategorías.</p>
                </div>
            </div>
            <svg class="h-5 w-5 text-violet-500 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
            <h3 class="mb-3 text-sm font-bold text-gray-700">O crea manualmente</h3>
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
            <div class="border-b border-gray-100 px-5 py-3.5">
                <template v-if="editingCatId === c.id">
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-2">
                            <input v-model="editCatForm.name" type="text" maxlength="120"
                                class="flex-1 rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                            <select v-model="editCatForm.status" class="rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-gray-500">Descripción interna</label>
                            <textarea v-model="editCatForm.description" rows="2" maxlength="500"
                                placeholder="Para qué se usa esta categoría. Ayuda a la IA a clasificar correctamente."
                                class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-gray-500">Sinónimos / alias (separados por coma)</label>
                            <input v-model="editCatForm.aliases_text" type="text" maxlength="600"
                                placeholder="Vehículos, Combustible, Logística"
                                class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                            <p class="mt-1 text-[10px] text-gray-400">La IA usará estos sinónimos para evitar crear categorías duplicadas.</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button @click="editingCatId = null" type="button" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
                            <button @click="submitEditCat(c)" :disabled="editCatForm.processing" class="rounded-xl bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3 class="truncate text-sm font-bold text-gray-900">{{ c.name }}</h3>
                                <span v-if="c.status === 'inactive'" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">Inactiva</span>
                            </div>
                            <p v-if="c.description" class="mt-1 line-clamp-2 text-xs text-gray-500">{{ c.description }}</p>
                            <div v-if="c.aliases?.length" class="mt-1.5 flex flex-wrap gap-1">
                                <span v-for="a in c.aliases" :key="a" class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">{{ a }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button @click="startEditCat(c)" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200">Editar</button>
                            <button v-if="canDelete" @click="deleteCat(c)" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100">Eliminar</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="space-y-2 px-5 py-3">
                <div v-if="!c.subcategories?.length" class="text-xs text-gray-400">Sin subcategorías.</div>
                <div v-for="s in c.subcategories" :key="s.id" class="rounded-xl bg-gray-50 px-3 py-2">
                    <template v-if="editingSubId === s.id">
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <input v-model="editSubForm.name" type="text" maxlength="120" class="flex-1 rounded-lg border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                                <select v-model="editSubForm.status" class="rounded-lg border-gray-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                    <option value="active">Activa</option>
                                    <option value="inactive">Inactiva</option>
                                </select>
                            </div>
                            <textarea v-model="editSubForm.description" rows="2" maxlength="500"
                                placeholder="Descripción interna (qué tipo de gasto entra aquí). Ayuda a la IA."
                                class="block w-full rounded-lg border-gray-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                            <input v-model="editSubForm.aliases_text" type="text" maxlength="600"
                                placeholder="Sinónimos separados por coma: Diésel, Nafta..."
                                class="block w-full rounded-lg border-gray-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                            <div class="flex justify-end gap-2">
                                <button @click="editingSubId = null" type="button" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
                                <button @click="submitEditSub(s)" :disabled="editSubForm.processing" class="rounded-lg bg-red-600 px-3 py-1 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">Guardar</button>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-medium text-gray-700">{{ s.name }}</span>
                                    <span v-if="s.status === 'inactive'" class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-500">Inactiva</span>
                                </div>
                                <p v-if="s.description" class="mt-0.5 line-clamp-2 text-[11px] text-gray-500">{{ s.description }}</p>
                                <div v-if="s.aliases?.length" class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="a in s.aliases" :key="a" class="rounded-full bg-white px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-gray-200">{{ a }}</span>
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button @click="startEditSub(s)" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-200" title="Editar">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                </button>
                                <button v-if="canDelete" @click="deleteSub(s)" class="rounded-lg p-1.5 text-red-500 hover:bg-red-100" title="Eliminar">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
                <form @submit.prevent="submitNewSubcategory(c.id)" class="flex gap-2 pt-1">
                    <input v-model="newSubByCat[c.id]" type="text" maxlength="120" placeholder="Nueva subcategoría..."
                        class="flex-1 rounded-lg border-gray-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                    <button type="submit" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-bold text-gray-700 hover:bg-gray-200">+ Agregar</button>
                </form>
            </div>
        </div>

        <!-- Crear categoría con IA (modales) -->
        <CategoryAICaptureModal
            :show="catIAStep === 'capture'"
            :tenant-slug="tenantSlug"
            :route-prefix="routePrefix"
            @close="catIAStep = 'idle'"
            @proposal="onCategoryIAProposal" />

        <CategoryAIReviewModal
            :show="catIAStep === 'review'"
            :tenant-slug="tenantSlug"
            :route-prefix="routePrefix"
            :draft-result="catIADraft"
            @close="catIAStep = 'idle'"
            @saved="onCategoryIASaved" />
    </div>
</template>
