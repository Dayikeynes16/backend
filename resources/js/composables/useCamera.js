import { onBeforeUnmount, ref } from 'vue';

/**
 * Composable mínimo sobre getUserMedia para mostrar la cámara en vivo dentro
 * del navegador y capturar una foto a JPEG, sin librerías externas.
 *
 * Uso típico:
 *   const camera = useCamera();
 *   await camera.start(videoEl);          // enchufa el stream al <video>
 *   const shot = await camera.capturePhoto(); // { blob, url } o null
 *   camera.stop();
 *
 * Requiere contexto seguro (HTTPS o localhost). El stream se libera en stop(),
 * reset() y onBeforeUnmount para apagar el indicador de cámara del dispositivo.
 */
export function useCamera({ facingMode = 'environment', maxDimension = 1920, quality = 0.85 } = {}) {
    const isSupported = typeof window !== 'undefined'
        && !!navigator?.mediaDevices?.getUserMedia;

    const isActive = ref(false);
    const error = ref('');

    let stream = null;
    let videoEl = null;

    const stop = () => {
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
            stream = null;
        }
        if (videoEl) {
            videoEl.srcObject = null;
        }
        isActive.value = false;
    };

    /**
     * Pide la cámara y enchufa el stream al elemento <video> dado.
     *
     * @return {Promise<boolean>} true si la cámara quedó activa.
     */
    const start = async (el) => {
        if (!isSupported) {
            error.value = 'Tu navegador no soporta usar la cámara.';
            return false;
        }
        stop();
        error.value = '';
        videoEl = el;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: facingMode } },
                audio: false,
            });
        } catch (e) {
            if (e?.name === 'NotAllowedError') {
                error.value = 'Permiso de cámara denegado.';
            } else if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError') {
                error.value = 'No se encontró ninguna cámara.';
            } else {
                error.value = 'No se pudo acceder a la cámara.';
            }
            return false;
        }

        if (videoEl) {
            videoEl.srcObject = stream;
            try {
                await videoEl.play();
            } catch {
                /* autoplay puede fallar sin gesto; el atributo autoplay lo reintenta */
            }
        }
        isActive.value = true;
        return true;
    };

    /**
     * Dibuja el frame actual del <video> en un canvas y lo exporta a JPEG,
     * limitando el lado mayor a maxDimension para mantener el peso bajo.
     *
     * @return {Promise<{ blob: Blob, url: string } | null>}
     */
    const capturePhoto = () => {
        const vw = videoEl?.videoWidth ?? 0;
        const vh = videoEl?.videoHeight ?? 0;
        if (!stream || !vw || !vh) {
            return Promise.resolve(null);
        }

        const scale = Math.min(1, maxDimension / Math.max(vw, vh));
        const w = Math.round(vw * scale);
        const h = Math.round(vh * scale);

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(videoEl, 0, 0, w, h);

        return new Promise((resolve) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    resolve(null);
                    return;
                }
                resolve({ blob, url: URL.createObjectURL(blob) });
            }, 'image/jpeg', quality);
        });
    };

    const reset = () => {
        stop();
        error.value = '';
    };

    onBeforeUnmount(() => stop());

    return {
        isSupported,
        isActive,
        error,
        start,
        stop,
        capturePhoto,
        reset,
    };
}
