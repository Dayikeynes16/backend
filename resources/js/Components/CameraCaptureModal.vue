<script setup>
/**
 * Captura de foto con la webcam (getUserMedia) para desktop, donde el atributo
 * `capture` de los <input type="file"> es ignorado por el navegador.
 * Emite un File JPEG vía `@capture`. Requiere contexto seguro (https o localhost).
 *
 * Si el equipo tiene varias cámaras (p. ej. tablets Windows con frontal y
 * trasera), muestra un selector para cambiar entre ellas.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'capture']);

const video = ref(null);
const stream = ref(null);
const error = ref('');
const starting = ref(false);
const captured = ref(null); // { url, blob }

const devices = ref([]); // [{ deviceId, label }]
const currentDeviceId = ref('');

const stopStream = () => {
    if (stream.value) {
        stream.value.getTracks().forEach(t => t.stop());
        stream.value = null;
    }
};

const clearCaptured = () => {
    if (captured.value) {
        URL.revokeObjectURL(captured.value.url);
        captured.value = null;
    }
};

const mapError = (e) => {
    if (e?.name === 'NotAllowedError' || e?.name === 'SecurityError') {
        return 'Permiso de cámara denegado. Habilítalo en el navegador o usa “Subir imagen”.';
    }
    if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError' || e?.name === 'DevicesNotFoundError') {
        return 'No se detectó ninguna cámara en este equipo. Usa “Subir imagen”.';
    }
    if (e?.name === 'NotReadableError') {
        return 'La cámara está siendo usada por otra aplicación. Ciérrala e intenta de nuevo.';
    }
    if (e?.message === 'unsupported') {
        return 'Tu navegador no permite usar la cámara aquí (requiere https). Usa “Subir imagen”.';
    }
    return 'No se pudo iniciar la cámara. Usa “Subir imagen”.';
};

/**
 * Refresca la lista de cámaras. Las etiquetas solo están disponibles tras
 * conceder el permiso, por eso se llama después de abrir el primer stream.
 */
const refreshDevices = async () => {
    if (!navigator.mediaDevices?.enumerateDevices) {
        return;
    }
    try {
        const all = await navigator.mediaDevices.enumerateDevices();
        devices.value = all
            .filter(d => d.kind === 'videoinput')
            .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Cámara ${i + 1}` }));
    } catch {
        // enumerateDevices puede fallar en contextos raros; el selector simplemente
        // no aparece y se usa la cámara por defecto.
    }
};

/**
 * @param {string|null} deviceId Cámara específica; si es null usa la trasera por defecto.
 */
const start = async (deviceId = null) => {
    error.value = '';
    clearCaptured();
    stopStream();
    starting.value = true;
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('unsupported');
        }

        // Con deviceId apuntamos a la cámara elegida; sin él, pista 'environment'
        // (trasera en tablets/móvil). Pedimos alta resolución para legibilidad.
        const videoConstraints = deviceId
            ? { deviceId: { exact: deviceId }, width: { ideal: 1920 }, height: { ideal: 1080 } }
            : { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } };

        stream.value = await navigator.mediaDevices.getUserMedia({ video: videoConstraints, audio: false });

        // La cámara realmente activa (para reflejarla en el selector).
        const track = stream.value.getVideoTracks()[0];
        currentDeviceId.value = track?.getSettings?.().deviceId || deviceId || '';

        await refreshDevices();

        await nextTick();
        if (video.value) {
            video.value.srcObject = stream.value;
            await video.value.play().catch(() => {});
        }
    } catch (e) {
        error.value = mapError(e);
        stopStream();
    } finally {
        starting.value = false;
    }
};

const switchCamera = (deviceId) => {
    if (!deviceId || deviceId === currentDeviceId.value) {
        return;
    }
    start(deviceId);
};

// Alterna a la siguiente cámara disponible (frontal ↔ trasera, o rota si hay más).
const cycleCamera = () => {
    if (devices.value.length < 2) return;
    const idx = devices.value.findIndex(d => d.deviceId === currentDeviceId.value);
    const next = devices.value[(idx + 1) % devices.value.length];
    switchCamera(next.deviceId);
};

const shoot = () => {
    if (!video.value || !stream.value) return;
    const w = video.value.videoWidth;
    const h = video.value.videoHeight;
    if (!w || !h) return;

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(video.value, 0, 0, w, h);
    canvas.toBlob((blob) => {
        if (!blob) return;
        captured.value = { url: URL.createObjectURL(blob), blob };
        stopStream(); // congelamos la toma mientras el usuario decide
    }, 'image/jpeg', 0.92);
};

const usePhoto = () => {
    if (!captured.value) return;
    const file = new File([captured.value.blob], `foto-${Date.now()}.jpg`, { type: 'image/jpeg' });
    emit('capture', file);
    close();
};

const close = () => {
    stopStream();
    clearCaptured();
    error.value = '';
    emit('update:open', false);
};

watch(() => props.open, (v) => {
    if (v) {
        start();
    } else {
        stopStream();
        clearCaptured();
    }
});

onBeforeUnmount(() => {
    stopStream();
    clearCaptured();
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4" @click.self="close">
                <div class="flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-gray-900 shadow-2xl">
                    <!-- Header -->
                    <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <h3 class="shrink-0 text-sm font-bold text-white">Tomar foto</h3>
                        <button @click="close" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-white/10 hover:text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Viewport -->
                    <div class="relative flex aspect-video items-center justify-center bg-black">
                        <!-- Botón circular: alternar cámara (frontal ↔ trasera) -->
                        <button v-if="devices.length > 1 && !captured && !error" @click="cycleCamera" :disabled="starting"
                            class="absolute right-3 top-3 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-black/50 text-white shadow-lg backdrop-blur transition hover:bg-black/70 disabled:opacity-40"
                            title="Cambiar cámara">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        </button>

                        <!-- Error -->
                        <div v-if="error" class="px-8 text-center">
                            <svg class="mx-auto h-10 w-10 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                            <p class="mt-3 text-sm font-medium text-gray-200">{{ error }}</p>
                        </div>

                        <!-- Captura congelada -->
                        <img v-else-if="captured" :src="captured.url" alt="Foto capturada" class="h-full w-full object-contain" />

                        <!-- Stream en vivo -->
                        <video v-else ref="video" autoplay playsinline muted class="h-full w-full object-contain"></video>

                        <!-- Spinner mientras inicia -->
                        <div v-if="starting && !error" class="absolute inset-0 flex items-center justify-center">
                            <svg class="h-8 w-8 animate-spin text-white/80" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="flex items-center justify-center gap-3 px-5 py-4">
                        <template v-if="error">
                            <button @click="start()" class="rounded-xl bg-white/10 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20">Reintentar</button>
                            <button @click="close" class="rounded-xl px-5 py-2.5 text-sm font-medium text-gray-300 transition hover:text-white">Cerrar</button>
                        </template>
                        <template v-else-if="captured">
                            <button @click="start()" class="rounded-xl bg-white/10 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20">Repetir</button>
                            <button @click="usePhoto" class="rounded-xl bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">Usar foto</button>
                        </template>
                        <template v-else>
                            <button @click="shoot" :disabled="!stream || starting"
                                class="flex h-14 w-14 items-center justify-center rounded-full bg-white text-gray-900 shadow-lg ring-4 ring-white/30 transition hover:scale-105 disabled:cursor-not-allowed disabled:opacity-40"
                                title="Capturar">
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
