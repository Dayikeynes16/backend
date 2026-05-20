import { ref } from 'vue';
import axios from 'axios';

/**
 * Espejo de useExpenseAiDraft pero para Compras. El endpoint devuelve JSON
 * (no Inertia response). El form usa axios directo.
 */
export function usePurchaseAiDraft() {
    const loading = ref(false);
    const error = ref('');

    const submitDraft = async ({ routeName, tenantSlug, text, files, audio }) => {
        loading.value = true;
        error.value = '';

        const url = route(routeName, tenantSlug);
        const fd = new FormData();
        if (text) fd.append('input_text', text);
        (files || []).forEach((f) => fd.append('attachments[]', f));
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
                attachments: data.attachments || [],
                audioTranscription: data.audio_transcription || null,
            };
        } catch (e) {
            const msg = e?.response?.data?.message
                || e?.message
                || 'No se pudo analizar la compra.';
            error.value = msg;
            throw e;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Aplica una propuesta IA al form de CompraFormModal. Devuelve el set de
     * keys que vinieron prerellenadas para que la UI las marque con ✨.
     */
    const applyProposalToForm = (form, proposal) => {
        const filled = new Set();

        if (proposal.proveedor?.id) {
            form.provider_id = proposal.proveedor.id;
            filled.add('provider_id');
        }
        if (proposal.invoice_number) {
            form.invoice_number = proposal.invoice_number;
            filled.add('invoice_number');
        }
        if (proposal.purchased_at) {
            form.purchased_at = proposal.purchased_at;
            filled.add('purchased_at');
        }
        if (proposal.notas) {
            form.notes = proposal.notas;
            filled.add('notes');
        }
        if (Array.isArray(proposal.lineas) && proposal.lineas.length > 0) {
            form.items = proposal.lineas.map((l) => ({
                product_id: l.product_id ?? null,
                concept: l.concepto ?? '',
                quantity: Number(l.quantity ?? 0),
                unit: l.unit ?? 'kg',
                unit_price: Number(l.unit_price ?? 0),
                notes: l.notas ?? '',
            }));
            filled.add('items');
        }

        return Array.from(filled);
    };

    return { submitDraft, applyProposalToForm, loading, error };
}
