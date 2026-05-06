import { ref, computed } from 'vue';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

// Importante: NO usamos `noopener` aquí porque necesitamos la referencia para
// reescribir `popup.location.href` cuando vuelva el fetch. Con `noopener` la
// función devolvería null en Chrome/Firefox.
const openHolderPopup = () => {
    try { return window.open('about:blank', '_blank'); } catch (_) { return null; }
};

const navigateOrFallback = (popup, url) => {
    if (popup && !popup.closed) {
        try {
            popup.location.href = url;
            return true;
        } catch (_) { /* fall through al fallback */ }
    }
    return !!window.open(url, '_blank', 'noopener,noreferrer');
};

const closePopup = (popup) => {
    try { popup?.close(); } catch (_) { /* noop */ }
};

const fetchJson = (url, opts = {}) => fetch(url, {
    credentials: 'same-origin',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(opts.headers || {}),
    },
    ...opts,
});

/**
 * Maneja el ciclo completo del flujo "Enviar nota por WhatsApp" en Mesa de
 * Trabajo. Coordina tres diálogos (confirmación de envío, captura/edición de
 * teléfono, confirmación de borrado) y los endpoints asociados.
 *
 * Política UX clave: nunca se abre WhatsApp como efecto inmediato del click
 * en el botón principal. Siempre pasa primero por un diálogo (confirm o
 * capture) para evitar envíos accidentales y dejar visible a qué número se va.
 */
