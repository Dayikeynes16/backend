<script setup>
import { ref, watch } from 'vue';
import CameraCaptureModal from '@/Components/CameraCaptureModal.vue';
import { isMobileDevice } from '@/utils/device';

const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const inputRef = ref(null);
const imageInputRef = ref(null);   // galería / archivos
const cameraInputRef = ref(null);  // cámara nativa (móvil, capture=environment)

// Menú del clip: Tomar foto / Subir imagen. En móvil "tomar foto" usa el input
// nativo con capture; en desktop abre CameraCaptureModal (getUserMedia), el
// mismo patrón de los modales de captura IA de Gastos/Compras.
const attachMenuOpen = ref(false);
const cameraModalOpen = ref(false);

function takePhoto() {
    attachMenuOpen.value = false;
    if (isMobileDevice()) {
        cameraInputRef.value?.click();
    } else {
        cameraModalOpen.value = true;
    }
}

function pickImage() {
    attachMenuOpen.value = false;
    imageInputRef.value?.click();
}

function onImageSelected(e) {
    props.chat.selectImage(e.target?.files?.[0]);
    if (e.target) e.target.value = '';
}

function onCameraCapture(file) {
    cameraModalOpen.value = false;
    props.chat.selectImage(file);
}

// Compositor compacto: arranca en una línea y crece hasta ~5; después scroll interno.
const MIN_INPUT_HEIGHT = 44;
const MAX_INPUT_HEIGHT = 148;

const resizeInput = () => {
    const el = inputRef.value;
    if (!el) return;
    el.style.height = 'auto';
    const next = Math.min(MAX_INPUT_HEIGHT, Math.max(MIN_INPUT_HEIGHT, el.scrollHeight));
    el.style.height = next + 'px';
    el.style.overflowY = el.scrollHeight > MAX_INPUT_HEIGHT ? 'auto' : 'hidden';
};

watch(() => props.chat.inputText, resizeInput);
</script>

