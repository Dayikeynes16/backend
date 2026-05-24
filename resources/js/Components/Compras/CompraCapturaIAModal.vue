<script setup>
import { useAudioRecorder } from '@/composables/useAudioRecorder';
import { usePurchaseAiDraft } from '@/composables/usePurchaseAiDraft';
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import CameraCaptureModal from '@/Components/CameraCaptureModal.vue';
import { isMobileDevice } from '@/utils/device';

const props = defineProps({
    open: { type: Boolean, default: false },
    routes: { type: Object, required: true }, // { iaStore: 'empresa.compras.ia.store' }
});
const emit = defineEmits(['close', 'analyzed']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const { submitDraft, loading, error } = usePurchaseAiDraft();
const recorder = useAudioRecorder({ maxSeconds: 90 });

const text = ref('');
const files = ref([]);
const filesPreview = computed(() => files.value.map((f, i) => ({ index: i, name: f.name, size: f.size })));

watch(() => props.open, (open) => {
    if (open) {
        text.value = '';
        files.value = [];
        recorder.reset();
    }
});

const onFiles = (e) => {
    const selected = Array.from(e.target.files || []).slice(0, 5);
    files.value = selected;
};

const removeFile = (idx) => { files.value = files.value.filter((_, i) => i !== idx); };

// Cámara nativa del dispositivo (igual que gastos): mejor resolución/enfoque.
// Agrega a los adjuntos respetando el límite de 5 y permite tomar otra.
const onCameraFile = (e) => {
    const picked = Array.from(e.target.files || []);
    if (picked.length) {
        files.value = [...files.value, ...picked].slice(0, 5);
    }
    e.target.value = '';
};

// "Tomar foto": móvil → cámara nativa (input capture); desktop → webcam (getUserMedia).
const cameraInput = ref(null);
const cameraModalOpen = ref(false);
const onTakePhoto = () => {
    if (isMobileDevice()) {
        cameraInput.value?.click();
    } else {
        cameraModalOpen.value = true;
    }
};
const onCameraCapture = (file) => {
    files.value = [...files.value, file].slice(0, 5);
};

const recordTimeLabel = computed(() => {
    const s = recorder.duration.value;
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return `${mm}:${ss}`;
});

const toggleRecording = () => {
    if (recorder.isRecording.value) recorder.stopRecording();
    else recorder.startRecording();
};

const removeRecording = () => recorder.reset();

const analyze = async () => {
    if (loading.value) return;
    const hasAny = (text.value.trim() !== '') || files.value.length > 0 || recorder.audioBlob.value !== null;
    if (!hasAny) {
        error.value = 'Aporta al menos un texto, una foto o un audio.';
        return;
    }
    try {
        const result = await submitDraft({
            routeName: props.routes.iaStore,
            tenantSlug: slug.value,
            text: text.value,
            files: files.value,
            audio: recorder.audioBlob.value,
        });
        emit('analyzed', result);
    } catch (e) {
        // error.value ya seteado por el composable.
    }
};

const close = () => { emit('close'); };
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-2xl overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 bg-gradient-to-r from-violet-50 to-fuchsia-50 px-5 py-4">
                        <div>
                            <h2 class="flex items-center gap-2 text-lg font-bold text-gray-900">
                                <span class="rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 px-2 py-0.5 text-xs font-bold text-white">IA</span>
                                Capturar compra con IA
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-600">Manda foto de la factura + audio o texto. La IA pre-rellena el form; tú revisas antes de guardar.</p>
                        </div>
                        <button @click="close" :disabled="loading" class="text-gray-400 hover:text-gray-700 disabled:opacity-50">✕</button>
                    </header>

                    <div class="max-h-[80vh] space-y-5 overflow-y-auto px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Texto (opcional)</label>
                            <textarea v-model="text" rows="3" :disabled="loading"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500 disabled:bg-gray-50"
                                placeholder="Ej. 'Compré 25 kg de pulpa a Don Pedro a $185 el kg, factura F-4521, total $4,625'"></textarea>
                        </div>

                        <!-- Adjuntos -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Foto / PDF de la factura (hasta 5)</label>
                            <input type="file" multiple accept="image/jpeg,image/png,image/webp" @change="onFiles" :disabled="loading"
                                class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-violet-700 hover:file:bg-violet-100" />
                            <button type="button" @click="onTakePhoto" :disabled="loading || files.length >= 5"
                                class="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 transition hover:bg-violet-100 disabled:pointer-events-none disabled:opacity-50">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                                </svg>
                                Tomar foto
                            </button>
                            <input ref="cameraInput" type="file" accept="image/*" capture="environment" @change="onCameraFile" class="hidden" :disabled="loading || files.length >= 5" />
                            <p class="mt-1 text-xs text-gray-500">jpg/png/webp · 5 MB c/u. PDFs no procesa GPT-4o aún.</p>
                            <ul v-if="filesPreview.length" class="mt-2 space-y-1">
                                <li v-for="f in filesPreview" :key="f.index" class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-1.5 text-xs">
                                    <span class="truncate text-gray-700">{{ f.name }}</span>
                                    <span class="ml-2 flex items-center gap-2">
                                        <span class="text-gray-500">{{ Math.ceil(f.size / 1024) }} KB</span>
                                        <button type="button" @click="removeFile(f.index)" class="text-red-600 hover:text-red-800">✕</button>
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <!-- Voz -->
                        <div v-if="recorder.isSupported">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nota de voz (opcional, hasta 90s)</label>
                            <div v-if="!recorder.audioBlob.value" class="flex items-center gap-3">
                                <button type="button" @click="toggleRecording" :disabled="loading"
                                    :class="['flex h-12 w-12 items-center justify-center rounded-full transition',
                                        recorder.isRecording.value
                                            ? 'animate-pulse bg-red-600 text-white'
                                            : 'bg-violet-600 text-white hover:bg-violet-700']">
                                    <svg v-if="!recorder.isRecording.value" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                    </svg>
                                    <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
                                </button>
                                <span v-if="recorder.isRecording.value" class="text-sm font-medium text-red-700">Grabando… {{ recordTimeLabel }}</span>
                                <span v-else class="text-sm text-gray-600">Click para grabar</span>
                            </div>
                            <div v-else class="flex items-center gap-3 rounded-lg bg-violet-50 px-3 py-2">
                                <audio :src="recorder.audioUrl.value" controls class="flex-1 max-w-sm"></audio>
                                <button type="button" @click="removeRecording" class="text-sm font-medium text-red-600 hover:text-red-800">✕ Borrar</button>
                            </div>
                            <p v-if="recorder.error.value" class="mt-1 text-xs text-red-600">{{ recorder.error.value }}</p>
                        </div>

                        <div v-if="error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</div>
                    </div>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" :disabled="loading"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50">Cancelar</button>
                        <button @click="analyze" :disabled="loading"
                            class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:from-violet-700 hover:to-fuchsia-700 disabled:opacity-50">
                            <span v-if="!loading">Analizar con IA</span>
                            <span v-else class="flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                    <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                                    <path d="M22 12a10 10 0 0 1-10 10" />
                                </svg>
                                Analizando…
                            </span>
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Webcam (desktop): captura con getUserMedia cuando `capture` no aplica -->
    <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
</template>
