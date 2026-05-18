<script setup>
import SalesSummaryCard from './SalesSummaryCard.vue';
import ExpenseSummaryCard from './ExpenseSummaryCard.vue';
import TopProductsCard from './TopProductsCard.vue';
import ShiftStatusCard from './ShiftStatusCard.vue';
import CustomerStatsCard from './CustomerStatsCard.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { useAudioRecorder } from '@/composables/useAudioRecorder';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    activeSessionId: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    budget: { type: Object, default: () => ({ remaining_cents: 0, cap_cents: 0 }) },
    // Route names for the current role. The page passes them in.
    routes: {
        type: Object,
        required: true,
        validator: (v) => v.index && v.createSession && v.sendMessage,
    },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const cardComponents = {
    sales_summary: SalesSummaryCard,
    expense_summary: ExpenseSummaryCard,
    top_products: TopProductsCard,
    shift_status: ShiftStatusCard,
    customer_debt: CustomerStatsCard,
    customer_top_buyers: CustomerStatsCard,
};

const messages = ref([...props.messages]);
const inputText = ref('');
const sending = ref(false);
const errorBanner = ref(null);
const threadRef = ref(null);
const inputRef = ref(null);

// Textarea auto-grow: se ajusta entre 3 y 8 líneas según contenido.
const MIN_INPUT_HEIGHT = 84;   // ~3 líneas con text-base
const MAX_INPUT_HEIGHT = 220;  // ~8 líneas; después aparece scroll interno

const resizeInput = () => {
    const el = inputRef.value;
    if (!el) return;
    el.style.height = 'auto';
    const next = Math.min(MAX_INPUT_HEIGHT, Math.max(MIN_INPUT_HEIGHT, el.scrollHeight));
    el.style.height = next + 'px';
    el.style.overflowY = el.scrollHeight > MAX_INPUT_HEIGHT ? 'auto' : 'hidden';
};

watch(inputText, resizeInput);

// Dictado por voz: graba en el browser, sube a /transcribir, recibe texto.
// El texto cae al cuadro de entrada para que el usuario lo revise antes de enviar.
const { isSupported: micSupported, isRecording, duration: recordDuration,
    audioBlob, startRecording, stopRecording, reset: resetRecording,
    error: recorderError } = useAudioRecorder({ maxSeconds: 90 });
const transcribing = ref(false);