<template>
    <div class="border-t border-gray-100 bg-white px-3 pb-3 pt-2 sm:px-4">
        <div class="mx-auto w-full max-w-3xl">
            <div v-if="chat.errorBanner" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800" role="alert">{{ chat.errorBanner }}</div>

            <!-- Estado de grabación / transcripción -->
            <div v-if="chat.isRecording" class="mb-2 flex items-center justify-between rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="status">
                <span class="flex items-center gap-2">
                    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-red-600"></span>
                    Grabando… {{ chat.recordTimeLabel }}
                </span>
                <button type="button" @click="chat.toggleRecording()" class="font-semibold text-red-700 underline-offset-2 hover:underline">
                    Detener
                </button>
            </div>
            <div v-else-if="chat.transcribing" class="mb-2 flex items-center gap-2 rounded-xl bg-orange-50 px-3 py-2 text-xs text-orange-800" role="status">
                <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.3" /><path d="M22 12a10 10 0 0 1-10 10" /></svg>
                Transcribiendo audio…
            </div>

            <!-- Recibo adjunto seleccionado -->
            <div v-if="chat.pendingImage" class="mb-2 inline-flex max-w-full items-center gap-2 rounded-full border border-orange-200 bg-orange-50 py-1 pl-3 pr-1 text-xs text-orange-900">
                <span class="truncate">📎 {{ chat.pendingImage.name }}</span>
                <button type="button" @click="chat.clearImage()" aria-label="Quitar adjunto" class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-orange-700 transition-colors duration-150 hover:bg-orange-200">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <form
                @submit.prevent="chat.send()"
                class="flex items-end gap-1.5 rounded-3xl border border-gray-200 bg-white p-1.5 transition-colors duration-150 focus-within:border-orange-300"
            >
                <input ref="imageInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onImageSelected" />
                <input ref="cameraInputRef" type="file" accept="image/*" capture="environment" class="hidden" @change="onImageSelected" />

                <!-- Adjuntar: menú Tomar foto / Subir imagen -->
                <div class="relative">
                    <button
                        type="button"
                        @click="attachMenuOpen = !attachMenuOpen"
                        :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                        title="Adjuntar foto de recibo o factura"
                        aria-label="Adjuntar foto de recibo o factura"
                        :aria-expanded="attachMenuOpen"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-400 transition-colors duration-150 hover:bg-orange-50 hover:text-orange-700 disabled:cursor-not-allowed disabled:opacity-40"
                        :class="attachMenuOpen ? 'bg-orange-50 text-orange-700' : ''"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
                    </button>

                    <!-- Cerrar al tocar fuera -->
                    <div v-if="attachMenuOpen" class="fixed inset-0 z-30" @click="attachMenuOpen = false" aria-hidden="true"></div>

                    <Transition
                        enter-active-class="transition duration-150 ease-out"
                        leave-active-class="transition duration-100 ease-in"
                        enter-from-class="translate-y-1 opacity-0"
                        leave-to-class="translate-y-1 opacity-0"
                    >
                        <div v-if="attachMenuOpen" class="absolute bottom-11 left-0 z-40 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
                            <button type="button" @click="takePhoto" class="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm text-gray-700 transition-colors duration-150 hover:bg-orange-50 hover:text-orange-900">
                                <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                                Tomar foto
                            </button>
                            <button type="button" @click="pickImage" class="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm text-gray-700 transition-colors duration-150 hover:bg-orange-50 hover:text-orange-900">
                                <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                                Subir imagen
                            </button>
                        </div>
                    </Transition>
                </div>

                <textarea
                    ref="inputRef"
                    v-model="chat.inputText"
                    :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                    rows="1"
                    placeholder="Pregunta o registra algo…"
                    aria-label="Mensaje para el asistente"
                    class="max-h-[148px] min-h-[44px] flex-1 resize-none self-center border-0 bg-transparent px-2 py-2.5 text-[15px] leading-snug text-gray-900 placeholder:text-gray-400 focus:ring-0 disabled:opacity-50"
                    style="height: 44px;"
                    @keydown.enter.exact.prevent="chat.send()"
                    @input="resizeInput"
                />

                <!-- Micrófono -->
                <button
                    v-if="chat.micSupported && chat.routes.transcribe"
                    type="button"
                    @click="chat.toggleRecording()"
                    :disabled="!chat.activeSessionId || chat.sending || chat.transcribing"
                    :title="chat.isRecording ? 'Detener grabación' : 'Dictar por voz'"
                    :aria-label="chat.isRecording ? 'Detener grabación' : 'Dictar por voz'"
                    :class="[
                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-40',
                        chat.isRecording
                            ? 'bg-red-100 text-red-700 hover:bg-red-200'
                            : 'text-gray-400 hover:bg-orange-50 hover:text-orange-700',
                    ]"
                >
                    <svg v-if="!chat.isRecording" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
                    <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>
                </button>

                <!-- Enviar -->
                <button
                    type="submit"
                    :disabled="!chat.activeSessionId || chat.sending || (!chat.inputText.trim() && !chat.pendingImage) || chat.isRecording || chat.transcribing"
                    aria-label="Enviar mensaje"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-red-600 text-white transition-[transform,opacity] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] hover:from-orange-600 hover:to-red-700 active:scale-95 disabled:cursor-not-allowed disabled:opacity-35 disabled:saturate-50"
                >
                    <svg v-if="chat.sending" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.3" /><path d="M22 12a10 10 0 0 1-10 10" /></svg>
                    <svg v-else class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z" /></svg>
                </button>
            </form>
        </div>

        <!-- Webcam para desktop (mismo modal que la captura IA de Gastos/Compras) -->
        <CameraCaptureModal v-model:open="cameraModalOpen" @capture="onCameraCapture" />
    </div>
</template>
