import { ref } from 'vue';
import axios from 'axios';

/**
 * Compone el flujo "Registrar gasto con IA":
 * - submitDraft({ text, files, routeName, tenantSlug }) → llama al backend con
 *   multipart, devuelve { draftId, proposal, attachments } cuando la IA responde
 *   con éxito.
 * - applyProposalToForm(form, proposal) → mapea los campos de la propuesta al
 *   shape del useForm de GastoFormModal.
 *
 * NOTA: NO usamos Inertia router porque este endpoint devuelve JSON (no Inertia
 * response). Usamos axios directo. Axios ya está disponible (Inertia v2 lo trae).
 */
export function useExpenseAiDraft() {
    const loading = ref(false);
    const error = ref('');

    /**
     * @param {Object} opts
     * @param {string} opts.routeName Ziggy route name (empresa.gastos.ia.store | sucursal.gastos.ia.store)
     * @param {string} opts.tenantSlug
     * @param {string} [opts.text]
     * @param {File[]} [opts.files]
     * @param {Blob|null} [opts.audio] Blob de MediaRecorder (Fase 2).
     * @returns {Promise<{ draftId: number, proposal: Object, attachments: any[], audioTranscription: string|null }>}
     */
    const submitDraft = async ({ routeName, tenantSlug, text, files, audio }) => {
        loading.value = true;
        error.value = '';

        const url = route(routeName, tenantSlug);
        const fd = new FormData();
        if (text) fd.append('input_text', text);
        (files || []).forEach((f) => fd.append('attachments[]', f));
        if (audio) {
            // El backend infiere extensión por mimetype, pero damos un nombre
            // amigable para que el archivo en disco sea identificable.
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
                || 'No se pudo analizar el gasto.';
            error.value = msg;
            throw e;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Aplica una propuesta IA a un form (Inertia useForm) del GastoFormModal.
     * Devuelve la lista de claves que vinieron prellenadas para que la UI las marque.
     */
    const applyProposalToForm = (form, proposal, categories) => {
        const filledFields = new Set();

        if (proposal.concepto) { form.concept = proposal.concepto; filledFields.add('concept'); }
        if (proposal.monto != null) { form.amount = proposal.monto; filledFields.add('amount'); }
        if (proposal.fecha) { form.expense_date = proposal.fecha; filledFields.add('expense_date'); }
        if (proposal.descripcion) { form.description = proposal.descripcion; filledFields.add('description'); }
        if (proposal.metodo_pago) { form.payment_method = proposal.metodo_pago; filledFields.add('payment_method'); }
        if (proposal.branch_id) { form.branch_id = proposal.branch_id; filledFields.add('branch_id'); }

        // Para resolver expense_subcategory_id → necesitamos también la
        // expense_category_id, que se infiere buscando en el catálogo.
        if (proposal.expense_subcategory_id) {
            for (const c of categories || []) {
                const match = (c.subcategories || []).find(s => s.id === proposal.expense_subcategory_id);
                if (match) {
                    form.expense_category_id = c.id;
                    form.expense_subcategory_id = match.id;
                    filledFields.add('expense_category_id');
                    filledFields.add('expense_subcategory_id');
                    break;
                }
            }
        }

        return Array.from(filledFields);
    };

    return { submitDraft, applyProposalToForm, loading, error };
}
