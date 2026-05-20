<script setup>
import { useCamera } from '@/composables/useCamera';
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'capture']);

const camera = useCamera();
const videoEl = ref(null);
const preview = ref(null); // { blob, url } | null
const busy = ref(false);

const clearPreview = () => {
    if (preview.value?.url) {
        URL.revokeObjectURL(preview.value.url);
    }
    preview.value = null;
};

const startCamera = async () => {
    clearPreview();
    await nextTick(); // asegura que el <video> esté montado
    await camera.start(videoEl.value);
};

watch(() => props.open, (open) => {
    if (open) {
        startCamera();
    } else {
        camera.stop();
        clearPreview();
    }
});

const shoot = async () => {
    if (busy.value || !camera.isActive.value) {
        return;
    }
    busy.value = true;
    const shot = await camera.capturePhoto();
    busy.value = false;
    if (shot) {
        preview.value = shot; // el stream sigue vivo detrás (v-show), repetir es instantáneo
    }
};

const retake = () => {
    clearPreview();
};

const usePhoto = () => {
    if (!preview.value) {
        return;
    }
    const file = new File([preview.value.blob], `camara-${Date.now()}.jpg`, { type: 'image/jpeg' });
    emit('capture', file);
    close();
};

const close = () => {
    camera.stop();
    clearPreview();
    emit('close');
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-[60] flex flex-col bg-black">
                <!-- Barra superior -->
                <div class="flex items-center justify-between px-4 py-3 text-white">
                    <span class="text-sm font-semibold">
                        {{ preview ? 'Revisa la foto' : 'Toma la foto de la factura' }}
                    </span>
                    <button @click="close" class="rounded-full bg-white/10 px-3 py-1 text-sm hover:bg-white/20">✕</button>
                </div>

                <!-- Visor -->
                <div class="relative flex flex-1 items-center justify-center overflow-hidden">
                    <video v-show="!preview && !camera.error.value" ref="videoEl" autoplay playsinline muted
                        class="h-full w-full object-contain"></video>
                    <img v-if="preview" :src="preview.url" alt="Foto capturada" class="h-full w-full object-contain" />
                    <div v-if="camera.error.value" class="px-6 text-center text-white">
                        <svg class="mx-auto mb-3 h-10 w-10 text-white/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM3 3l18 18" />
                        </svg>
                        <p class="text-sm">{{ camera.error.value }}</p>
                    </div>
                </div>

                <!-- Controles -->
                <div class="flex items-center justify-center gap-6 px-4 pb-8 pt-4">
                    <template v-if="camera.error.value">
                        <button @click="close" class="rounded-xl bg-white/10 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/20">
                            Cerrar
                        </button>
                    </template>
                    <template v-else-if="preview">
                        <button @click="retake"
                            class="rounded-xl border border-white/40 px-5 py-2.5 text-sm font-semibold text-white hover:bg-white/10">
                            Repetir
                        </button>
                        <button @click="usePhoto"
                            class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-violet-700 hover:to-fuchsia-700">
                            Usar foto
                        </button>
                    </template>
                    <template v-else>
                        <button @click="shoot" :disabled="!camera.isActive.value || busy"
                            aria-label="Tomar foto"
                            class="flex h-16 w-16 items-center justify-center rounded-full border-4 border-white bg-white/20 transition active:scale-95 disabled:opacity-40">
                            <span class="h-12 w-12 rounded-full bg-white"></span>
                        </button>
                    </template>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
