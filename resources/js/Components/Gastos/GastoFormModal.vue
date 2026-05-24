<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import DateField from '@/Components/DateField.vue';
import AttachmentViewerModal from '@/Components/Gastos/AttachmentViewerModal.vue';
import CameraCaptureModal from '@/Components/CameraCaptureModal.vue';
import { useExpenseAiDraft } from '@/composables/useExpenseAiDraft';
import { localToday } from '@/utils/date';
import { isMobileDevice } from '@/utils/device';

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
    /** Opciones {value,label} de métodos de pago (vienen del backend). */
    paymentMethods: { type: Array, default: () => [] },
    /** Propuesta de la IA (Fase 1). Cuando se setea, prerellena el form al abrir. */
    aiProposal: { type: Object, default: null },
    /** ID del draft IA — se envía al backend al guardar para mover archivos. */
    aiDraftId: { type: [Number, String, null], default: null },
    /** Metadata de archivos ya guardados en el draft IA. */
    aiAttachments: { type: Array, default: () => [] },
    /** Transcripción de la nota de voz (Fase 2). Informativo, no editable. */
    aiTranscription: { type: String, default: null },
});

const emit = defineEmits(['close', 'success']);

const MAX_ATTACHMENTS = 5;
const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

const form = useForm({
    concept: '',
    amount: '',
    expense_category_id: '',
    expense_subcategory_id: '',
    branch_id: '',
    expense_date: '',
    payment_method: '',
    description: '',
    attachments: [],
});

// Conjunto de claves que vinieron prerellenadas por la IA (badge ✨ en la UI).
const aiFilledFields = ref(new Set());
// Adjuntos del draft IA (sólo metadata, los archivos ya viven en disco privado).
const aiDraftAttachments = ref([]);

const { applyProposalToForm } = useExpenseAiDraft();

const fileInput = ref(null);
const cameraInput = ref(null);
const newFiles = ref([]);
// URLs locales para previsualizar imágenes recién agregadas; se revocan al
// quitarlas y al cerrar/limpiar para no fugar memoria (URL.createObjectURL
// retiene el blob hasta revoke).
const newFilePreviews = ref(new Map());
const fileError = ref('');

const subcategories = computed(() => {
    const cat = props.categories.find(c => c.id === Number(form.expense_category_id));
    return cat?.subcategories?.filter(s => s.status === 'active') || [];
});

const existingAttachments = computed(() => props.expense?.attachments || []);

const remainingSlots = computed(() => {
    const used = (existingAttachments.value?.length || 0)
        + aiDraftAttachments.value.length
        + newFiles.value.length;
    return Math.max(0, MAX_ATTACHMENTS - used);
});

const totalAttachments = computed(() =>
    (existingAttachments.value?.length || 0)
    + aiDraftAttachments.value.length
    + newFiles.value.length
);

const revokeAllPreviews = () => {
    newFilePreviews.value.forEach(url => URL.revokeObjectURL(url));
    newFilePreviews.value.clear();
};

const reset = () => {
    form.reset();
    form.clearErrors();
    revokeAllPreviews();
    newFiles.value = [];
    fileError.value = '';
    if (fileInput.value) fileInput.value.value = '';
    if (cameraInput.value) cameraInput.value.value = '';
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
    form.payment_method = props.expense.payment_method || '';
    form.description = props.expense.description || '';
};

const setupCreate = () => {
    form.expense_date = localToday();
    if (!props.allowBranchSelect && props.fixedBranchId) {
        form.branch_id = props.fixedBranchId;
    }
};

const applyAiProposal = () => {
    aiFilledFields.value = new Set();
    aiDraftAttachments.value = props.aiAttachments || [];
    if (!props.aiProposal) return;

    const filled = applyProposalToForm(form, props.aiProposal, props.categories);
    aiFilledFields.value = new Set(filled);
    // Si la IA no detectó fecha, mantenemos el default de hoy.
    if (!form.expense_date) form.expense_date = localToday();
};

