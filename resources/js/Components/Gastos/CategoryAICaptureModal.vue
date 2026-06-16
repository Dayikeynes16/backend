<script setup>
import { computed, ref, watch } from 'vue';
import { useAudioRecorder } from '@/composables/useAudioRecorder';
import { useCategoryAiDraft } from '@/composables/useCategoryAiDraft';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    routePrefix: { type: String, default: 'empresa' },
});

const emit = defineEmits(['close', 'proposal']);

const text = ref('');
const { submitDraft, submitting, error } = useCategoryAiDraft();
const recorder = useAudioRecorder({ maxSeconds: 90 });

const canAnalyze = computed(() =>
    !submitting.value
    && !recorder.isRecording.value
    && (text.value.trim() !== '' || recorder.hasRecording.value)
);

const formatDuration = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

const toggleRecording = async () => {
    if (recorder.isRecording.value) recorder.stopRecording();
    else await recorder.startRecording();
};

const reset = () => {
    text.value = '';
    recorder.reset();
};

watch(() => props.show, (v) => { if (!v) reset(); });

const analyze = async () => {
    if (!canAnalyze.value) return;
    try {
        const userText = text.value.trim();
        const result = await submitDraft({
            tenantSlug: props.tenantSlug,
            text: userText,
            audio: recorder.audioBlob.value,
            routePrefix: props.routePrefix,
        });
        // Pasamos el texto que escribió el usuario para que el modal de revisión
        // pueda usarlo como base si la IA pide aclaración (modo iterativo).
        // NO emitimos 'close' aquí: el parent transiciona el state machine
        // (catIAStep capture → review) que oculta este modal por sí solo.
        // Si emitiéramos close, el handler @close="catIAStep='idle'" del parent
        // sobrescribiría el 'review' que setea @proposal en el mismo tick.
        emit('proposal', { ...result, originalText: userText });
    } catch {
        // error se muestra en banner
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="!submitting && $emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-xl flex-col rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white shadow-sm">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Crear categoría con IA</h3>
                                <p class="mt-0.5 text-xs text-gray-500">Describe qué tipo de gastos quieres agrupar. La IA propone nombre, descripción, subcategorías y sinónimos.</p>
                            </div>
                        </div>
                        <button @click="!submitting && $emit('close')" :disabled="submitting"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex flex-1 flex-col overflow-y-auto">
                        <div class="space-y-5 px-6 py-5">
                            <!-- Texto -->
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Explica qué quieres agrupar en esta categoría</label>
                                <textarea v-model="text" rows="5" maxlength="2000" :disabled="submitting"
                                    placeholder="Ej. Quiero una categoría para gastos de gasolina, mantenimiento de camionetas, llantas, refacciones y cosas de reparto. No quiero que entren gastos personales ni compras de mercancía."
                                    class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:ring-violet-300 disabled:bg-gray-50" />
                                <ul class="mt-2 space-y-0.5 text-[11px] text-gray-500">
                                    <li>· Menciona qué tipo de gastos SÍ deben entrar</li>
                                    <li>· Si aplica, qué gastos NO deberían entrar</li>
                                    <li>· Si quieres separar en subcategorías, dilo</li>
                                </ul>
                            </div>

                            <!-- Nota de voz -->
                            <div v-if="recorder.isSupported">
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">O grábalo por voz</label>

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
                                    <button type="button" @click="toggleRecording" class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700">Detener</button>
                                </div>

                                <div v-else-if="recorder.hasRecording.value" class="rounded-2xl border-2 border-violet-200 bg-violet-50/40 px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <svg class="h-5 w-5 shrink-0 text-violet-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                        </svg>
                                        <audio :src="recorder.audioUrl.value" controls class="h-9 flex-1" preload="metadata"></audio>
                                        <button type="button" @click="recorder.reset()" :disabled="submitting"
                                            class="rounded-lg p-1.5 text-violet-700 hover:bg-violet-100 disabled:opacity-30" title="Borrar y volver a grabar">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <button v-else type="button" @click="toggleRecording" :disabled="submitting"
                                    class="group flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-violet-200 bg-violet-50/40 px-4 py-3 text-center transition hover:border-violet-400 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50">
                                    <svg class="h-5 w-5 text-violet-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                    </svg>
                                    <span class="text-sm font-semibold text-violet-700">Grabar nota de voz</span>
                                </button>

                                <p v-if="recorder.error.value" class="mt-1 text-xs text-red-600">{{ recorder.error.value }}</p>
                            </div>

                            <!-- Loading -->
                            <div v-if="submitting" class="rounded-2xl border border-violet-200 bg-violet-50/50 p-4 text-center">
                                <div class="flex items-center justify-center gap-3">
                                    <svg class="h-5 w-5 animate-spin text-violet-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <p class="text-sm font-semibold text-violet-800">Analizando tu solicitud…</p>
                                </div>
                                <p class="mt-1 text-xs text-violet-700">
                                    <template v-if="recorder.hasRecording.value">Transcribiendo audio y proponiendo categoría. </template>
                                    <template v-else>Construyendo propuesta. </template>
                                    Puede tomar 10–30 segundos.
                                </p>
                            </div>

                            <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-3">
                                <p class="text-sm font-semibold text-red-800">No se pudo analizar</p>
                                <p class="mt-0.5 text-xs text-red-700">{{ error }}</p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                            <button type="button" @click="$emit('close')" :disabled="submitting"
                                class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200 disabled:opacity-30">Cancelar</button>
                            <button type="button" @click="analyze" :disabled="!canAnalyze"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg v-if="!submitting" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09Z" /></svg>
                                {{ submitting ? 'Analizando…' : 'Analizar con IA' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
