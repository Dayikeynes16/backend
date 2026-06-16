import { ref } from 'vue';
import axios from 'axios';

/**
 * Compone el flujo "Crear categoría con IA":
 *
 * - `submitDraft({ tenantSlug, text, audio })` llama al endpoint de borrador
 *   (multipart), devuelve `{ draftId, proposal, audioTranscription }`.
 *
 * - `applyDraft({ tenantSlug, payload })` consume el draft con el bulk
 *   transaccional. Devuelve la categoría creada/actualizada.
 *
 * Endpoint backend devuelve JSON puro (no Inertia response) por eso usamos
 * axios directo.
 */
export function useCategoryAiDraft() {
    const submitting = ref(false);
    const applying = ref(false);
    const error = ref('');

    const submitDraft = async ({ tenantSlug, text, audio, routePrefix = 'empresa' }) => {
        submitting.value = true;
        error.value = '';

        const url = route(`${routePrefix}.gastos.categorias.ia.store`, tenantSlug);
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
                draftId: data.draft_id,
                proposal: data.proposal || {},
                audioTranscription: data.audio_transcription || null,
            };
        } catch (e) {
            error.value = e?.response?.data?.message
                || e?.message
                || 'No se pudo analizar tu solicitud.';
            throw e;
        } finally {
            submitting.value = false;
        }
    };

    const applyDraft = async ({ tenantSlug, payload, routePrefix = 'empresa' }) => {
        applying.value = true;
        error.value = '';

        const url = route(`${routePrefix}.gastos.categorias.ia.apply`, tenantSlug);

        try {
            const { data } = await axios.post(url, payload, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                timeout: 30_000,
            });
            return data;
        } catch (e) {
            // 422 trae { message, errors? } — propagamos para que la UI los muestre.
            error.value = e?.response?.data?.message
                || e?.message
                || 'No se pudo guardar la categoría.';
            throw e;
        } finally {
            applying.value = false;
        }
    };

    return { submitDraft, applyDraft, submitting, applying, error };
}