const isAiFilled = (key) => aiFilledFields.value.has(key);

const initializeForMode = () => {
    reset();
    if (props.mode === 'edit') {
        populateFromExpense();
    } else {
        setupCreate();
        if (props.aiProposal) applyAiProposal();
    }
};

// El watcher de `show` reinicia tanto al abrir como al cerrar. Antes solo
// reiniciaba en open, lo que permitía que valores quedaran "pegados" si el
// padre cerraba/abría sin re-renderizar o si se llegaba al modal desde una
// transición rápida.
watch(() => props.show, (val) => {
    if (val) {
        initializeForMode();
    } else {
        reset();
    }
});

// Si el padre cambia el modo o el gasto objetivo SIN cerrar el modal (p. ej.
// click directo en "editar" desde otra fila), también hay que reinicializar.
watch(() => [props.mode, props.expense?.id], () => {
    if (props.show) initializeForMode();
});

watch(() => form.expense_category_id, (newVal, oldVal) => {
    if (oldVal && Number(newVal) !== Number(oldVal)) {
        form.expense_subcategory_id = '';
    }
});

onBeforeUnmount(() => revokeAllPreviews());

const addFiles = (files) => {
    fileError.value = '';
    if (!files.length) return;
    for (const f of files) {
        if (!ALLOWED_MIMES.includes(f.type)) {
            fileError.value = `Tipo no permitido: ${f.name}. Solo imágenes (jpg, png, webp) o PDF.`;
            return;
        }
        if (f.size > MAX_BYTES) {
            fileError.value = `Archivo demasiado grande (máx 5 MB): ${f.name}`;
            return;
        }
    }
    if (files.length > remainingSlots.value) {
        fileError.value = `Solo puedes adjuntar hasta ${MAX_ATTACHMENTS} archivos por gasto.`;
        return;
    }
    files.forEach(f => {
        newFiles.value.push(f);
        if (f.type.startsWith('image/')) {
            newFilePreviews.value.set(f, URL.createObjectURL(f));
        }
    });
};

const onFileSelect = (e) => {
    addFiles(Array.from(e.target.files || []));
    e.target.value = '';
};

// "Tomar foto": móvil → cámara nativa (input capture); desktop → webcam (getUserMedia).
const cameraModalOpen = ref(false);
const onTakePhoto = () => {
    if (isMobileDevice()) {
        cameraInput.value?.click();
    } else {
        cameraModalOpen.value = true;
    }
};
const onCameraCapture = (file) => addFiles([file]);

const removeNewFile = (i) => {
    const file = newFiles.value[i];
    const url = newFilePreviews.value.get(file);
    if (url) {
        URL.revokeObjectURL(url);
        newFilePreviews.value.delete(file);
    }
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
        // En create con propuesta IA, mandamos ai_draft_id para que el backend
        // mueva los archivos del draft al gasto (no se re-suben).
        const draftId = props.aiDraftId;
        if (draftId) {
            form
                .transform((data) => ({ ...data, ai_draft_id: draftId }))
                .post(url, opts);
        } else {
            form.post(url, opts);
        }
    }
};

const fmtSize = (b) => {
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};