export function useWhatsappSend({ sale, linkUrl, savePhoneUrl, deletePhoneUrl, onMutate }) {
    const loading = ref(false);
    const savingPhone = ref(false);
    const removingPhone = ref(false);
    const error = ref(null);

    const confirmDialog = ref({ show: false });
    const captureDialog = ref({
        show: false,
        initialPhone: '',
        sendAfter: false,
        title: '',
        subtitle: '',
        actionLabel: '',
    });
    const removeDialog = ref({ show: false });

    const phoneInfo = computed(() => {
        const s = sale();
        if (!s) return { phone: null, source: null, customerName: null };
        if (s.customer?.phone) {
            return { phone: s.customer.phone, source: 'customer', customerName: s.customer.name };
        }
        if (s.contact_phone) {
            return { phone: s.contact_phone, source: 'manual', customerName: null };
        }
        return { phone: null, source: null, customerName: null };
    });

    const closeAll = () => {
        confirmDialog.value = { show: false };
        captureDialog.value = { ...captureDialog.value, show: false };
        removeDialog.value = { show: false };
        error.value = null;
    };

    const handleSendClick = () => {
        error.value = null;
        if (phoneInfo.value.phone) {
            confirmDialog.value = { show: true };
        } else {
            captureDialog.value = {
                show: true,
                initialPhone: '',
                sendAfter: true,
                title: 'Enviar nota por WhatsApp',
                subtitle: 'Captura el teléfono. Lo guardaremos en la venta para próximos envíos.',
                actionLabel: 'Guardar y enviar',
            };
        }
    };

    // Click "Editar" dentro del confirm dialog (solo aparece para fuente manual).
    const switchToEditFromConfirm = () => {
        confirmDialog.value = { show: false };
        captureDialog.value = {
            show: true,
            initialPhone: phoneInfo.value.phone || '',
            sendAfter: true,
            title: 'Editar y enviar',
            subtitle: 'Corrige el teléfono y se enviará la nota.',
            actionLabel: 'Guardar y enviar',
        };
    };

    // Click ✏️ del chip — solo edita, no envía.
    const handleChipEdit = () => {
        error.value = null;
        captureDialog.value = {
            show: true,
            initialPhone: phoneInfo.value.phone || '',
            sendAfter: false,
            title: 'Editar teléfono',
            subtitle: 'Actualiza el número asociado a esta venta.',
            actionLabel: 'Guardar',
        };
    };

    // Click ➕ del chip — agregar sin enviar.
    const handleChipAdd = () => {
        error.value = null;
        captureDialog.value = {
            show: true,
            initialPhone: '',
            sendAfter: false,
            title: 'Agregar teléfono',
            subtitle: 'Asocia un teléfono a esta venta para futuros envíos.',
            actionLabel: 'Guardar',
        };
    };

    // Click 🗑️ del chip.
    const handleChipRemove = () => {
        error.value = null;
        removeDialog.value = { show: true };
    };

    // Confirmar envío con el teléfono actual (sin tocar nada).
    const confirmSend = async () => {
        loading.value = true;
        error.value = null;
        confirmDialog.value = { show: false };
        const popup = openHolderPopup();
        try {
            const res = await fetchJson(linkUrl());
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            if (data.available && data.url) {
                if (!navigateOrFallback(popup, data.url)) {
                    error.value = 'El navegador bloqueó la ventana de WhatsApp. Permite popups e intenta de nuevo.';
                }
                return;
            }

            closePopup(popup);
            if (data.reason === 'needs_phone') {
                // El estado en frontend dijo que había teléfono pero el backend
                // dice que no. Normalmente no pasa: caemos al modo captura.
                captureDialog.value = {
                    show: true,
                    initialPhone: '',
                    sendAfter: true,
                    title: 'Capturar teléfono',
                    subtitle: 'No se encontró un teléfono guardado. Captúralo para enviar.',
                    actionLabel: 'Guardar y enviar',
                };
                return;
            }
            error.value = data.reason === 'invalid_phone'
                ? 'El teléfono guardado no es válido.'
                : 'No se pudo generar el link.';
        } catch (e) {
            closePopup(popup);
            error.value = e.message || 'Error al generar el link';
        } finally {
            loading.value = false;
        }
    };

    // Submit del capture dialog. Si sendAfter=true, abre WhatsApp después de guardar.
    const submitPhone = async (phone) => {
        savingPhone.value = true;
        error.value = null;
        const wantsSend = captureDialog.value.sendAfter;
        const popup = wantsSend ? openHolderPopup() : null;
        try {
            const res = await fetchJson(savePhoneUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ phone }),
            });

            if (res.status === 422) {
                closePopup(popup);
                const data = await res.json().catch(() => ({}));
                error.value = data.errors?.phone?.[0] || data.message || 'Teléfono inválido.';
                return { ok: false };
            }
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            captureDialog.value = { ...captureDialog.value, show: false };
            onMutate?.();

            if (wantsSend) {
                if (!data.url) {
                    closePopup(popup);
                    error.value = 'No se pudo generar el link.';
                    return { ok: false };
                }
                if (!navigateOrFallback(popup, data.url)) {
                    error.value = 'El navegador bloqueó la ventana de WhatsApp. Permite popups e intenta de nuevo.';
                    return { ok: false };
                }
            } else {
                closePopup(popup);
            }
            return { ok: true };
        } catch (e) {
            closePopup(popup);
            error.value = e.message || 'Error al guardar el teléfono';
            return { ok: false };
        } finally {
            savingPhone.value = false;
        }
    };

    const confirmRemove = async () => {
        removingPhone.value = true;
        error.value = null;
        try {
            const res = await fetchJson(deletePhoneUrl(), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            removeDialog.value = { show: false };
            onMutate?.();
        } catch (e) {
            error.value = e.message || 'Error al quitar el teléfono';
        } finally {
            removingPhone.value = false;
        }
    };

    return {
        // estado
        loading, savingPhone, removingPhone, error, phoneInfo,
        // dialogs
        confirmDialog, captureDialog, removeDialog,
        // acciones del botón principal
        handleSendClick,
        confirmSend,
        switchToEditFromConfirm,
        // acciones del chip
        handleChipEdit, handleChipAdd, handleChipRemove,
        // acciones de los diálogos
        submitPhone, confirmRemove,
        closeAll,
    };
}
