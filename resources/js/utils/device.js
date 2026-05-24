/**
 * Heurística de dispositivo móvil. Decide el flujo de cámara:
 * - Móvil: el <input capture> nativo abre la cámara del sistema (mejor enfoque).
 * - Desktop: el navegador IGNORA `capture` y solo abre el selector de archivos,
 *   así que usamos getUserMedia (webcam) en su lugar.
 *
 * @returns {boolean}
 */
export function isMobileDevice() {
    if (typeof navigator === 'undefined') {
        return false;
    }

    // userAgentData.mobile es la señal más fiable donde existe (Chromium).
    if (navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean') {
        return navigator.userAgentData.mobile;
    }

    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i
        .test(navigator.userAgent || '');
}
