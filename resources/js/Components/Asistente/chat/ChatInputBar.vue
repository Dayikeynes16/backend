<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const inputRef = ref(null);
const imageInputRef = ref(null);

// Textarea auto-grow: se ajusta entre 3 y 8 líneas según contenido.
const MIN_INPUT_HEIGHT = 84;   // ~3 líneas con text-base
const MAX_INPUT_HEIGHT = 220;  // ~8 líneas; después aparece scroll interno

const resizeInput = () => {
    const el = inputRef.value;
    if (!el) return;
    el.style.height = 'auto';
    const next = Math.min(MAX_INPUT_HEIGHT, Math.max(MIN_INPUT_HEIGHT, el.scrollHeight));
    el.style.height = next + 'px';
    el.style.overflowY = el.scrollHeight > MAX_INPUT_HEIGHT ? 'auto' : 'hidden';
};

watch(() => props.chat.inputText, resizeInput);

function onImageSelected(e) {
    props.chat.selectImage(e.target?.files?.[0]);
    if (imageInputRef.value) imageInputRef.value.value = '';
}
</script>

<template>
    <div class="border-t border-gray-200 p-4">
        <div v-if="chat.errorBanner" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">{{ chat.errorBanner }}</div>

        <!-- Estado de grabación / transcripción -->
        <div v-if="chat.isRecording" class="mb-2 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
            <span class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-red-600"></span>
                Grabando… {{ chat.recordTimeLabel }}
            </span>
            <button type="button" @click="chat.toggleRecording()" class="font-semibold text-red-700 underline hover:text-red-900">
                Detener
            </button>
        </div>
        <div v-else-if="chat.transcribing" class="mb-2 flex items-center gap-2 rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-800">
            <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                <path d="M22 12a10 10 0 0 1-10 10" />
            </svg>
            Transcribiendo audio…
        </div>

        <!-- Recibo adjunto seleccionado -->
        <div v-if="chat.pendingImage" class="mb-2 flex items-center justify-between rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-xs text-orange-800">
            <span class="flex min-w-0 items-center gap-2 truncate">📎 {{ chat.pendingImage.name }}</span>
            <button type="button" @click="chat.clearImage()" class="shrink-0 font-semibold text-orange-700 underline hover:text-orange-900">Quitar</button>
        </div>

        <form @submit.prevent="chat.send()" class="flex items-end gap-2">
            <input ref="imageInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onImageSelected" />

            <textarea
                ref="inputRef"
                v-model="chat.inputText"
                :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                rows="3"
                placeholder="Escribe o adjunta un recibo…"
                class="flex-1 resize-none rounded-xl border-gray-300 px-4 py-3 text-base leading-relaxed focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                style="min-height: 84px;"
                @keydown.enter.exact.prevent="chat.send()"
                @input="resizeInput"
            />

            <!-- Adjuntar recibo (imagen) para preparar un gasto. -->
            <button
                type="button"
                @click="imageInputRef?.click()"
                :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                title="Adjuntar recibo"
                class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl border border-gray-300 bg-white text-gray-600 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
            </button>

            <!-- Botón micrófono. Sólo si el navegador soporta MediaRecorder y hay ruta de transcripción. -->
            <button
                v-if="chat.micSupported && chat.routes.transcribe"
                type="button"
                @click="chat.toggleRecording()"
                :disabled="!chat.activeSessionId || chat.sending || chat.transcribing"
                :title="chat.isRecording ? 'Detener grabación' : 'Dictar por voz'"
                :class="[
                    'flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl border transition disabled:cursor-not-allowed disabled:opacity-50',
                    chat.isRecording
                        ? 'border-red-300 bg-red-100 text-red-700 hover:bg-red-200'
                        : 'border-gray-300 bg-white text-gray-600 hover:border-orange-400 hover:bg-orange-50 hover:text-orange-700',
                ]"
            >
                <svg v-if="!chat.isRecording" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                </svg>
                <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                    <rect x="6" y="6" width="12" height="12" rx="2" />
                </svg>
            </button>

            <button
                type="submit"
                :disabled="!chat.activeSessionId || chat.sending || (!chat.inputText.trim() && !chat.pendingImage) || chat.isRecording || chat.transcribing"
                class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Enviar
            </button>
        </form>
    </div>
</template>
