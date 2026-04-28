<script setup>
/**
 * AttachmentViewerModal — visor inline de adjuntos (imagen + PDF).
 *
 * Acepta una lista de adjuntos y un índice activo. Permite navegar prev/next
 * (si hay varios), descargar y cerrar. NO descarga al abrir.
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    attachments: { type: Array, default: () => [] },
    initialIndex: { type: Number, default: 0 },
    /** Builder que recibe (attachment) y retorna URL de preview. */
    previewUrl: { type: Function, required: true },
    /** Builder que recibe (attachment) y retorna URL de descarga forzada. */
    downloadUrl: { type: Function, required: true },
});

const emit = defineEmits(['close']);

const index = ref(props.initialIndex);

watch(() => props.initialIndex, (v) => { index.value = v; });
watch(() => props.show, (v) => { if (v) index.value = props.initialIndex; });

const current = computed(() => props.attachments[index.value] || null);
const total = computed(() => props.attachments.length);

const isImage = (att) => att?.mime_type?.startsWith('image/');
const isPdf = (att) => att?.mime_type === 'application/pdf';
const isPreviewable = (att) => isImage(att) || isPdf(att);

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};

const prev = () => {
    if (total.value <= 1) return;
    index.value = (index.value - 1 + total.value) % total.value;
};
const next = () => {
    if (total.value <= 1) return;
    index.value = (index.value + 1) % total.value;
};

const onKey = (e) => {
    if (!props.show) return;
    if (e.key === 'Escape') emit('close');
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
};

import { onBeforeUnmount, onMounted } from 'vue';
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" leave-active-class="transition duration-100" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show && current" class="fixed inset-0 z-[60] flex flex-col bg-black/85 backdrop-blur-sm" @click.self="$emit('close')">
                <!-- Top bar -->
                <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3 text-white sm:px-6">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold">{{ current.original_name }}</p>
                        <p class="text-[11px] text-white/60">
                            <span class="uppercase">{{ current.mime_type?.split('/')[1] || 'archivo' }}</span>
                            <span v-if="current.size_bytes" class="ml-2">{{ fmtSize(current.size_bytes) }}</span>
                            <span v-if="total > 1" class="ml-3">{{ index + 1 }} / {{ total }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <a :href="downloadUrl(current)"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/20"
                            download>
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Descargar
                        </a>
                        <button @click="$emit('close')" class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20" aria-label="Cerrar">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="relative flex flex-1 items-center justify-center overflow-hidden p-4 sm:p-8">
                    <!-- Prev/Next -->
                    <button v-if="total > 1" @click="prev" aria-label="Anterior"
                        class="absolute left-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:left-6">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    </button>
                    <button v-if="total > 1" @click="next" aria-label="Siguiente"
                        class="absolute right-3 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 sm:right-6">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>

                    <!-- Image -->
                    <img v-if="isImage(current)"
                        :src="previewUrl(current)"
                        :alt="current.original_name"
                        class="max-h-full max-w-full rounded-xl object-contain shadow-2xl" />

                    <!-- PDF -->
                    <iframe v-else-if="isPdf(current)"
                        :src="previewUrl(current)"
                        :title="current.original_name"
                        class="h-full w-full max-w-5xl rounded-xl bg-white"
                        frameborder="0" />

                    <!-- Unsupported -->
                    <div v-else class="rounded-2xl bg-white/5 px-8 py-12 text-center text-white">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        </div>
                        <p class="mt-4 text-base font-bold">No se puede previsualizar este archivo</p>
                        <p class="mt-1 text-sm text-white/60">Descárgalo para abrirlo en tu equipo.</p>
                        <a :href="downloadUrl(current)" download
                            class="mt-5 inline-flex h-10 items-center gap-2 rounded-lg bg-white px-5 text-sm font-bold text-gray-900 transition hover:bg-gray-100">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Descargar
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
