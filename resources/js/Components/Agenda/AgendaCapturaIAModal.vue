<script setup>
import { useAudioRecorder } from '@/composables/useAudioRecorder';
import { useAgendaAiDraft } from '@/composables/useAgendaAiDraft';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
});
const emit = defineEmits(['close', 'proposal']);

const { submitDraft, loading, error } = useAgendaAiDraft();
const recorder = useAudioRecorder({ maxSeconds: 90 });

const text = ref('');

watch(() => props.open, (open) => {
    if (open) {
        text.value = '';
        error.value = '';
        recorder.reset();
    }
});

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
    const hasAny = (text.value.trim() !== '') || recorder.audioBlob.value !== null;
    if (!hasAny) {
        error.value = 'Dicta o escribe algo para que la IA arme el recordatorio.';
        return;
    }
    try {
        const result = await submitDraft({
            tenantSlug: props.tenantSlug,
            text: text.value,
            audio: recorder.audioBlob.value,
        });
        emit('proposal', result.proposal);
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
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 bg-gradient-to-r from-violet-50 to-fuchsia-50 px-5 py-4">
                        <div>
                            <h2 class="flex items-center gap-2 text-lg font-bold text-gray-900">
                                <span class="rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 px-2 py-0.5 text-xs font-bold text-white">IA</span>
                                Dictar a la agenda
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-600">Dicta o escribe el recordatorio. La IA arma el borrador; tú lo revisas antes de guardar.</p>
                        </div>
                        <button @click="close" :disabled="loading" class="text-gray-400 hover:text-gray-700 disabled:opacity-50">✕</button>
                    </header>

                    <div class="max-h-[80vh] space-y-5 overflow-y-auto px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Texto (opcional)</label>
                            <textarea v-model="text" rows="3" :disabled="loading"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-violet-500 focus:ring-violet-500 disabled:bg-gray-50"
                                placeholder="Ej. 'Recuérdame entregar carne a las 2pm mañana'"></textarea>
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
                                <audio :src="recorder.audioUrl.value" controls class="max-w-sm flex-1"></audio>
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
</template>
