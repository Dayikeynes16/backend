<script setup>
/**
 * AttachmentsPicker — selector/gestor de adjuntos compartido por Gastos,
 * Compras y comprobantes de pago (spec 2026-07-17-adjuntos-unificados).
 *
 * Presentacional: no hace peticiones de red, no decide permisos, no arma
 * URLs de rutas del backend. El padre sigue siendo dueño de esa lógica —
 * este componente solo junta/valida archivos localmente y emite eventos.
 *
 * Dos modos:
 * - "staged": los archivos nuevos se acumulan aquí (v-model:new-files) y se
 *   envían junto con el resto de un formulario al guardar (Gastos, Compras).
 * - "immediate": cada selección dispara `files-selected` de inmediato — el
 *   padre hace su propia petición de subida (comprobantes de pago).
 *
 * Eliminar un adjunto YA SUBIDO siempre es una petición inmediata del padre
 * (emit('remove-existing', attachment)), sin importar el modo — no existe
 * "borrado en cola" para algo que ya vive en el servidor.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { isMobileDevice } from '@/utils/device';
import CameraCaptureModal from '@/Components/CameraCaptureModal.vue';
import AttachmentViewerModal from '@/Components/AttachmentViewerModal.vue';

const props = defineProps({
    mode: {
        type: String,
        required: true,
        validator: (v) => ['staged', 'immediate'].includes(v),
    },
    attachments: { type: Array, default: () => [] },
    newFiles: { type: Array, default: () => [] },
    maxCount: { type: Number, default: 3 },
    allowedMimes: {
        type: Array,
        default: () => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
    previewUrl: { type: Function, default: null },
    downloadUrl: { type: Function, default: null },
    canAdd: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    uploading: { type: Boolean, default: false },
    uploadError: { type: String, default: '' },
    emptyStateText: { type: String, default: 'Aún no hay archivos — toma una foto o adjunta uno.' },
});

const emit = defineEmits(['update:newFiles', 'remove-existing', 'files-selected']);

const fileInput = ref(null);
const cameraInput = ref(null);
const cameraModalOpen = ref(false);
const localError = ref('');
const dragOver = ref(false);

// Previews locales (blob URLs): para archivos en cola (modo staged) y para
// las miniaturas "pendientes" mientras se sube (modo immediate). Se revocan
// al quitarlas o al desmontar para no fugar memoria.
const previewsByFile = ref(new Map());
const revoke = (file) => {
    const url = previewsByFile.value.get(file);
    if (url) {
        URL.revokeObjectURL(url);
        previewsByFile.value.delete(file);
    }
};

// mode="immediate": archivos que se están subiendo AHORA MISMO, solo para
// feedback visual (spinner). Se limpian cuando `uploading` vuelve a false.
const pendingFiles = ref([]);
watch(() => props.uploading, (isUploading, was) => {
    if (was && !isUploading) {
        pendingFiles.value.forEach(revoke);
        pendingFiles.value = [];
    }
});

// Si el padre reemplaza `newFiles` externamente (p.ej. al resetear un
// formulario), libera los blobs de los archivos que ya no están.
watch(() => props.newFiles, (next, prev) => {
    (prev || []).forEach((f) => { if (!next.includes(f)) revoke(f); });
});

onBeforeUnmount(() => {
    previewsByFile.value.forEach((url) => URL.revokeObjectURL(url));
});

const stagedCount = computed(() => (props.mode === 'staged' ? props.newFiles.length : pendingFiles.value.length));
const totalCount = computed(() => props.attachments.length + stagedCount.value);
const remainingSlots = computed(() => Math.max(0, props.maxCount - totalCount.value));
const showAddTriggers = computed(() => props.canAdd && remainingSlots.value > 0);

const validate = (files) => {
    for (const f of files) {
        if (!props.allowedMimes.includes(f.type)) {
            return `Tipo no permitido: ${f.name}. Solo imágenes (jpg, png, webp) o PDF.`;
        }
        if (f.size > props.maxBytes) {
            return `Archivo demasiado grande (máx ${Math.round(props.maxBytes / (1024 * 1024))} MB): ${f.name}`;
        }
    }
    if (files.length > remainingSlots.value) {
        return `Solo puedes adjuntar hasta ${props.maxCount} archivos.`;
    }
    return '';
};

const addFiles = (files) => {
    localError.value = '';
    if (!files.length) return;
    const error = validate(files);
    if (error) {
        localError.value = error;
        return;
    }

    files.forEach((f) => {
        if (f.type.startsWith('image/')) previewsByFile.value.set(f, URL.createObjectURL(f));
    });

    if (props.mode === 'staged') {
        emit('update:newFiles', [...props.newFiles, ...files]);
    } else {
        pendingFiles.value = [...pendingFiles.value, ...files];
        emit('files-selected', files);
    }
};

const onFileInputChange = (e) => {
    addFiles(Array.from(e.target.files ?? []));
    e.target.value = '';
};

const onDrop = (e) => {
    dragOver.value = false;
    if (!showAddTriggers.value) return;
    addFiles(Array.from(e.dataTransfer?.files ?? []));
};

const onTakePhoto = () => {
    if (isMobileDevice()) {
        cameraInput.value?.click();
    } else {
        cameraModalOpen.value = true;
    }
};
const onCameraCapture = (file) => addFiles([file]);

const removeNewFile = (index) => {
    const file = props.newFiles[index];
    revoke(file);
    emit('update:newFiles', props.newFiles.filter((_, i) => i !== index));
};

const removeExisting = (att) => {
    if (!window.confirm(`¿Eliminar "${att.original_name}"?`)) return;
    emit('remove-existing', att);
};

// --- Viewer (solo adjuntos ya subidos) ---
const viewerOpen = ref(false);
const viewerIndex = ref(0);
const openViewer = (i) => {
    if (!props.previewUrl) return;
    viewerIndex.value = i;
    viewerOpen.value = true;
};

const isImage = (att) => att?.mime_type?.startsWith('image/');
const isPdf = (att) => att?.mime_type === 'application/pdf';

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<template>
    <div>
        <!-- Grid de miniaturas: existentes + en cola (staged) + pendientes de subida (immediate) -->
        <div v-if="totalCount > 0" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <div v-for="(att, i) in attachments" :key="`existing-${att.id}`"
                class="group relative aspect-square overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-200">
                <button v-if="previewUrl" type="button" @click="openViewer(i)" class="block h-full w-full">
                    <img v-if="isImage(att)" :src="previewUrl(att)" :alt="att.original_name" loading="lazy"
                        class="h-full w-full object-cover transition group-hover:scale-105" />
                    <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-gray-500">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <span class="line-clamp-2 text-[10px] font-medium">{{ att.original_name }}</span>
                    </div>
                </button>
                <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-gray-500">
                    <svg v-if="isImage(att)" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3 4.5h18v15H3v-15Z" /></svg>
                    <svg v-else class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <span class="line-clamp-2 text-[10px] font-medium">{{ att.original_name }}</span>
                </div>
                <span class="pointer-events-none absolute bottom-1 left-1 rounded-md bg-black/60 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ fmtSize(att.size_bytes) }}</span>
                <button v-if="canDelete" type="button" @click="removeExisting(att)"
                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <template v-if="mode === 'staged'">
                <div v-for="(f, i) in newFiles" :key="`staged-${i}`"
                    class="group relative aspect-square overflow-hidden rounded-xl bg-amber-50 ring-1 ring-amber-200">
                    <img v-if="previewsByFile.get(f)" :src="previewsByFile.get(f)" :alt="f.name" class="h-full w-full object-cover" />
                    <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-2 text-amber-700">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <span class="line-clamp-2 text-[10px] font-medium">{{ f.name }}</span>
                    </div>
                    <span class="pointer-events-none absolute bottom-1 left-1 rounded-md bg-amber-600 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ fmtSize(f.size) }}</span>
                    <button type="button" @click="removeNewFile(i)"
                        class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </template>

            <template v-else>
                <div v-for="(f, i) in pendingFiles" :key="`pending-${i}`"
                    class="relative aspect-square overflow-hidden rounded-xl bg-blue-50 ring-1 ring-blue-200">
                    <img v-if="previewsByFile.get(f)" :src="previewsByFile.get(f)" :alt="f.name" class="h-full w-full object-cover opacity-50" />
                    <div v-else class="flex h-full w-full items-center justify-center p-2 text-blue-700 opacity-50">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    </div>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/20">
                        <svg class="h-6 w-6 animate-spin text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                </div>
            </template>
        </div>

        <!-- Estado vacío -->
        <div v-else class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-xs text-gray-400">
            {{ emptyStateText }}
        </div>

        <!-- Triggers: cámara + archivo/arrastrar -->
        <div v-if="showAddTriggers" class="mt-3 grid grid-cols-2 gap-2">
            <button type="button" @click="onTakePhoto"
                class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-red-200 bg-red-50/40 px-4 py-3 text-center transition hover:border-red-400 hover:bg-red-50">
                <svg class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                </svg>
                <span class="text-sm font-semibold text-red-700">Tomar foto</span>
            </button>
            <label
                @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop"
                :class="['group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-3 text-center transition',
                    dragOver ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50']">
                <svg class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <span class="text-sm font-semibold text-gray-700">{{ uploading ? 'Subiendo…' : 'Adjuntar o arrastra aquí' }}</span>
                <input ref="fileInput" type="file" multiple :accept="allowedMimes.join(',')" class="hidden" :disabled="uploading" @change="onFileInputChange" />
            </label>
            <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" :disabled="uploading" @change="onFileInputChange" />
        </div>
        <p v-else-if="canAdd" class="mt-3 text-center text-xs text-gray-400">Máximo {{ maxCount }} archivos.</p>

        <p v-if="localError" class="mt-2 text-xs font-semibold text-red-600">{{ localError }}</p>
        <p v-if="uploadError" class="mt-2 text-xs font-semibold text-red-600">{{ uploadError }}</p>

        <AttachmentViewerModal v-if="previewUrl"
            :show="viewerOpen"
            :attachments="attachments"
            :initial-index="viewerIndex"
            :preview-url="previewUrl"
            :download-url="downloadUrl"
            @close="viewerOpen = false" />
        <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
    </div>
</template>
