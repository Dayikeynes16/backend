<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useExpenseAiDraft } from '@/composables/useExpenseAiDraft';
import { useAudioRecorder } from '@/composables/useAudioRecorder';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    /** Ziggy route name: empresa.gastos.ia.store | sucursal.gastos.ia.store */
    submitRouteName: { type: String, required: true },
});

const emit = defineEmits(['close', 'proposal']);

const MAX_IMAGES = 5;
const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

const text = ref('');
const files = ref([]);
const previews = ref(new Map());
const fileError = ref('');

const { submitDraft, loading, error } = useExpenseAiDraft();
const recorder = useAudioRecorder({ maxSeconds: 90 });

const fileInput = ref(null);
const cameraInput = ref(null);

const canAnalyze = computed(() =>
    !loading.value
    && !recorder.isRecording.value
    && (text.value.trim() !== '' || files.value.length > 0 || recorder.hasRecording.value)
);

const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
};

const toggleRecording = async () => {
    if (recorder.isRecording.value) {
        recorder.stopRecording();
    } else {
        await recorder.startRecording();
    }
};

const remainingSlots = computed(() => Math.max(0, MAX_IMAGES - files.value.length));

const revokeAllPreviews = () => {
    previews.value.forEach(url => URL.revokeObjectURL(url));
    previews.value.clear();
};

const reset = () => {
    text.value = '';
    files.value = [];
    fileError.value = '';
    revokeAllPreviews();
    recorder.reset();
    if (fileInput.value) fileInput.value.value = '';
    if (cameraInput.value) cameraInput.value.value = '';
};

watch(() => props.show, (val) => { if (!val) reset(); });

onBeforeUnmount(() => revokeAllPreviews());

const addFiles = (incoming) => {
    fileError.value = '';
    if (!incoming.length) return;

    for (const f of incoming) {
        if (!ALLOWED_MIMES.includes(f.type)) {
            fileError.value = `Tipo no permitido: ${f.name}. Solo imágenes (jpg, png, webp).`;
            return;
        }
        if (f.size > MAX_BYTES) {
            fileError.value = `Imagen demasiado grande (máx 5 MB): ${f.name}`;
            return;
        }
    }
    if (incoming.length > remainingSlots.value) {
        fileError.value = `Máximo ${MAX_IMAGES} imágenes por análisis.`;
        return;
    }

    incoming.forEach(f => {
        files.value.push(f);
        previews.value.set(f, URL.createObjectURL(f));
    });
};

const onFileSelect = (e) => {
    addFiles(Array.from(e.target.files || []));
    e.target.value = '';
};

const removeFile = (i) => {
    const f = files.value[i];
    const url = previews.value.get(f);
    if (url) {
        URL.revokeObjectURL(url);
        previews.value.delete(f);
    }
    files.value = files.value.filter((_, idx) => idx !== i);
};