watch(audioBlob, async (blob) => {
    if (!blob) return;
    if (!props.routes.transcribe) {
        errorBanner.value = 'El dictado no está habilitado en este rol.';
        resetRecording();
        return;
    }
    transcribing.value = true;
    errorBanner.value = null;
    try {
        const form = new FormData();
        form.append('audio', blob, blob.type?.includes('webm') ? 'audio.webm' : 'audio.dat');
        const url = route(props.routes.transcribe, slug.value);
        const { data } = await axios.post(url, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const text = (data?.text ?? '').trim();
        if (!text) {
            errorBanner.value = 'No escuché nada. Intenta de nuevo.';
        } else {
            // Concatena al input por si el usuario ya estaba escribiendo.
            inputText.value = inputText.value
                ? inputText.value.trimEnd() + ' ' + text
                : text;
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
// Toggle global persistido en localStorage. Botón play manual en cada bubble
// y auto-play del último mensaje del asistente cuando se reciba uno nuevo.
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
    if (!props.routes.speak) return;

    // Si ya está sonando este mismo, alterna pause/stop.
    if (playingMessageId.value === message.id) {
        stopAudio();
        return;
    }
    stopAudio();

    loadingVoiceFor.value = message.id;
    try {
        const url = route(props.routes.speak, {
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
    if (voiceAutoplay.value) {
        playMessage(newest);
    }
}, { deep: true });

// Cambiar de sesión / desmontar: corta el audio activo.
watch(() => props.activeSessionId, () => stopAudio());
onBeforeUnmount(() => stopAudio());

function guessKindFromToolName(name) {
    return ({
        consultar_ventas: 'sales_summary',
        consultar_gastos: 'expense_summary',
        consultar_productos_top: 'top_products',
        consultar_turnos: 'shift_status',
        consultar_clientes: 'customer_debt',
    })[name] || 'unknown';
}

// Agrupa los mensajes en una secuencia de "items" renderizables. Cada tool
// con resultado se acumula y queda anclado al SIGUIENTE mensaje del asistente
// con contenido — así una card sólo aparece una vez, junto a la respuesta que
// la usó, y nunca se repinta en turnos posteriores.
const renderItems = computed(() => {
    const sorted = [...messages.value].sort((a, b) => a.id - b.id);
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

// Stick-to-bottom: si el usuario está al fondo cuando llega un cambio,
// mantenerlo pegado al fondo SIN animación. Si está leyendo arriba, no tocar
// su scroll. El smooth scroll causaba "salto arriba y luego abajo" porque el
// watch se dispara varias veces durante un turno (optimistic → replace → adds).
const STICK_TOLERANCE_PX = 80;
let wasAtBottom = true;

const isAtBottom = () => {
    const el = threadRef.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight <= STICK_TOLERANCE_PX;
};

watch(messages, async () => {
    // Captura del estado ANTES del re-render. Como el watch corre tras la
    // mutación pero antes del flush del DOM, el scrollHeight aún no incluye
    // el contenido nuevo. Si estábamos al fondo, queremos quedarnos al fondo.
    const shouldStick = wasAtBottom;
    await nextTick();
    if (shouldStick && threadRef.value) {
        threadRef.value.scrollTop = threadRef.value.scrollHeight;
    }
    wasAtBottom = isAtBottom();
}, { deep: true });

// Mantenemos el flag al día con scrolls manuales del usuario.
const onThreadScroll = () => {
    wasAtBottom = isAtBottom();
};

const budgetText = computed(() => {
    const remaining = (props.budget?.remaining_cents ?? 0) / 100;
    const cap = (props.budget?.cap_cents ?? 0) / 100;
    return `Presupuesto IA del mes: $${remaining.toFixed(2)} / $${cap.toFixed(2)} USD`;
});

async function send() {
    const text = inputText.value.trim();
    if (!text || sending.value) return;
    if (!props.activeSessionId) {
        errorBanner.value = 'Crea una sesión primero.';
        return;
    }

    sending.value = true;
    errorBanner.value = null;
    // El usuario acaba de interactuar: queremos que vea el nuevo turno aunque
    // estuviera leyendo arriba. Forzamos stick para los próximos cambios.
    wasAtBottom = true;

    const tempId = -Date.now();
    messages.value.push({
        id: tempId,
        role: 'user',
        content: text,
        created_at: new Date().toISOString(),
    });
    inputText.value = '';

    try {
        const url = route(props.routes.sendMessage, {
            tenant: slug.value,
            session: props.activeSessionId,
        });
        const { data } = await axios.post(url, { content: text });

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
    router.post(route(props.routes.createSession, slug.value));
}

function switchSession(id) {
    router.get(route(props.routes.index, slug.value), { session: id }, { preserveScroll: true });
}

const examplePrompts = [
    '¿Cuánto vendí hoy?',
    'Top 5 productos de esta semana',
    'Gastos más fuertes del mes',
    '¿Qué turnos están abiertos?',
    '¿Cuánto me deben los clientes?',
];
</script>

<template>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[260px_1fr]">
        <aside class="flex flex-col gap-3 lg:max-h-[calc(100vh-9rem)] lg:overflow-y-auto">
            <button
                @click="newSession"
                class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nueva conversación
            </button>

            <div class="space-y-1">
                <button
                    v-for="s in sessions"
                    :key="s.id"
                    @click="switchSession(s.id)"
                    :class="[
                        'w-full truncate rounded-lg px-3 py-2 text-left text-sm transition',
                        s.id === activeSessionId
                            ? 'bg-orange-50 font-semibold text-orange-900 ring-1 ring-orange-200'
                            : 'text-gray-700 hover:bg-gray-100',
                    ]"
                >
                    {{ s.title || 'Sin título' }}
                </button>
                <p v-if="!sessions.length" class="px-3 py-2 text-sm italic text-gray-500">
                    Sin conversaciones aún.
                </p>
            </div>

            <div class="mt-auto space-y-2">
                <label v-if="routes.speak" class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-700">
                    <input v-model="voiceAutoplay" type="checkbox" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500" />
                    <span>Leer respuestas en voz alta</span>
                </label>
                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
                    {{ budgetText }}
                </div>
            </div>
        </aside>

        <section class="flex min-h-[600px] flex-col rounded-2xl border border-gray-200 bg-white shadow-sm lg:max-h-[calc(100vh-9rem)]">
            <div ref="threadRef" @scroll.passive="onThreadScroll" class="flex-1 space-y-4 overflow-y-auto p-5">
                <div v-if="!activeSessionId" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
                    <p class="mb-2">No hay conversación activa.</p>
                    <button @click="newSession" class="text-orange-700 underline hover:text-orange-900">
                        Crear una nueva
                    </button>
                </div>

                <div v-else-if="!messages.length" class="space-y-3">
                    <p class="text-sm font-medium text-gray-700">Pregúntame algo. Por ejemplo:</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(p, i) in examplePrompts"
                            :key="i"
                            @click="inputText = p"
                            class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-900"
                        >
                            {{ p }}
                        </button>
                    </div>
                </div>

                <template v-for="item in renderItems" :key="`${item.kind}-${item.id}`">
                    <div v-if="item.kind === 'user'" class="flex justify-end">
                        <div class="max-w-[78%] whitespace-pre-wrap rounded-2xl rounded-tr-md bg-gradient-to-r from-orange-500 to-red-600 px-4 py-3 text-base leading-relaxed text-white shadow-sm">
                            {{ item.message.content }}
                        </div>
                    </div>

                    <div v-else-if="item.kind === 'assistant'" class="space-y-3">
                        <div class="flex items-end justify-start gap-2">
                            <div class="max-w-[78%] whitespace-pre-wrap rounded-2xl rounded-tl-md bg-gray-100 px-4 py-3 text-base leading-relaxed text-gray-900">
                                {{ item.message.content }}
                            </div>
                            <button
                                v-if="routes.speak && item.message.id > 0"
                                type="button"
                                @click="playMessage(item.message)"
                                :disabled="loadingVoiceFor === item.message.id"
                                :title="playingMessageId === item.message.id ? 'Detener' : 'Escuchar respuesta'"
                                :class="[
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border transition disabled:cursor-not-allowed disabled:opacity-50',
                                    playingMessageId === item.message.id
                                        ? 'border-orange-300 bg-orange-100 text-orange-700'
                                        : 'border-gray-200 bg-white text-gray-500 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700',
                                ]"
                            >
                                <svg v-if="loadingVoiceFor === item.message.id" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                    <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                                    <path d="M22 12a10 10 0 0 1-10 10" />
                                </svg>
                                <svg v-else-if="playingMessageId === item.message.id" class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                    <rect x="6" y="6" width="4" height="12" rx="1" />
                                    <rect x="14" y="6" width="4" height="12" rx="1" />
                                </svg>
                                <svg v-else class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            </button>
                        </div>
                        <template v-for="c in item.cards" :key="c.id">
                            <component :is="cardComponents[c.kind]" v-if="cardComponents[c.kind]" :data="c.data" />
                            <div v-else class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
                                <div class="mb-1 font-semibold">Resultado de {{ c.tool_name }}</div>
                                <pre class="overflow-x-auto whitespace-pre-wrap font-mono">{{ JSON.stringify(c.data, null, 2) }}</pre>
                            </div>
                        </template>
                    </div>

                    <div v-else-if="item.kind === 'orphan_cards'" class="space-y-3">
                        <template v-for="c in item.cards" :key="c.id">
                            <component :is="cardComponents[c.kind]" v-if="cardComponents[c.kind]" :data="c.data" />
                        </template>
                    </div>

                    <div v-else-if="item.kind === 'tool_error'" class="flex justify-start">
                        <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                            Acción {{ item.message.tool_name }} rechazada: {{ item.message.tool_result?.message || item.message.tool_status }}
                        </div>
                    </div>
                </template>

                <div v-if="sending" class="flex justify-start">
                    <div class="flex items-center gap-2 rounded-2xl bg-gray-100 px-4 py-2.5 text-sm text-gray-600">
                        <span class="inline-flex space-x-1">
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0s"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0.15s"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0.3s"></span>
                        </span>
                        Pensando…
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-200 p-4">
                <div v-if="errorBanner" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">{{ errorBanner }}</div>

                <!-- Estado de grabación / transcripción -->
                <div v-if="isRecording" class="mb-2 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                    <span class="flex items-center gap-2">
                        <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-red-600"></span>
                        Grabando… {{ recordTimeLabel }}
                    </span>
                    <button type="button" @click="toggleRecording" class="font-semibold text-red-700 underline hover:text-red-900">
                        Detener
                    </button>
                </div>
                <div v-else-if="transcribing" class="mb-2 flex items-center gap-2 rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-800">
                    <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                        <path d="M22 12a10 10 0 0 1-10 10" />
                    </svg>
                    Transcribiendo audio…
                </div>

                <form @submit.prevent="send" class="flex items-end gap-2">
                    <textarea
                        ref="inputRef"
                        v-model="inputText"
                        :disabled="!activeSessionId || sending || isRecording || transcribing"
                        rows="3"
                        placeholder="Escribe tu pregunta…"
                        class="flex-1 resize-none rounded-xl border-gray-300 px-4 py-3 text-base leading-relaxed focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                        style="min-height: 84px;"
                        @keydown.enter.exact.prevent="send"
                        @input="resizeInput"
                    />

                    <!-- Botón micrófono. Sólo si el navegador soporta MediaRecorder y la ruta de transcripción está disponible. -->
                    <button
                        v-if="micSupported && routes.transcribe"
                        type="button"
                        @click="toggleRecording"
                        :disabled="!activeSessionId || sending || transcribing"
                        :title="isRecording ? 'Detener grabación' : 'Dictar por voz'"
                        :class="[
                            'flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl border transition disabled:cursor-not-allowed disabled:opacity-50',
                            isRecording
                                ? 'border-red-300 bg-red-100 text-red-700 hover:bg-red-200'
                                : 'border-gray-300 bg-white text-gray-600 hover:border-orange-400 hover:bg-orange-50 hover:text-orange-700',
                        ]"
                    >
                        <svg v-if="!isRecording" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                        </svg>
                        <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                            <rect x="6" y="6" width="12" height="12" rx="2" />
                        </svg>
                    </button>

                    <button
                        type="submit"
                        :disabled="!activeSessionId || sending || !inputText.trim() || isRecording || transcribing"
                        class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Enviar
                    </button>
                </form>
            </div>
        </section>
    </div>
</template>
