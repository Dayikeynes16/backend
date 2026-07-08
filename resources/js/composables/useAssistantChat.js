import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useAudioRecorder } from '@/composables/useAudioRecorder';

/**
 * Estado y lógica del chat del asistente, extraídos de AsistenteChat.vue para
 * que la página clásica y la mini-app compartan exactamente el mismo
 * comportamiento. Lo que toca el DOM (auto-grow del textarea, stick-to-bottom
 * del hilo) vive en los componentes; aquí solo estado, red y derivaciones.
 *
 * @param props  props reactivas de la página: sessions, activeSessionId, messages, budget
 * @param routes mapa de nombres de ruta Ziggy del contexto (index, createSession,
 *               sendMessage, transcribe, draftConfirm, draftCancel, speak?)
 */
export function useAssistantChat(props, routes) {
    const page = usePage();
    const slug = computed(() => page.props.auth.tenant_slug);

    const messages = ref([...props.messages]);
    const inputText = ref('');
    const sending = ref(false);
    const errorBanner = ref(null);

    // Señal de "scroll al fondo" para MessageThread (enviar, cambiar sesión).
    const stickRequest = ref(0);

    // Adjunto de recibo (imagen) para preparar un gasto desde el chat.
    const pendingImage = ref(null);

    const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    const IMAGE_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    async function selectImage(file) {
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            errorBanner.value = 'Solo se permiten imágenes (jpg, png, webp).';
            return;
        }

        // Fotos de cámara de teléfono: suelen exceder 5 MB o venir en formatos
        // que el backend no acepta (HEIC). Se re-escalan a JPEG en el cliente.
        if (file.size > IMAGE_MAX_BYTES || ! IMAGE_ALLOWED_TYPES.includes(file.type)) {
            try {
                file = await downscaleImage(file);
            } catch {
                // Si no se pudo convertir, seguimos con el archivo original y
                // dejamos que las validaciones de abajo decidan.
            }
        }

        if (file.size > IMAGE_MAX_BYTES) {
            errorBanner.value = 'La imagen no puede superar 5 MB.';
        } else if (! IMAGE_ALLOWED_TYPES.includes(file.type)) {
            errorBanner.value = 'Formato de imagen no soportado (usa jpg, png o webp).';
        } else {
            errorBanner.value = null;
            pendingImage.value = file;
        }
    }

    function downscaleImage(file, maxDim = 2000, quality = 0.85) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(img.width * scale));
                canvas.height = Math.max(1, Math.round(img.height * scale));
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                canvas.toBlob((blob) => {
                    if (!blob) return reject(new Error('compress failed'));
                    const name = (file.name || 'foto').replace(/\.\w+$/, '') + '.jpg';
                    resolve(new File([blob], name, { type: 'image/jpeg' }));
                }, 'image/jpeg', quality);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('load failed'));
            };
            img.src = url;
        });
    }

    function clearImage() {
        pendingImage.value = null;
    }

    // Dictado por voz: graba en el browser, sube a /transcribir, recibe texto.
    // El texto cae al cuadro de entrada para que el usuario lo revise antes de enviar.
    const { isSupported: micSupported, isRecording, duration: recordDuration,
        audioBlob, startRecording, stopRecording, reset: resetRecording,
        error: recorderError } = useAudioRecorder({ maxSeconds: 90 });
    const transcribing = ref(false);

    watch(audioBlob, async (blob) => {
        if (!blob) return;
        if (!routes.transcribe) {
            errorBanner.value = 'El dictado no está habilitado en este rol.';
            resetRecording();
            return;
        }
        transcribing.value = true;
        errorBanner.value = null;
        try {
            const form = new FormData();
            form.append('audio', blob, blob.type?.includes('webm') ? 'audio.webm' : 'audio.dat');
            const url = route(routes.transcribe, slug.value);
            const { data } = await axios.post(url, form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            const text = (data?.text ?? '').trim();
            if (!text) {
                errorBanner.value = 'No escuché nada. Intenta de nuevo.';
            } else {
                // Dictado = envío directo: el usuario habla y el asistente actúa,
                // sin paso intermedio de revisión (concatenando lo que ya hubiera
                // escrito). Es seguro: toda escritura sigue pasando por borrador
                // + confirmación explícita en la tarjeta.
                inputText.value = inputText.value
                    ? inputText.value.trimEnd() + ' ' + text
                    : text;
                send();
            }
        } catch (err) {
            errorBanner.value = err.response?.data?.message || 'No pude transcribir el audio.';
        } finally {
            transcribing.value = false;
            resetRecording();
        }
    });

    const toggleRecording = () => {
        if (transcribing.value) return;
        if (isRecording.value) {
            stopRecording();
        } else {
            errorBanner.value = null;
            startRecording();
        }
    };

    const recordTimeLabel = computed(() => {
        const s = recordDuration.value;
        const mm = String(Math.floor(s / 60)).padStart(2, '0');
        const ss = String(s % 60).padStart(2, '0');
        return `${mm}:${ss}`;
    });

    // Surface recorder permission/init errors al banner del chat.
    watch(recorderError, (msg) => {
        if (msg) errorBanner.value = msg;
    });

    // ─── TTS: reproducir respuestas del asistente con ElevenLabs ──────────────
    // Toggle global persistido en localStorage. Sólo activo si routes.speak existe.
    const VOICE_PREF_KEY = 'assistant-voice-autoplay';
    const voiceAutoplay = ref((localStorage.getItem(VOICE_PREF_KEY) ?? '1') === '1');
    watch(voiceAutoplay, (v) => localStorage.setItem(VOICE_PREF_KEY, v ? '1' : '0'));

    const playingMessageId = ref(null);
    const loadingVoiceFor = ref(null);
    let currentAudio = null;
    // Mensaje más reciente al montar; cualquier id mayor que esto y de role=assistant
    // con content NO vacío se considera "nuevo" (candidato a auto-play).
    let lastSeenAssistantId = Math.max(
        0,
        ...props.messages.filter((m) => m.role === 'assistant').map((m) => m.id),
    );

    const stopAudio = () => {
        if (currentAudio) {
            try { currentAudio.pause(); } catch { /* ignore */ }
            currentAudio.src = '';
            currentAudio = null;
        }
        playingMessageId.value = null;
    };

    const playMessage = async (message) => {
        if (!message || message.role !== 'assistant' || !message.content) return;
        if (!routes.speak) return;

        // Si ya está sonando este mismo, alterna pause/stop.
        if (playingMessageId.value === message.id) {
            stopAudio();
            return;
        }
        stopAudio();

        loadingVoiceFor.value = message.id;
        try {
            const url = route(routes.speak, {
                tenant: slug.value,
                session: props.activeSessionId,
                message: message.id,
            });
            const res = await axios.post(url, {}, { responseType: 'blob' });
            const blob = res.data;
            const objectUrl = URL.createObjectURL(blob);
            currentAudio = new Audio(objectUrl);
            currentAudio.addEventListener('ended', () => {
                URL.revokeObjectURL(objectUrl);
                if (playingMessageId.value === message.id) playingMessageId.value = null;
                currentAudio = null;
            });
            currentAudio.addEventListener('error', () => {
                URL.revokeObjectURL(objectUrl);
                playingMessageId.value = null;
                currentAudio = null;
                errorBanner.value = 'No pude reproducir el audio.';
            });
            playingMessageId.value = message.id;
            await currentAudio.play().catch((e) => {
                // Browsers bloquean autoplay si no hubo interacción del usuario.
                // En auto-play silenciamos el error; en click manual sí lo mostramos.
                if (e?.name !== 'NotAllowedError') {
                    errorBanner.value = 'No pude reproducir el audio.';
                }
                playingMessageId.value = null;
            });
        } catch (err) {
            errorBanner.value = err.response?.data?.message
                ?? (err.response?.status === 429 ? 'Has excedido el límite de reproducciones por hora.' : 'No pude generar la voz.');
        } finally {
            loadingVoiceFor.value = null;
        }
    };

    // Detecta nuevos assistant messages y los reproduce si autoplay está on.
    watch(messages, (arr) => {
        const newest = arr
            .filter((m) => m.role === 'assistant' && m.content && m.id > lastSeenAssistantId)
            .sort((a, b) => b.id - a.id)[0];
        if (!newest) return;
        lastSeenAssistantId = newest.id;
        if (voiceAutoplay.value && routes.speak) {
            playMessage(newest);
        }
    }, { deep: true });

    // Cambiar de sesión / desmontar: corta el audio activo.
    onBeforeUnmount(() => stopAudio());

    // Cuando Inertia navega a otra sesión (crear nueva, switch desde el panel),
    // las props cambian pero el componente NO se desmonta. Hay que resincronizar
    // el estado local desde las props nuevas o el thread queda con mensajes viejos.
    watch(() => props.activeSessionId, () => {
        stopAudio();
        messages.value = [...props.messages];
        errorBanner.value = null;
        sending.value = false;
        lastSeenAssistantId = Math.max(
            0,
            ...props.messages.filter((m) => m.role === 'assistant').map((m) => m.id),
        );
        stickRequest.value++;
    });

    function guessKindFromToolName(name) {
        return ({
            consultar_ventas: 'sales_summary',
            consultar_gastos: 'expense_summary',
            consultar_productos_top: 'top_products',
            consultar_turnos: 'shift_status',
            consultar_clientes: 'customer_debt',
            consultar_productos: 'product_details',
            consultar_compras: 'purchase_summary',
            consultar_cuentas_por_pagar: 'accounts_payable',
            consultar_categorias_gasto: 'expense_categories',
            preparar_borrador_gasto: 'assistant_draft',
            preparar_borrador_proveedor: 'assistant_draft',
            preparar_borrador_compra: 'assistant_draft',
            preparar_borrador_abono: 'assistant_draft',
            preparar_cobro_cliente: 'assistant_draft',
            preparar_pago_proveedor_cuenta: 'assistant_draft',
            preparar_retiro_caja: 'assistant_draft',
            preparar_cambio_precio: 'assistant_draft',
            preparar_borrador_categoria_gasto: 'assistant_draft',
            editar_categoria_gasto: 'assistant_draft',
        })[name] || 'unknown';
    }

    // Agrupa los mensajes en una secuencia de "items" renderizables. Cada tool
    // con resultado se acumula y queda anclado al SIGUIENTE mensaje del asistente
    // con contenido — así una card sólo aparece una vez, junto a la respuesta que
    // la usó, y nunca se repinta en turnos posteriores.
    const renderItems = computed(() => {
        // Los ids negativos son mensajes optimistas en vuelo: van AL FINAL del
        // hilo (con a.id - b.id crudo se iban al principio).
        const sortKey = (m) => (m.id < 0 ? Number.MAX_SAFE_INTEGER : m.id);
        const sorted = [...messages.value].sort((a, b) => sortKey(a) - sortKey(b));
        const items = [];
        let pendingCards = [];

        for (const m of sorted) {
            if (m.role === 'user') {
                items.push({ kind: 'user', id: m.id, message: m });
                continue;
            }

            if (m.role === 'tool') {
                if (m.tool_status === 'success' && m.tool_result) {
                    pendingCards.push({
                        id: m.id,
                        kind: m.tool_result.kind || guessKindFromToolName(m.tool_name),
                        data: m.tool_result,
                        tool_name: m.tool_name,
                    });
                } else {
                    items.push({ kind: 'tool_error', id: m.id, message: m });
                }
                continue;
            }

            if (m.role === 'assistant') {
                // Saltar assistant intermedio sin contenido (sólo pidió herramientas).
                // Sus cards se atan al SIGUIENTE assistant con contenido.
                if (!m.content) continue;

                items.push({
                    kind: 'assistant',
                    id: m.id,
                    message: m,
                    cards: pendingCards,
                });
                pendingCards = [];
            }
        }

        // Cards huérfanas (sólo pasa si el assistant final falló): mostrarlas
        // igual para que la consulta no se pierda visualmente.
        if (pendingCards.length > 0) {
            items.push({ kind: 'orphan_cards', id: pendingCards[0].id, cards: pendingCards });
        }

        return items;
    });

    const budgetText = computed(() => {
        const remaining = (props.budget?.remaining_cents ?? 0) / 100;
        const cap = (props.budget?.cap_cents ?? 0) / 100;
        return `Presupuesto IA del mes: $${remaining.toFixed(2)} / $${cap.toFixed(2)} USD`;
    });

    async function send() {
        const text = inputText.value.trim();
        const image = pendingImage.value;
        if ((!text && !image) || sending.value) return;
        if (!props.activeSessionId) {
            errorBanner.value = 'Crea una sesión primero.';
            return;
        }

        sending.value = true;
        errorBanner.value = null;
        // El usuario acaba de interactuar: queremos que vea el nuevo turno aunque
        // estuviera leyendo arriba. Pedimos stick al hilo para los próximos cambios.
        stickRequest.value++;

        const tempId = -Date.now();
        messages.value.push({
            id: tempId,
            role: 'user',
            content: text || '📎 Recibo adjunto',
            created_at: new Date().toISOString(),
        });
        inputText.value = '';
        pendingImage.value = null;

        try {
            const url = route(routes.sendMessage, {
                tenant: slug.value,
                session: props.activeSessionId,
            });
            let data;
            if (image) {
                const fd = new FormData();
                if (text) fd.append('content', text);
                fd.append('attachment', image);
                ({ data } = await axios.post(url, fd, { headers: { 'Content-Type': 'multipart/form-data' } }));
            } else {
                ({ data } = await axios.post(url, { content: text }));
            }

            // Reemplazo atómico: construimos el array final y lo asignamos UNA vez.
            // Si lo hacíamos en pasos (filter → push N veces), el DOM se encogía
            // entre estados intermedios y el scroll se veía saltando arriba.
            const filtered = messages.value.filter((m) => m.id !== tempId);
            const existingIds = new Set(filtered.map((m) => m.id));
            const newOnes = (data.messages || []).filter((m) => !existingIds.has(m.id));
            messages.value = [...filtered, ...newOnes];
        } catch (err) {
            messages.value = messages.value.filter((m) => m.id !== tempId);
            errorBanner.value = err.response?.data?.message || 'No pude enviar tu mensaje.';
        } finally {
            sending.value = false;
        }
    }

    function newSession() {
        router.post(route(routes.createSession, slug.value));
    }

    function switchSession(id) {
        router.get(route(routes.index, slug.value), { session: id }, { preserveScroll: true });
    }

    const examplePrompts = [
        '¿Cuánto vendí hoy?',
        'Top 5 productos de esta semana',
        'Gastos más fuertes del mes',
        '¿Qué turnos están abiertos?',
        '¿Cuánto me deben los clientes?',
    ];

    // reactive() desenvuelve los refs internos: los componentes consumen
    // `chat.sending`, `v-model="chat.inputText"`, etc., sin `.value`.
    return reactive({
        routes,
        slug,
        sessions: computed(() => props.sessions),
        activeSessionId: computed(() => props.activeSessionId),
        messages,
        inputText,
        sending,
        errorBanner,
        stickRequest,
        pendingImage,
        selectImage,
        clearImage,
        micSupported,
        isRecording,
        transcribing,
        recordTimeLabel,
        toggleRecording,
        voiceAutoplay,
        playingMessageId,
        loadingVoiceFor,
        playMessage,
        stopAudio,
        renderItems,
        budgetText,
        examplePrompts,
        send,
        newSession,
        switchSession,
    });
}
