<script setup>
import { computed, ref } from 'vue';
import AttachmentViewerModal from '@/Components/Gastos/AttachmentViewerModal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    expense: { type: Object, default: null },
    tenantSlug: { type: String, required: true },
    /** Ziggy route name for inline preview (Content-Disposition: inline). */
    previewRouteName: { type: String, required: true },
    /** Ziggy route name for download (forced attachment). */
    downloadRouteName: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

defineEmits(['close', 'edit', 'delete']);

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDate = (v) => {
    if (!v) return '—';
    const d = new Date(v);
    return d.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};

const attachments = computed(() => props.expense?.attachments || []);

const previewUrlBuilder = (att) => route(props.previewRouteName, [props.tenantSlug, props.expense.id, att.id]);
const downloadUrlBuilder = (att) => route(props.downloadRouteName, [props.tenantSlug, props.expense.id, att.id]);

const viewerOpen = ref(false);
const viewerIndex = ref(0);
const openViewer = (i) => { viewerIndex.value = i; viewerOpen.value = true; };

const isImage = (att) => att?.mime_type?.startsWith('image/');
const isPdf = (att) => att?.mime_type === 'application/pdf';
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show && expense" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="$emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-6 items-center rounded-full bg-red-50 px-2.5 text-[11px] font-bold text-red-700 ring-1 ring-red-100">
                                    {{ expense.subcategory?.category?.name || 'Sin categoría' }}
                                </span>
                                <span class="text-[11px] text-gray-400">·</span>
                                <span class="text-[11px] font-semibold text-gray-500">{{ expense.subcategory?.name || '—' }}</span>
                            </div>
                            <h3 class="mt-1.5 truncate text-lg font-bold text-gray-900">{{ expense.concept }}</h3>
                        </div>
                        <button @click="$emit('close')" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                        <!-- Monto destacado -->
                        <div class="rounded-2xl bg-gradient-to-br from-gray-900 to-gray-800 p-5 text-white">
                            <p class="text-[11px] font-bold uppercase tracking-[0.15em] text-white/60">Monto</p>
                            <p class="mt-1 text-3xl font-bold tabular-nums tracking-tight">{{ money(expense.amount) }}</p>
                            <p class="mt-2 text-xs text-white/70">{{ fmtDate(expense.expense_at) }}</p>
                        </div>

                        <!-- Datos -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-gray-100">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Sucursal</p>
                                <p class="mt-1 truncate text-sm font-semibold text-gray-800">{{ expense.branch?.name || '—' }}</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-gray-100">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Registrado por</p>
                                <p class="mt-1 truncate text-sm font-semibold text-gray-800">{{ expense.user?.name || '—' }}</p>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div v-if="expense.description">
                            <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500">Notas</p>
                            <p class="whitespace-pre-line rounded-xl bg-gray-50 p-3.5 text-sm text-gray-700 ring-1 ring-gray-100">{{ expense.description }}</p>
                        </div>

                        <!-- Adjuntos -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Adjuntos</p>
                                <span class="text-[11px] text-gray-400">{{ attachments.length }} archivo{{ attachments.length === 1 ? '' : 's' }}</span>
                            </div>
                            <div v-if="!attachments.length" class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-xs text-gray-400">
                                Sin adjuntos.
                            </div>

                            <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <button v-for="(att, i) in attachments" :key="att.id" type="button" @click="openViewer(i)"
                                    class="group relative flex aspect-square flex-col items-center justify-center gap-2 overflow-hidden rounded-xl bg-gray-50 p-3 ring-1 ring-gray-100 transition hover:bg-red-50/40 hover:ring-red-200">
                                    <!-- Image thumbnail -->
                                    <img v-if="isImage(att)" :src="previewUrlBuilder(att)" :alt="att.original_name"
                                        class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                    <!-- PDF icon -->
                                    <div v-else-if="isPdf(att)" class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-600">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                    </div>
                                    <!-- Generic icon -->
                                    <div v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-200 text-gray-500">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                    </div>
                                    <!-- Filename overlay -->
                                    <div :class="['absolute inset-x-0 bottom-0 px-2 py-1.5 text-left text-[10px] font-semibold',
                                        isImage(att) ? 'bg-gradient-to-t from-black/80 to-transparent text-white' : 'text-gray-700']">
                                        <p class="truncate">{{ att.original_name }}</p>
                                        <p :class="['truncate text-[9px]', isImage(att) ? 'text-white/70' : 'text-gray-400']">{{ fmtSize(att.size_bytes) }}</p>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-between gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <button v-if="canDelete" @click="$emit('delete')" class="rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-red-600 ring-1 ring-red-200 transition hover:bg-red-50">
                            Eliminar
                        </button>
                        <span v-else></span>
                        <div class="flex gap-3">
                            <button @click="$emit('close')" class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cerrar</button>
                            <button v-if="canEdit" @click="$emit('edit')" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">Editar</button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Viewer modal layered above -->
        <AttachmentViewerModal
            :show="viewerOpen"
            :attachments="attachments"
            :initial-index="viewerIndex"
            :preview-url="previewUrlBuilder"
            :download-url="downloadUrlBuilder"
            @close="viewerOpen = false" />
    </Teleport>
</template>