const analyze = async () => {
    if (!canAnalyze.value) return;
    try {
        const result = await submitDraft({
            routeName: props.submitRouteName,
            tenantSlug: props.tenantSlug,
            text: text.value.trim(),
            files: files.value,
            audio: recorder.audioBlob.value,
        });
        emit('proposal', result);
        emit('close');
    } catch {
        // error ya se setea en el composable; lo mostramos en banner
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="!loading && $emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white shadow-sm">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Registrar gasto con IA</h3>
                                <p class="mt-0.5 text-xs text-gray-500">Aporta una foto del ticket, escribe lo que compraste, o ambos.</p>
                            </div>
                        </div>
                        <button @click="!loading && $emit('close')" :disabled="loading"
                            class="flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex flex-1 flex-col overflow-y-auto">
                        <div class="space-y-5 px-6 py-5">
                            <!-- Texto -->
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">¿Qué gasto fue?</label>
                                <textarea v-model="text" rows="3" maxlength="2000" :disabled="loading"
                                    placeholder="Ej. Compré gasolina para la camioneta, 850 pesos en efectivo."
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:ring-violet-300 disabled:bg-gray-50" />
                                <p class="mt-1 text-[11px] text-gray-400">{{ text.length }} / 2000 caracteres</p>
                            </div>

                            <!-- Nota de voz -->
                            <div v-if="recorder.isSupported">
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Nota de voz (opcional)</label>

                                <!-- Estado: grabando -->
                                <div v-if="recorder.isRecording.value" class="flex items-center justify-between gap-3 rounded-2xl border-2 border-red-300 bg-red-50 px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="relative flex h-3 w-3">
                                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                            <span class="relative inline-flex h-3 w-3 rounded-full bg-red-600"></span>
                                        </span>
                                        <div>
                                            <p class="text-sm font-bold text-red-800">Grabando…</p>
                                            <p class="font-mono text-xs text-red-600">{{ formatDuration(recorder.duration.value) }} / {{ formatDuration(recorder.maxSeconds) }}</p>
                                        </div>
                                    </div>
                                    <button type="button" @click="toggleRecording" class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700">
                                        Detener
                                    </button>
                                </div>

                                <!-- Estado: hay grabación lista -->
                                <div v-else-if="recorder.hasRecording.value" class="rounded-2xl border-2 border-violet-200 bg-violet-50/40 px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <svg class="h-5 w-5 shrink-0 text-violet-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                        </svg>
                                        <audio :src="recorder.audioUrl.value" controls class="h-9 flex-1" preload="metadata"></audio>
                                        <button type="button" @click="recorder.reset()" :disabled="loading"
                                            class="rounded-lg p-1.5 text-violet-700 hover:bg-violet-100 disabled:opacity-30" title="Borrar y volver a grabar">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Estado: vacío -->
                                <button v-else type="button" @click="toggleRecording" :disabled="loading"
                                    class="group flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-violet-200 bg-violet-50/40 px-4 py-3 text-center transition hover:border-violet-400 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50">
                                    <svg class="h-5 w-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                    </svg>
                                    <span class="text-sm font-semibold text-violet-700">Grabar nota de voz</span>
                                </button>

                                <p v-if="recorder.error.value" class="mt-1 text-xs text-red-600">{{ recorder.error.value }}</p>
                                <p class="mt-1 text-[11px] text-gray-400">Hasta {{ formatDuration(recorder.maxSeconds) }} minutos. Se transcribe con Whisper en español.</p>
                            </div>

                            <!-- Imágenes -->
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <label class="text-xs font-semibold text-gray-600">Foto del ticket o comprobante</label>
                                    <span class="text-[11px] text-gray-400">jpg · png · webp · 5 MB · {{ MAX_IMAGES }} máx</span>
                                </div>

                                <div v-if="remainingSlots > 0" class="grid grid-cols-2 gap-2">
                                    <label class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-violet-200 bg-violet-50/40 px-4 py-3 text-center transition hover:border-violet-400 hover:bg-violet-50"
                                        :class="{ 'pointer-events-none opacity-50': loading }">
                                        <svg class="h-5 w-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                        </svg>
                                        <span class="text-sm font-semibold text-violet-700">Tomar foto</span>
                                        <input ref="cameraInput" type="file" accept="image/*" capture="environment" @change="onFileSelect" class="hidden" />
                                    </label>
                                    <label class="group flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-200 px-4 py-3 text-center transition hover:border-gray-300 hover:bg-gray-50"
                                        :class="{ 'pointer-events-none opacity-50': loading }">
                                        <svg class="h-5 w-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                                        </svg>
                                        <span class="text-sm font-semibold text-gray-700">Subir imagen</span>
                                        <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp" @change="onFileSelect" class="hidden" />
                                    </label>
                                </div>

                                <div v-if="files.length > 0" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    <div v-for="(f, i) in files" :key="i" class="group relative aspect-square overflow-hidden rounded-xl bg-violet-50 ring-1 ring-violet-200">
                                        <img v-if="previews.get(f)" :src="previews.get(f)" :alt="f.name" class="h-full w-full object-cover" />
                                        <button type="button" @click="removeFile(i)" :disabled="loading"
                                            class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow ring-1 ring-gray-200 transition hover:bg-red-600 hover:text-white disabled:opacity-30">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <p v-if="fileError" class="mt-2 text-xs text-red-600">{{ fileError }}</p>
                            </div>

                            <!-- Loading state -->
                            <div v-if="loading" class="rounded-2xl border border-violet-200 bg-violet-50/50 p-4 text-center">
                                <div class="flex items-center justify-center gap-3">
                                    <svg class="h-5 w-5 animate-spin text-violet-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <p class="text-sm font-semibold text-violet-800">Analizando tu gasto…</p>
                                </div>
                                <p class="mt-1 text-xs text-violet-700">
                                    <template v-if="recorder.hasRecording.value && files.length">Transcribiendo audio, leyendo {{ files.length }} {{ files.length === 1 ? 'imagen' : 'imágenes' }} y clasificando.</template>
                                    <template v-else-if="recorder.hasRecording.value">Transcribiendo audio y clasificando.</template>
                                    <template v-else-if="files.length">Leyendo {{ files.length }} {{ files.length === 1 ? 'imagen' : 'imágenes' }} y clasificando.</template>
                                    <template v-else>Clasificando con IA.</template>
                                    Puede tomar 10–30 segundos.
                                </p>
                            </div>

                            <!-- Error -->
                            <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-3">
                                <p class="text-sm font-semibold text-red-800">No se pudo analizar</p>
                                <p class="mt-0.5 text-xs text-red-700">{{ error }}</p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                            <button type="button" @click="$emit('close')" :disabled="loading"
                                class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200 disabled:opacity-30">
                                Cancelar
                            </button>
                            <button type="button" @click="analyze" :disabled="!canAnalyze"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg v-if="!loading" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                {{ loading ? 'Analizando…' : 'Analizar con IA' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
