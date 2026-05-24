import { ref } from 'vue';
import axios from 'axios';

/**
 * Espejo de usePurchaseAiDraft pero para la Agenda. El endpoint es STATELESS:
 * no guarda nada, sólo devuelve una propuesta (texto + audio, sin imágenes).
 * El form usa axios directo (no Inertia) porque la respuesta es JSON.
 */
export function useAgendaAiDraft() {
    const loading = ref(false);
    const error = ref('');

    const submitDraft = async ({ tenantSlug, text, audio }) => {
        loading.value = true;
        error.value = '';

        const url = route('agenda.ia.store', tenantSlug);
        const fd = new FormData();
        if (text) fd.append('input_text', text);
        if (audio) {
            const ext = (audio.type.includes('webm') && 'webm')
                || (audio.type.includes('ogg') && 'ogg')
                || (audio.type.includes('mp4') && 'm4a')
                || (audio.type.includes('mpeg') && 'mp3')
                || 'webm';
            fd.append('audio', audio, `nota-de-voz.${ext}`);
        }

        try {
            const { data } = await axios.post(url, fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
                timeout: 120_000,
            });
            return {
                proposal: data.proposal || {},
                transcription: data.transcription || null,
            };
        } catch (e) {
            const msg = e?.response?.data?.message
                || e?.message
                || 'No se pudo armar el recordatorio.';
            error.value = msg;
            throw e;
        } finally {
            loading.value = false;
        }
    };

    return { submitDraft, loading, error };
}
