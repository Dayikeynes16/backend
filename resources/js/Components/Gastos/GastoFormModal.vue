<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import DateField from '@/Components/DateField.vue';
import AttachmentViewerModal from '@/Components/Gastos/AttachmentViewerModal.vue';
import { localToday } from '@/utils/date';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** 'create' | 'edit' */
    mode: { type: String, default: 'create' },
    tenantSlug: { type: String, required: true },
    /** Listado completo de categorías (cada una con `subcategories[]`). */
    categories: { type: Array, required: true },
    /** Branches disponibles. Requerido cuando allowBranchSelect=true. */
    branches: { type: Array, default: () => [] },
    /** Si true, muestra el selector de sucursal (admin-empresa). Si false, sucursal viene fija. */
    allowBranchSelect: { type: Boolean, default: false },
    /** Cuando allowBranchSelect=false, branchId que se usará. */
    fixedBranchId: { type: [Number, String, null], default: null },
    /** Gasto en edición (cuando mode === 'edit'). */
    expense: { type: Object, default: null },
    submitRouteName: { type: String, required: true },
    attachmentDestroyRouteName: { type: String, required: true },
    /** Para preview/download dentro del modal en modo edit. */
    attachmentPreviewRouteName: { type: String, default: '' },
    attachmentDownloadRouteName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'success']);

const MAX_ATTACHMENTS = 5;
const MAX_BYTES = 5 * 1024 * 1024;

const form = useForm({
    concept: '',
    amount: '',
    expense_category_id: '',
    expense_subcategory_id: '',
    branch_id: '',
    expense_date: '',
    description: '',
    attachments: [],
});

const fileInput = ref(null);
const newFiles = ref([]);
const fileError = ref('');

const subcategories = computed(() => {
    const cat = props.categories.find(c => c.id === Number(form.expense_category_id));
    return cat?.subcategories?.filter(s => s.status === 'active') || [];
});

const existingAttachments = computed(() => props.expense?.attachments || []);

const remainingSlots = computed(() => {
    const used = (existingAttachments.value?.length || 0) + newFiles.value.length;
    return Math.max(0, MAX_ATTACHMENTS - used);
});

const reset = () => {
    form.reset();
    form.clearErrors();
    newFiles.value = [];
    fileError.value = '';
    if (fileInput.value) fileInput.value.value = '';
};

const populateFromExpense = () => {
    if (!props.expense) return;
    form.concept = props.expense.concept || '';
    form.amount = props.expense.amount;
    form.expense_subcategory_id = props.expense.expense_subcategory_id;
    form.expense_category_id = props.expense.subcategory?.expense_category_id
        || props.expense.subcategory?.category?.id || '';
    form.branch_id = props.expense.branch_id || props.fixedBranchId || '';
    if (props.expense.expense_at) {
        const d = new Date(props.expense.expense_at);
        const pad = (n) => String(n).padStart(2, '0');
        form.expense_date = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    }
    form.description = props.expense.description || '';
};

const setupCreate = () => {
    form.expense_date = localToday();
    if (!props.allowBranchSelect && props.fixedBranchId) {
        form.branch_id = props.fixedBranchId;
    }
};

watch(() => props.show, (val) => {
    if (!val) return;
    reset();
    if (props.mode === 'edit') {
        populateFromExpense();
    } else {
        setupCreate();
    }
});

watch(() => form.expense_category_id, (newVal, oldVal) => {
    if (oldVal && Number(newVal) !== Number(oldVal)) {
        form.expense_subcategory_id = '';
    }
});

const onFileSelect = (e) => {
    fileError.value = '';
    const files = Array.from(e.target.files || []);
    if (!files.length) return;

    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    for (const f of files) {
        if (!allowed.includes(f.type)) {
            fileError.value = `Tipo no permitido: ${f.name}. Solo imágenes (jpg, png, webp) o PDF.`;
            e.target.value = '';
            return;
        }
        if (f.size > MAX_BYTES) {
            fileError.value = `Archivo demasiado grande (máx 5 MB): ${f.name}`;
            e.target.value = '';
            return;
        }
    }
    if (files.length > remainingSlots.value) {
        fileError.value = `Solo puedes adjuntar hasta ${MAX_ATTACHMENTS} archivos por gasto.`;
        e.target.value = '';
        return;
    }
    newFiles.value = [...newFiles.value, ...files];
    e.target.value = '';
};

