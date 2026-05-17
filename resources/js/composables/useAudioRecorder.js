import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Composable mínimo sobre MediaRecorder para grabar notas de voz dentro del
 * navegador, sin librerías externas.
 *
 * Uso típico:
 *   const { isSupported, isRecording, duration, audioBlob, audioUrl,
 *           startRecording, stopRecording, reset, error } = useAudioRecorder({ maxSeconds: 90 });
 *
 * El blob queda en audioBlob.value cuando stop() resuelve. audioUrl es un
 * objectURL para reproducir, se revoca automáticamente en reset() y unmount.
 */
export function useAudioRecorder({ maxSeconds = 90 } = {}) {
    const isSupported = typeof window !== 'undefined'
        && !!window.MediaRecorder
        && !!navigator?.mediaDevices?.getUserMedia;

    const isRecording = ref(false);
    const duration = ref(0);
    const audioBlob = ref(null);
    const audioUrl = ref(null);
    const error = ref('');

    let mediaRecorder = null;
    let mediaStream = null;
    let chunks = [];
    let tickInterval = null;
    let autoStopTimeout = null;

    const hasRecording = computed(() => audioBlob.value !== null);

    const cleanupStream = () => {
        if (tickInterval) { clearInterval(tickInterval); tickInterval = null; }
        if (autoStopTimeout) { clearTimeout(autoStopTimeout); autoStopTimeout = null; }
        if (mediaStream) {
            mediaStream.getTracks().forEach(t => t.stop());
            mediaStream = null;
        }
        mediaRecorder = null;
        chunks = [];
    };

    const reset = () => {
        if (isRecording.value) {
            try { mediaRecorder?.stop(); } catch { /* ignore */ }
        }
        cleanupStream();
        if (audioUrl.value) {
            URL.revokeObjectURL(audioUrl.value);
            audioUrl.value = null;
        }
        audioBlob.value = null;
        duration.value = 0;
        error.value = '';
        isRecording.value = false;
    };

    // Preferred mime type: webm/opus en Chrome/Firefox; mp4 en Safari iOS.
    // Si ninguno está soportado, el navegador usa su default y obtenemos
    // el mime real del blob al finalizar.
    const pickMimeType = () => {
        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
        for (const m of candidates) {
            if (window.MediaRecorder.isTypeSupported(m)) return m;
        }
        return '';
    };

    const startRecording = async () => {
        if (!isSupported) {
            error.value = 'Tu navegador no soporta grabar audio.';
            return;
        }
        if (isRecording.value) return;

        reset();

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            error.value = e?.name === 'NotAllowedError'
                ? 'Permiso de micrófono denegado.'
                : 'No se pudo acceder al micrófono.';
            return;
        }

        const mimeType = pickMimeType();
        try {
            mediaRecorder = mimeType
                ? new MediaRecorder(mediaStream, { mimeType })
                : new MediaRecorder(mediaStream);
        } catch (e) {
            error.value = 'No se pudo iniciar la grabación.';
            cleanupStream();
            return;
        }

        chunks = [];
        mediaRecorder.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size > 0) chunks.push(e.data);
        });
        mediaRecorder.addEventListener('stop', () => {
            const blobType = mediaRecorder?.mimeType || mimeType || 'audio/webm';
            const blob = new Blob(chunks, { type: blobType });
            audioBlob.value = blob;
            audioUrl.value = URL.createObjectURL(blob);
            isRecording.value = false;
            cleanupStream();
        });

        mediaRecorder.start();
        isRecording.value = true;
        duration.value = 0;
        const startedAt = Date.now();
        tickInterval = setInterval(() => {
            duration.value = Math.floor((Date.now() - startedAt) / 1000);
        }, 250);
        autoStopTimeout = setTimeout(() => {
            // Forzamos parada al alcanzar el límite.
            if (isRecording.value) stopRecording();
        }, maxSeconds * 1000);
    };

    const stopRecording = () => {
        if (!isRecording.value || !mediaRecorder) return;
        try { mediaRecorder.stop(); } catch { /* ignore */ }
    };

    onBeforeUnmount(() => {
        if (audioUrl.value) URL.revokeObjectURL(audioUrl.value);
        cleanupStream();
    });

    return {
        isSupported,
        isRecording,
        duration,
        audioBlob,
        audioUrl,
        hasRecording,
        startRecording,
        stopRecording,
        reset,
        error,
        maxSeconds,
    };
}
