/**
 * Sonidos de la isla (copiado del hub). Un solo AudioContext reutilizado: una
 * pestaña de caja puede quedarse abierta todo el día y el navegador acaba
 * negándose a crear más contextos, así que el sonido dejaría de oírse justo el
 * día de más trabajo.
 *
 * `action` son dos tonos ascendentes (algo espera una decisión); `important`,
 * uno suave. `info` no suena.
 */
const TONES = {
    action: { gain: 0.3, notes: [[660, 0], [880, 0.16]] },
    important: { gain: 0.15, notes: [[880, 0]] },
};

let ctx = null;

export function playChime(level) {
    const tone = TONES[level];
    if (!tone) return;

    try {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return;
        if (!ctx) ctx = new Ctor();
        if (ctx.state === 'suspended') ctx.resume();

        for (const [freq, delay] of tone.notes) {
            const t0 = ctx.currentTime + delay;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(tone.gain, t0);
            gain.gain.exponentialRampToValueAtTime(0.001, t0 + 0.35);
            osc.start(t0);
            osc.stop(t0 + 0.35);
            osc.onended = () => {
                osc.disconnect();
                gain.disconnect();
            };
        }
    } catch {
        // Audio no disponible
    }
}