const isImageMime = (mime) => mime?.startsWith('image/');
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
                            <!-- Banner IA: confianza global + alertas -->
                            <div v-if="aiProposal" :class="[
                                'rounded-2xl border p-3.5',
                                aiProposal.confianza === 'alta' ? 'border-emerald-200 bg-emerald-50' :
                                aiProposal.confianza === 'media' ? 'border-amber-200 bg-amber-50' :
                                'border-red-200 bg-red-50',
                            ]">
                                <div class="flex items-start gap-3">
                                    <div :class="[
                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-xl',
                                        aiProposal.confianza === 'alta' ? 'bg-emerald-100 text-emerald-700' :
                                        aiProposal.confianza === 'media' ? 'bg-amber-100 text-amber-700' :
                                        'bg-red-100 text-red-700',
                                    ]">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-bold" :class="{
                                            'text-emerald-900': aiProposal.confianza === 'alta',
                                            'text-amber-900': aiProposal.confianza === 'media',
                                            'text-red-900': aiProposal.confianza === 'baja',
                                        }">
                                            <template v-if="aiProposal.confianza === 'alta'">La IA está segura — revisa y confirma.</template>
                                            <template v-else-if="aiProposal.confianza === 'media'">Revisa con cuidado los campos marcados.</template>
                                            <template v-else>Verifica todos los campos: la IA no está segura.</template>
                                        </p>
                                        <ul v-if="aiProposal.alertas?.length" class="mt-1 space-y-0.5 text-xs text-gray-700">
                                            <li v-for="(a, i) in aiProposal.alertas" :key="i">· {{ a }}</li>
                                        </ul>
                                        <p v-if="aiProposal.campos_faltantes?.length" class="mt-1 text-xs text-gray-600">
                                            Faltó detectar: <span class="font-semibold">{{ aiProposal.campos_faltantes.join(', ') }}</span>
                                        </p>
                                    </div>
                                </div>
                                <!-- Transcripción de voz (informativa) -->
                                <div v-if="aiTranscription" class="mt-3 rounded-xl bg-white/70 p-3 ring-1 ring-violet-200">
                                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-violet-700">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
                                        Transcripción de tu nota de voz
                                    </p>
                                    <p class="mt-1 text-xs italic text-gray-700">"{{ aiTranscription }}"</p>
                                </div>

                                <!-- Sugerencia de categoría nueva (sólo display; F3 implementa el flujo de aprobación) -->
                                <div v-if="aiProposal.sugerencia_nueva_categoria" class="mt-3 rounded-xl bg-white/70 p-3 ring-1 ring-violet-200">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-violet-700">Sugerencia de categoría nueva</p>
                                    <p class="mt-0.5 text-sm font-semibold text-gray-800">
                                        {{ aiProposal.sugerencia_nueva_categoria.tipo === 'subcategoria' ? 'Subcategoría' : 'Categoría' }}:
                                        "{{ aiProposal.sugerencia_nueva_categoria.nombre_propuesto }}"
                                    </p>
                                    <p v-if="aiProposal.sugerencia_nueva_categoria.razon" class="mt-0.5 text-xs text-gray-600">{{ aiProposal.sugerencia_nueva_categoria.razon }}</p>
                                    <p class="mt-1 text-[11px] text-gray-500">Para usarla, pide al admin de empresa que la cree desde Categorías.</p>
                                </div>
                            </div>

                            <!-- Monto (primero: lo único que el usuario siempre tiene a la mano) -->
                            <div>
                                <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                    Monto (MXN)
                                    <span v-if="isAiFilled('amount')" class="inline-flex items-center gap-0.5 rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                </label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xl font-bold text-gray-400">$</span>
                                    <input v-model.number="form.amount" type="number" step="0.01" min="0.01" inputmode="decimal" required placeholder="0.00"
                                        :class="['block w-full rounded-xl bg-white py-3.5 pl-10 pr-3 text-2xl font-bold tabular-nums text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300',
                                            isAiFilled('amount') ? 'border-violet-300' : 'border-gray-200']" />
                                </div>
                                <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                            </div>

                            <!-- Adjuntos: foto + archivo + miniaturas -->
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-semibold text-gray-600">Comprobante</label>
                                    <span class="text-[11px] text-gray-400">jpg · png · webp · pdf · 5 MB · {{ MAX_ATTACHMENTS }} máx</span>
                                </div>

                                <!-- Adjuntos del draft IA: chips informativos (no removibles) -->
                                <div v-if="aiDraftAttachments.length" class="mb-2 flex flex-wrap gap-1.5">
                                    <span v-for="a in aiDraftAttachments" :key="a.index" class="inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-2.5 py-1 text-[11px] font-semibold text-violet-800 ring-1 ring-violet-200">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                        <span class="max-w-[120px] truncate">{{ a.original_name }}</span>
                                    </span>
                                </div>

                                <!-- Triggers: cámara + archivo -->
                                <div v-if="remainingSlots > 0" class="grid grid-cols-2 gap-2">
                                    <button type="button" @click="onTakePhoto"
                                        class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-red-200 bg-red-50/40 px-4 py-3 text-center transition hover:border-red-400 hover:bg-red-50">
                                        <svg class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                        </svg>
                                        <span class="text-sm font-semibold text-red-700">Tomar foto</span>
                                    </button>
                                    <label class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-200 px-4 py-3 text-center transition hover:border-gray-300 hover:bg-gray-50">
                                        <svg class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                        </svg>
                                        <span class="text-sm font-semibold text-gray-700">Adjuntar archivo</span>
                                        <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="onFileSelect" class="hidden" />
                                    </label>
                                </div>
                                <input ref="cameraInput" type="file" accept="image/*" capture="environment" @change="onFileSelect" class="hidden" />

                                <!-- Grid de miniaturas (existentes + nuevas) -->
                                <div v-if="totalAttachments > 0" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    <!-- Existentes -->
                                    <div v-for="(att, i) in existingAttachments" :key="`ex-${att.id}`"
                                        class="group relative aspect-square overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-200">
                                        <button v-if="attachmentPreviewRouteName && isImageMime(att.mime_type)"
                                            type="button" @click="openAttachmentViewer(i)" class="block h-full w-full">
                                            <img :src="previewUrlBuilder(att)" :alt="att.original_name" loading="lazy"
                                                class="h-full w-full object-cover transition group-hover:scale-105" />
                                        </button>
                                        <button v-else-if="attachmentPreviewRouteName"
                                            type="button" @click="openAttachmentViewer(i)"
                                            class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-gray-500 transition hover:bg-gray-100">
                                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                            <span class="line-clamp-2 text-[10px] font-medium">{{ att.original_name }}</span>
                                        </button>
                                        <button type="button" @click="removeExistingAttachment(att)"
                                            class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>

                                    <!-- Nuevos (queue) -->
                                    <div v-for="(f, i) in newFiles" :key="`new-${i}`"
                                        class="group relative aspect-square overflow-hidden rounded-xl bg-amber-50 ring-1 ring-amber-200">
                                        <img v-if="newFilePreviews.get(f)" :src="newFilePreviews.get(f)" :alt="f.name"
                                            class="h-full w-full object-cover" />
                                        <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-amber-700">
                                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                            <span class="line-clamp-2 text-[10px] font-medium">{{ f.name }}</span>
                                        </div>
                                        <span class="absolute bottom-1 left-1 rounded-md bg-amber-600 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ fmtSize(f.size) }}</span>
                                        <button type="button" @click="removeNewFile(i)"
                                            class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <p class="mt-2 text-[11px] text-gray-400">{{ totalAttachments }} / {{ MAX_ATTACHMENTS }} archivos</p>
                                <p v-if="fileError" class="mt-1 text-xs text-red-600">{{ fileError }}</p>
                                <p v-if="form.errors.attachments" class="mt-1 text-xs text-red-600">{{ form.errors.attachments }}</p>
                                <p v-for="(err, key) in Object.fromEntries(Object.entries(form.errors).filter(([k]) => k.startsWith('attachments.')))"
                                    :key="key" class="mt-1 text-xs text-red-600">{{ err }}</p>
                            </div>

                            <!-- Concepto -->
                            <div>
                                <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                    Concepto
                                    <span v-if="isAiFilled('concept')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                </label>
                                <input v-model="form.concept" type="text" required maxlength="160" placeholder="Ej. Recibo de luz CFE marzo"
                                    :class="['block w-full rounded-xl bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300',
                                        isAiFilled('concept') ? 'border-violet-300' : 'border-gray-200']" />
                                <p v-if="form.errors.concept" class="mt-1 text-xs text-red-600">{{ form.errors.concept }}</p>
                            </div>

                            <!-- Categoría / Subcategoría -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                        Categoría
                                        <span v-if="isAiFilled('expense_category_id')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                    </label>
                                    <select v-model="form.expense_category_id" required
                                        :class="['block w-full rounded-xl bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300',
                                            isAiFilled('expense_category_id') ? 'border-violet-300' : 'border-gray-200']">
                                        <option value="">Selecciona...</option>
                                        <option v-for="c in categories.filter(c => c.status === 'active')" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                        Subcategoría
                                        <span v-if="isAiFilled('expense_subcategory_id')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                    </label>
                                    <select v-model="form.expense_subcategory_id" required :disabled="!form.expense_category_id"
                                        :class="['block w-full rounded-xl bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300 disabled:bg-gray-50 disabled:text-gray-400',
                                            isAiFilled('expense_subcategory_id') ? 'border-violet-300' : 'border-gray-200']">
                                        <option value="">{{ form.expense_category_id ? 'Selecciona...' : '—' }}</option>
                                        <option v-for="s in subcategories" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                    <p v-if="form.errors.expense_subcategory_id" class="mt-1 text-xs text-red-600">{{ form.errors.expense_subcategory_id }}</p>
                                </div>
                            </div>

                            <!-- Fecha + Sucursal -->
                            <div class="grid gap-3" :class="allowBranchSelect ? 'grid-cols-2' : 'grid-cols-1'">
                                <div>
                                    <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                        Fecha del gasto
                                        <span v-if="isAiFilled('expense_date')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                    </label>
                                    <DateField v-model="form.expense_date" mode="single" :max="localToday()" align="left" class="w-full" />
                                    <p v-if="form.errors.expense_date" class="mt-1 text-xs text-red-600">{{ form.errors.expense_date }}</p>
                                </div>
                                <div v-if="allowBranchSelect">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                        Sucursal
                                        <span v-if="isAiFilled('branch_id')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                    </label>
                                    <select v-model="form.branch_id" required
                                        :class="['block w-full rounded-xl bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300',
                                            isAiFilled('branch_id') ? 'border-violet-300' : 'border-gray-200']">
                                        <option value="">Selecciona la sucursal...</option>
                                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                    </select>
                                    <p v-if="form.errors.branch_id" class="mt-1 text-xs text-red-600">{{ form.errors.branch_id }}</p>
                                </div>
                            </div>

                            <!-- Método de pago (opcional) -->
                            <div v-if="paymentMethods.length">
                                <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                    Método de pago (opcional)
                                    <span v-if="isAiFilled('payment_method')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                </label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button v-for="pm in paymentMethods" :key="pm.value" type="button"
                                        @click="form.payment_method = form.payment_method === pm.value ? '' : pm.value"
                                        :class="['rounded-xl border-2 px-3 py-2.5 text-xs font-semibold transition',
                                            form.payment_method === pm.value
                                                ? 'border-red-500 bg-red-50 text-red-700'
                                                : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300']">
                                        {{ pm.label }}
                                    </button>
                                </div>
                                <p v-if="form.errors.payment_method" class="mt-1 text-xs text-red-600">{{ form.errors.payment_method }}</p>
                            </div>

                            <!-- Notas -->
                            <div>
                                <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-gray-600">
                                    Notas (opcional)
                                    <span v-if="isAiFilled('description')" class="inline-flex rounded-full bg-violet-100 px-1.5 text-[10px] font-bold text-violet-700">✨ IA</span>
                                </label>
                                <textarea v-model="form.description" rows="2" maxlength="1000" placeholder="Detalle interno, número de folio, etc."
                                    :class="['block w-full rounded-xl bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300',
                                        isAiFilled('description') ? 'border-violet-300' : 'border-gray-200']" />
                                <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
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

    <!-- Webcam (desktop): captura con getUserMedia cuando `capture` no aplica -->
    <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
</template>