const removeNewFile = (i) => {
    newFiles.value = newFiles.value.filter((_, idx) => idx !== i);
};

const removeExistingAttachment = (att) => {
    if (!confirm(`¿Eliminar adjunto "${att.original_name}"?`)) return;
    router.delete(route(props.attachmentDestroyRouteName, [props.tenantSlug, props.expense.id, att.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

// --- Adjunto preview (modo edit) ---
const viewerOpen = ref(false);
const viewerIndex = ref(0);
const openAttachmentViewer = (i) => {
    if (!props.attachmentPreviewRouteName) return;
    viewerIndex.value = i;
    viewerOpen.value = true;
};
const previewUrlBuilder = (att) =>
    route(props.attachmentPreviewRouteName, [props.tenantSlug, props.expense?.id, att.id]);
const downloadUrlBuilder = (att) =>
    route(props.attachmentDownloadRouteName, [props.tenantSlug, props.expense?.id, att.id]);

const submit = () => {
    if (form.processing) return;
    form.attachments = newFiles.value;

    const args = props.mode === 'edit'
        ? [props.tenantSlug, props.expense.id]
        : [props.tenantSlug];

    const url = route(props.submitRouteName, args);

    const opts = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('success');
            emit('close');
        },
    };

    if (props.mode === 'edit') {
        // forceFormData impide el spoofing automático de método de Inertia,
        // así que `_method: 'put'` debe ir DENTRO del payload (no como option).
        // Laravel lee ese field para tratar el POST multipart como PUT.
        form
            .transform((data) => ({ ...data, _method: 'put' }))
            .post(url, opts);
    } else {
        form.post(url, opts);
    }
};

const fmtSize = (b) => {
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="$emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <div>
                            <h3 class="text-base font-bold text-gray-900">
                                {{ mode === 'edit' ? 'Editar gasto' : 'Registrar gasto' }}
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ mode === 'edit' ? 'Actualiza los datos del gasto.' : 'Captura un nuevo gasto operativo.' }}
                            </p>
                        </div>
                        <button @click="$emit('close')" class="flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body (scrollable) -->
                    <form @submit.prevent="submit" class="flex flex-1 flex-col overflow-y-auto">
                        <div class="space-y-5 px-6 py-5">
                            <!-- Concepto -->
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Concepto</label>
                                <input v-model="form.concept" type="text" required maxlength="160" placeholder="Ej. Recibo de luz CFE marzo"
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="form.errors.concept" class="mt-1 text-xs text-red-600">{{ form.errors.concept }}</p>
                            </div>

                            <!-- Categoría / Subcategoría -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600">Categoría</label>
                                    <select v-model="form.expense_category_id" required
                                        class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300">
                                        <option value="">Selecciona...</option>
                                        <option v-for="c in categories.filter(c => c.status === 'active')" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600">Subcategoría</label>
                                    <select v-model="form.expense_subcategory_id" required :disabled="!form.expense_category_id"
                                        class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300 disabled:bg-gray-50 disabled:text-gray-400">
                                        <option value="">{{ form.expense_category_id ? 'Selecciona...' : '—' }}</option>
                                        <option v-for="s in subcategories" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                    <p v-if="form.errors.expense_subcategory_id" class="mt-1 text-xs text-red-600">{{ form.errors.expense_subcategory_id }}</p>
                                </div>
                            </div>

                            <!-- Monto + Fecha -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600">Monto (MXN)</label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-gray-400">$</span>
                                        <input v-model.number="form.amount" type="number" step="0.01" min="0.01" required placeholder="0.00"
                                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 pl-7 pr-3 text-sm font-medium tabular-nums text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                                    </div>
                                    <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600">Fecha del gasto</label>
                                    <DateField v-model="form.expense_date" mode="single" :max="localToday()" align="left" class="w-full" />
                                    <p v-if="form.errors.expense_date" class="mt-1 text-xs text-red-600">{{ form.errors.expense_date }}</p>
                                </div>
                            </div>

                            <!-- Sucursal -->
                            <div v-if="allowBranchSelect">
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Sucursal</label>
                                <select v-model="form.branch_id" required
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300">
                                    <option value="">Selecciona la sucursal...</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                                <p v-if="form.errors.branch_id" class="mt-1 text-xs text-red-600">{{ form.errors.branch_id }}</p>
                            </div>

                            <!-- Descripción -->
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Notas (opcional)</label>
                                <textarea v-model="form.description" rows="2" maxlength="1000" placeholder="Detalle interno, número de folio, etc."
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                                <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
                            </div>

                            <!-- Adjuntos -->
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-semibold text-gray-600">Adjuntos</label>
                                    <span class="text-[11px] text-gray-400">jpg · png · webp · pdf · 5 MB · {{ MAX_ATTACHMENTS }} máx</span>
                                </div>

                                <!-- Existentes (edit) -->
                                <div v-if="existingAttachments.length" class="mb-2 space-y-1.5">
                                    <div v-for="(att, i) in existingAttachments" :key="att.id"
                                        class="flex items-center gap-3 rounded-xl bg-gray-50 px-3 py-2.5 ring-1 ring-gray-100 transition hover:ring-gray-200">
                                        <button v-if="attachmentPreviewRouteName" type="button" @click="openAttachmentViewer(i)"
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-gray-200 text-gray-500 transition hover:bg-red-50 hover:text-red-600 hover:ring-red-200" title="Previsualizar">
                                            <svg v-if="att.mime_type?.startsWith('image/')" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159M21.75 18V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5h16.5a1.5 1.5 0 0 0 1.5-1.5Z" /></svg>
                                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                        </button>
                                        <span class="flex-1 truncate text-sm text-gray-700">{{ att.original_name }}</span>
                                        <span class="text-[11px] text-gray-400">{{ fmtSize(att.size_bytes) }}</span>
                                        <button type="button" @click="removeExistingAttachment(att)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600" title="Eliminar adjunto">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Nuevos en cola -->
                                <div v-if="newFiles.length" class="mb-2 space-y-1.5">
                                    <div v-for="(f, i) in newFiles" :key="i"
                                        class="flex items-center gap-3 rounded-xl bg-amber-50 px-3 py-2.5 ring-1 ring-amber-100">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-amber-200 text-amber-700">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                        </div>
                                        <span class="flex-1 truncate text-sm text-amber-900">{{ f.name }}</span>
                                        <span class="text-[11px] text-amber-700">{{ fmtSize(f.size) }}</span>
                                        <button type="button" @click="removeNewFile(i)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-amber-700 transition hover:bg-amber-100" title="Quitar">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Trigger -->
                                <label v-if="remainingSlots > 0"
                                    class="group flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-gray-200 px-4 py-5 text-center transition hover:border-red-300 hover:bg-red-50/40">
                                    <svg class="h-5 w-5 text-gray-400 transition group-hover:text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                                    <span class="text-sm font-semibold text-gray-700 group-hover:text-red-700">Agregar archivo</span>
                                    <span class="text-[11px] text-gray-400">{{ remainingSlots }} de {{ MAX_ATTACHMENTS }} disponibles</span>
                                    <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="onFileSelect" class="hidden" />
                                </label>

                                <p v-if="fileError" class="mt-1 text-xs text-red-600">{{ fileError }}</p>
                                <p v-if="form.errors.attachments" class="mt-1 text-xs text-red-600">{{ form.errors.attachments }}</p>
                                <p v-for="(err, key) in Object.fromEntries(Object.entries(form.errors).filter(([k]) => k.startsWith('attachments.')))"
                                    :key="key" class="mt-1 text-xs text-red-600">{{ err }}</p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                            <button type="button" @click="$emit('close')" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</button>
                            <button type="submit" :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                {{ form.processing ? 'Guardando...' : (mode === 'edit' ? 'Guardar cambios' : 'Registrar gasto') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Viewer in edit mode -->
        <AttachmentViewerModal v-if="attachmentPreviewRouteName"
            :show="viewerOpen"
            :attachments="existingAttachments"
            :initial-index="viewerIndex"
            :preview-url="previewUrlBuilder"
            :download-url="downloadUrlBuilder"
            @close="viewerOpen = false" />
    </Teleport>
</template>
