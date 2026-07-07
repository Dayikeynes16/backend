# Asistente Mini-App — F0 (descomposición) + F1 (mini-app) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Descomponer el chat del asistente en piezas reutilizables (F0, sin cambio de comportamiento) y construir la mini-app móvil `/{tenant}/asistente` con paridad funcional (F1), según `docs/superpowers/specs/2026-07-06-asistente-mini-app-design.md`.

**Architecture:** El monolito `AsistenteChat.vue` (670 líneas) se separa en un composable (`useAssistantChat`) + 4 subcomponentes; la página clásica queda componiendo exactamente esas piezas (decisión D3: ambas superficies comparten todo). En backend, los dos `AsistenteController` gemelos se unifican en un trait `HandlesAssistantChat` y un tercer controller (`AssistantAppController`) lo reutiliza bajo un grupo de rutas multi-rol nuevo (`asistente.*`, patrón agenda). Sin cajero (D1), sin migraciones.

**Tech Stack:** Laravel 13 + Inertia v2 + Vue 3 `<script setup>` + Tailwind 3 + Ziggy. Tests PHPUnit (feature). No hay infraestructura de tests JS en el repo — el frontend se verifica con `npm run build` + los feature tests de backend + checklist manual (convención existente del proyecto).

**Alcance:** La extracción de `CustomerGlobalPaymentService` (F0 backend del spec) NO va en este plan: solo la necesita F2 y tocará dinero — tendrá su propio plan junto con el cobro FIFO.

**Comandos (todo vía Sail):**
- Tests: `vendor/bin/sail artisan test --compact --filter=NombreTest`
- Build: `vendor/bin/sail npm run build`
- Formato PHP: `vendor/bin/sail bin pint --dirty --format agent`

---

## Contexto imprescindible (leer antes de la Task 1)

- `resources/js/Components/Asistente/AsistenteChat.vue` — el monolito fuente; todo el código de las Tasks 1-6 sale de ahí. Contrato de props: `sessions`, `activeSessionId`, `messages`, `budget`, `routes` (mapa de nombres Ziggy; cero rutas hardcodeadas).
- `app/Http/Controllers/Empresa/AsistenteController.php` y `app/Http/Controllers/Sucursal/AsistenteController.php` — gemelos (~190 líneas); solo difieren en página Inertia y ruta de redirect.
- Rutas actuales: `routes/web.php:176-183` (empresa) y `:396-403` (sucursal). Patrón multi-rol de referencia: grupo agenda `routes/web.php:546-549`.
- Tests existentes que NO deben romperse: `tests/Feature/Ai/AssistantControllerTest.php`, `SucursalAsistenteControllerTest.php`, `AssistantTranscribeTest.php`, `AssistantSpeakTest.php`.
- Invariante del proyecto: TTS (`routes.speak`) está deshabilitado en las páginas (comentado); el código debe seguir funcionando si algún día se reactiva la ruta.

---

### Task 1: Composable `useAssistantChat`

**Files:**
- Create: `resources/js/composables/useAssistantChat.js`
- (Referencia de origen: `resources/js/Components/Asistente/AsistenteChat.vue:46-439`)

- [x] **Step 1: Crear el composable con TODA la lógica no-DOM del monolito**

El único cambio semántico respecto al monolito: `wasAtBottom = true` (scroll) se sustituye por un contador `stickRequest` que los componentes observan, y `onImageSelected(event)` se convierte en `selectImage(file)` (el reset del `<input>` queda en `ChatInputBar`).

```js
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

    function selectImage(file) {
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            errorBanner.value = 'Solo se permiten imágenes (jpg, png, webp).';
        } else if (file.size > 5 * 1024 * 1024) {
            errorBanner.value = 'La imagen no puede superar 5 MB.';
        } else {
            errorBanner.value = null;
            pendingImage.value = file;
        }
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

    watch(recorderError, (msg) => {
        if (msg) errorBanner.value = msg;
    });

    // ─── TTS: reproducir respuestas del asistente con ElevenLabs ──────────
    // Toggle global persistido en localStorage. Sólo activo si routes.speak existe.
    const VOICE_PREF_KEY = 'assistant-voice-autoplay';
    const voiceAutoplay = ref((localStorage.getItem(VOICE_PREF_KEY) ?? '1') === '1');
    watch(voiceAutoplay, (v) => localStorage.setItem(VOICE_PREF_KEY, v ? '1' : '0'));

    const playingMessageId = ref(null);
    const loadingVoiceFor = ref(null);
    let currentAudio = null;
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

    onBeforeUnmount(() => stopAudio());

    // Cuando Inertia navega a otra sesión las props cambian pero el componente
    // NO se desmonta: resincronizar el estado local desde las props nuevas.
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
            preparar_borrador_categoria_gasto: 'assistant_draft',
            editar_categoria_gasto: 'assistant_draft',
        })[name] || 'unknown';
    }

    // Agrupa los mensajes en una secuencia de "items" renderizables. Cada tool
    // con resultado se acumula y queda anclado al SIGUIENTE mensaje del
    // asistente con contenido.
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

            // Reemplazo atómico para que el scroll no salte entre estados intermedios.
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
        renderItems,
        budgetText,
        examplePrompts,
        send,
        newSession,
        switchSession,
    });
}
```

- [x] **Step 2: Verificar que el build no rompe (el archivo aún no se importa)**

Run: `vendor/bin/sail npm run build`
Expected: build exitoso.

- [x] **Step 3: Commit**

```bash
git add resources/js/composables/useAssistantChat.js
git commit -m "refactor(asistente): extraer useAssistantChat del monolito AsistenteChat"
```

---

### Task 2: `ToolResultCard.vue` (dispatcher kind→card)

**Files:**
- Create: `resources/js/Components/Asistente/chat/ToolResultCard.vue`

- [x] **Step 1: Crear el componente**

Nota: el fallback `<pre>` ahora aplica también a cards huérfanas de kind desconocido (antes las huérfanas desconocidas no se pintaban); es una mejora deliberada, no una regresión.

```vue
<script setup>
import SalesSummaryCard from '../SalesSummaryCard.vue';
import ExpenseSummaryCard from '../ExpenseSummaryCard.vue';
import TopProductsCard from '../TopProductsCard.vue';
import ShiftStatusCard from '../ShiftStatusCard.vue';
import CustomerStatsCard from '../CustomerStatsCard.vue';
import ProductDetailsCard from '../ProductDetailsCard.vue';
import PurchaseSummaryCard from '../PurchaseSummaryCard.vue';
import AccountsPayableCard from '../AccountsPayableCard.vue';
import ExpenseCategoriesCard from '../ExpenseCategoriesCard.vue';
import AssistantDraftCard from '../AssistantDraftCard.vue';

defineProps({
    // { id, kind, data, tool_name } — item de renderItems del composable.
    card: { type: Object, required: true },
    routes: { type: Object, required: true },
});

const cardComponents = {
    sales_summary: SalesSummaryCard,
    expense_summary: ExpenseSummaryCard,
    top_products: TopProductsCard,
    shift_status: ShiftStatusCard,
    customer_debt: CustomerStatsCard,
    customer_top_buyers: CustomerStatsCard,
    product_details: ProductDetailsCard,
    purchase_summary: PurchaseSummaryCard,
    accounts_payable: AccountsPayableCard,
    expense_categories: ExpenseCategoriesCard,
};
</script>

<template>
    <AssistantDraftCard v-if="card.kind === 'assistant_draft'" :data="card.data" :routes="routes" />
    <component v-else-if="cardComponents[card.kind]" :is="cardComponents[card.kind]" :data="card.data" />
    <div v-else class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
        <div class="mb-1 font-semibold">Resultado de {{ card.tool_name }}</div>
        <pre class="overflow-x-auto whitespace-pre-wrap font-mono">{{ JSON.stringify(card.data, null, 2) }}</pre>
    </div>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Components/Asistente/chat/ToolResultCard.vue
git commit -m "refactor(asistente): extraer ToolResultCard (dispatcher kind->card)"
```

---

### Task 3: `MessageThread.vue` (hilo + burbujas + stick-to-bottom)

**Files:**
- Create: `resources/js/Components/Asistente/chat/MessageThread.vue`

- [x] **Step 1: Crear el componente**

```vue
<script setup>
import { nextTick, ref, watch } from 'vue';
import ToolResultCard from './ToolResultCard.vue';

const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const threadRef = ref(null);

// Stick-to-bottom: si el usuario está al fondo cuando llega un cambio,
// mantenerlo pegado al fondo SIN animación. Si está leyendo arriba, no tocar.
const STICK_TOLERANCE_PX = 80;
let wasAtBottom = true;

const isAtBottom = () => {
    const el = threadRef.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight <= STICK_TOLERANCE_PX;
};

watch(() => props.chat.messages, async () => {
    const shouldStick = wasAtBottom;
    await nextTick();
    if (shouldStick && threadRef.value) {
        threadRef.value.scrollTop = threadRef.value.scrollHeight;
    }
    wasAtBottom = isAtBottom();
}, { deep: true });

// El composable pide scroll forzado al enviar mensaje o cambiar de sesión.
watch(() => props.chat.stickRequest, async () => {
    wasAtBottom = true;
    await nextTick();
    if (threadRef.value) threadRef.value.scrollTop = threadRef.value.scrollHeight;
});

const onThreadScroll = () => {
    wasAtBottom = isAtBottom();
};
</script>

<template>
    <div ref="threadRef" @scroll.passive="onThreadScroll" class="flex-1 space-y-4 overflow-y-auto p-5">
        <div v-if="!chat.activeSessionId" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
            <p class="mb-2">No hay conversación activa.</p>
            <button @click="chat.newSession()" class="text-orange-700 underline hover:text-orange-900">
                Crear una nueva
            </button>
        </div>

        <div v-else-if="!chat.messages.length" class="space-y-3">
            <p class="text-sm font-medium text-gray-700">Pregúntame algo. Por ejemplo:</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(p, i) in chat.examplePrompts"
                    :key="i"
                    @click="chat.inputText = p"
                    class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-900"
                >
                    {{ p }}
                </button>
            </div>
        </div>

        <template v-for="item in chat.renderItems" :key="`${item.kind}-${item.id}`">
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
                        v-if="chat.routes.speak && item.message.id > 0"
                        type="button"
                        @click="chat.playMessage(item.message)"
                        :disabled="chat.loadingVoiceFor === item.message.id"
                        :title="chat.playingMessageId === item.message.id ? 'Detener' : 'Escuchar respuesta'"
                        :class="[
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border transition disabled:cursor-not-allowed disabled:opacity-50',
                            chat.playingMessageId === item.message.id
                                ? 'border-orange-300 bg-orange-100 text-orange-700'
                                : 'border-gray-200 bg-white text-gray-500 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700',
                        ]"
                    >
                        <svg v-if="chat.loadingVoiceFor === item.message.id" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                            <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                            <path d="M22 12a10 10 0 0 1-10 10" />
                        </svg>
                        <svg v-else-if="chat.playingMessageId === item.message.id" class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <rect x="6" y="6" width="4" height="12" rx="1" />
                            <rect x="14" y="6" width="4" height="12" rx="1" />
                        </svg>
                        <svg v-else class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </button>
                </div>
                <ToolResultCard v-for="c in item.cards" :key="c.id" :card="c" :routes="chat.routes" />
            </div>

            <div v-else-if="item.kind === 'orphan_cards'" class="space-y-3">
                <ToolResultCard v-for="c in item.cards" :key="c.id" :card="c" :routes="chat.routes" />
            </div>

            <div v-else-if="item.kind === 'tool_error'" class="flex justify-start">
                <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                    Acción {{ item.message.tool_name }} rechazada: {{ item.message.tool_result?.message || item.message.tool_status }}
                </div>
            </div>
        </template>

        <div v-if="chat.sending" class="flex justify-start">
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
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Components/Asistente/chat/MessageThread.vue
git commit -m "refactor(asistente): extraer MessageThread (hilo + stick-to-bottom)"
```

---

### Task 4: `ChatInputBar.vue`

**Files:**
- Create: `resources/js/Components/Asistente/chat/ChatInputBar.vue`

- [x] **Step 1: Crear el componente**

```vue
<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const inputRef = ref(null);
const imageInputRef = ref(null);

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

watch(() => props.chat.inputText, resizeInput);

function onImageSelected(e) {
    props.chat.selectImage(e.target?.files?.[0]);
    if (imageInputRef.value) imageInputRef.value.value = '';
}
</script>

<template>
    <div class="border-t border-gray-200 p-4">
        <div v-if="chat.errorBanner" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">{{ chat.errorBanner }}</div>

        <!-- Estado de grabación / transcripción -->
        <div v-if="chat.isRecording" class="mb-2 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
            <span class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-red-600"></span>
                Grabando… {{ chat.recordTimeLabel }}
            </span>
            <button type="button" @click="chat.toggleRecording()" class="font-semibold text-red-700 underline hover:text-red-900">
                Detener
            </button>
        </div>
        <div v-else-if="chat.transcribing" class="mb-2 flex items-center gap-2 rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-800">
            <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <circle cx="12" cy="12" r="10" stroke-opacity="0.3" />
                <path d="M22 12a10 10 0 0 1-10 10" />
            </svg>
            Transcribiendo audio…
        </div>

        <!-- Recibo adjunto seleccionado -->
        <div v-if="chat.pendingImage" class="mb-2 flex items-center justify-between rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-xs text-orange-800">
            <span class="flex min-w-0 items-center gap-2 truncate">📎 {{ chat.pendingImage.name }}</span>
            <button type="button" @click="chat.clearImage()" class="shrink-0 font-semibold text-orange-700 underline hover:text-orange-900">Quitar</button>
        </div>

        <form @submit.prevent="chat.send()" class="flex items-end gap-2">
            <input ref="imageInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onImageSelected" />

            <textarea
                ref="inputRef"
                v-model="chat.inputText"
                :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                rows="3"
                placeholder="Escribe o adjunta un recibo…"
                class="flex-1 resize-none rounded-xl border-gray-300 px-4 py-3 text-base leading-relaxed focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                style="min-height: 84px;"
                @keydown.enter.exact.prevent="chat.send()"
                @input="resizeInput"
            />

            <!-- Adjuntar recibo (imagen) para preparar un gasto. -->
            <button
                type="button"
                @click="imageInputRef?.click()"
                :disabled="!chat.activeSessionId || chat.sending || chat.isRecording || chat.transcribing"
                title="Adjuntar recibo"
                class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl border border-gray-300 bg-white text-gray-600 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
            </button>

            <!-- Botón micrófono. Sólo si el navegador soporta MediaRecorder y hay ruta de transcripción. -->
            <button
                v-if="chat.micSupported && chat.routes.transcribe"
                type="button"
                @click="chat.toggleRecording()"
                :disabled="!chat.activeSessionId || chat.sending || chat.transcribing"
                :title="chat.isRecording ? 'Detener grabación' : 'Dictar por voz'"
                :class="[
                    'flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl border transition disabled:cursor-not-allowed disabled:opacity-50',
                    chat.isRecording
                        ? 'border-red-300 bg-red-100 text-red-700 hover:bg-red-200'
                        : 'border-gray-300 bg-white text-gray-600 hover:border-orange-400 hover:bg-orange-50 hover:text-orange-700',
                ]"
            >
                <svg v-if="!chat.isRecording" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                </svg>
                <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                    <rect x="6" y="6" width="12" height="12" rx="2" />
                </svg>
            </button>

            <button
                type="submit"
                :disabled="!chat.activeSessionId || chat.sending || (!chat.inputText.trim() && !chat.pendingImage) || chat.isRecording || chat.transcribing"
                class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Enviar
            </button>
        </form>
    </div>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Components/Asistente/chat/ChatInputBar.vue
git commit -m "refactor(asistente): extraer ChatInputBar (texto + voz + adjunto)"
```

---

### Task 5: `SessionsPanel.vue`

**Files:**
- Create: `resources/js/Components/Asistente/chat/SessionsPanel.vue`

- [x] **Step 1: Crear el componente**

Emite `navigate` al crear/cambiar sesión para que la mini-app cierre el bottom-sheet; la página clásica simplemente ignora el evento.

```vue
<script setup>
const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const emit = defineEmits(['navigate']);

function create() {
    emit('navigate');
    props.chat.newSession();
}

function open(id) {
    emit('navigate');
    props.chat.switchSession(id);
}
</script>

<template>
    <div class="flex h-full flex-col gap-3">
        <button
            @click="create"
            class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Nueva conversación
        </button>

        <div class="space-y-1">
            <button
                v-for="s in chat.sessions"
                :key="s.id"
                @click="open(s.id)"
                :class="[
                    'w-full truncate rounded-lg px-3 py-2 text-left text-sm transition',
                    s.id === chat.activeSessionId
                        ? 'bg-orange-50 font-semibold text-orange-900 ring-1 ring-orange-200'
                        : 'text-gray-700 hover:bg-gray-100',
                ]"
            >
                {{ s.title || 'Sin título' }}
            </button>
            <p v-if="!chat.sessions.length" class="px-3 py-2 text-sm italic text-gray-500">
                Sin conversaciones aún.
            </p>
        </div>

        <div class="mt-auto space-y-2">
            <label v-if="chat.routes.speak" class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-700">
                <input v-model="chat.voiceAutoplay" type="checkbox" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500" />
                <span>Leer respuestas en voz alta</span>
            </label>
            <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
                {{ chat.budgetText }}
            </div>
        </div>
    </div>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Components/Asistente/chat/SessionsPanel.vue
git commit -m "refactor(asistente): extraer SessionsPanel"
```

---

### Task 6: Recomponer `AsistenteChat.vue` (misma API, mismo comportamiento)

**Files:**
- Modify: `resources/js/Components/Asistente/AsistenteChat.vue` (reemplazo completo del archivo)

- [x] **Step 1: Reemplazar el contenido COMPLETO del archivo por la composición**

Las props no cambian: `Pages/Empresa/Asistente.vue` y `Pages/Sucursal/Asistente.vue` no se tocan.

```vue
<script setup>
import MessageThread from './chat/MessageThread.vue';
import ChatInputBar from './chat/ChatInputBar.vue';
import SessionsPanel from './chat/SessionsPanel.vue';
import { useAssistantChat } from '@/composables/useAssistantChat';

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

const chat = useAssistantChat(props, props.routes);
</script>

<template>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[260px_1fr]">
        <aside class="lg:max-h-[calc(100vh-9rem)] lg:overflow-y-auto">
            <SessionsPanel :chat="chat" />
        </aside>

        <section class="flex min-h-[600px] flex-col rounded-2xl border border-gray-200 bg-white shadow-sm lg:max-h-[calc(100vh-9rem)]">
            <MessageThread :chat="chat" />
            <ChatInputBar :chat="chat" />
        </section>
    </div>
</template>
```

- [x] **Step 2: Build**

Run: `vendor/bin/sail npm run build`
Expected: build exitoso, sin warnings de imports rotos.

- [x] **Step 3: Verificación de regresión backend (nada debió cambiar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Ai/AssistantControllerTest.php tests/Feature/Ai/SucursalAsistenteControllerTest.php`
Expected: PASS (el refactor es solo frontend).

- [ ] **Step 4: Verificación manual del asistente clásico** (con `vendor/bin/sail composer run dev` corriendo)

Como `admin@eltoro.test` / `password` en `/el-toro/empresa/asistente`:
1. Crear conversación nueva → redirige con `?session=ID`.
2. Enviar "¿cuánto vendí hoy?" → aparece burbuja optimista, "Pensando…", respuesta + card.
3. Cambiar de sesión en el panel → el hilo se resincroniza.
4. Dictar por voz (permiso de micrófono) → el texto cae al textarea.
5. Adjuntar una imagen → chip "📎", enviar → draft de gasto con card confirmable.
6. Estando scrolleado arriba, recibir respuesta → el scroll NO salta; estando al fondo → se pega al fondo.

- [x] **Step 5: Commit**

```bash
git add resources/js/Components/Asistente/AsistenteChat.vue
git commit -m "refactor(asistente): AsistenteChat compone las piezas extraidas (F0 frontend completo)"
```

---

### Task 7: Trait backend `HandlesAssistantChat` + refactor de los controllers gemelos

**Files:**
- Create: `app/Http/Controllers/Concerns/HandlesAssistantChat.php`
- Modify: `app/Http/Controllers/Empresa/AsistenteController.php` (reemplazo completo)
- Modify: `app/Http/Controllers/Sucursal/AsistenteController.php` (reemplazo completo)

- [x] **Step 1: Crear el trait con la lógica compartida**

Es el cuerpo actual de `Empresa\AsistenteController` (idéntico al de Sucursal) parametrizado por dos métodos abstractos. El constructor con `AssistantOrchestrator $orchestrator` queda en cada controller.

```php
<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Chat del asistente conversacional compartido por Empresa, Sucursal y la
 * mini-app. Lo que limita el alcance de cada rol NO está aquí: cada Tool
 * reescribe branch_id en AbstractAssistantTool::resolveBranch() y los
 * confirmers re-validan. El controller que usa el trait define la página
 * Inertia y la ruta de redirect, y debe declarar la propiedad
 * `private readonly AssistantOrchestrator $orchestrator` en su constructor.
 */
trait HandlesAssistantChat
{
    abstract protected function inertiaPage(): string;

    abstract protected function indexRouteName(): string;

    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $sessions = AiAssistantSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'title', 'message_count', 'last_message_at']);

        $sessionId = $request->integer('session') ?: $sessions->first()?->id;
        $activeSession = $sessionId
            ? AiAssistantSession::query()->where('user_id', $user->id)->find($sessionId)
            : null;

        $messages = $activeSession
            ? $activeSession->messages()
                ->orderBy('id')
                ->get()
                ->map(fn (AiAssistantMessage $m) => $this->serializeMessage($m))
                ->values()
                ->all()
            : [];

        return Inertia::render($this->inertiaPage(), [
            'sessions' => $sessions,
            'activeSessionId' => $activeSession?->id,
            'messages' => $messages,
            'budget' => [
                'remaining_cents' => $this->orchestrator->budgetRemainingCents($tenant),
                'cap_cents' => $tenant->ai_monthly_budget_cents
                    ?? (int) config('ai.assistant.default_monthly_budget_cents', 5000),
            ],
        ]);
    }

    public function createSession(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $session = AiAssistantSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'title' => null,
            'message_count' => 0,
        ]);

        return redirect()->route($this->indexRouteName(), [
            'tenant' => $tenant->slug,
            'session' => $session->id,
        ]);
    }

    public function sendMessage(Request $request, AiAssistantSession $session): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if ($session->user_id !== $user->id || $session->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        $validated = $request->validate([
            'content' => [
                'nullable', 'string',
                'max:'.config('ai.assistant.max_input_text_length', 2000),
            ],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (blank($validated['content'] ?? null) && ! $request->hasFile('attachment')) {
            return response()->json(['message' => 'Escribe un mensaje o adjunta un recibo.'], 422);
        }

        // Rate limit por usuario (hora) y por tenant (día).
        $userKey = 'ai-assistant:user:'.$user->id;
        $tenantKey = 'ai-assistant:tenant:'.$tenant->id;
        $perHour = (int) config('ai.assistant.rate_limit_per_user_per_hour', 60);
        $perDay = (int) config('ai.assistant.rate_limit_per_tenant_per_day', 1000);

        if (RateLimiter::tooManyAttempts($userKey, $perHour)) {
            return response()->json([
                'message' => 'Has excedido el límite por hora. Intenta de nuevo más tarde.',
            ], 429);
        }
        if (RateLimiter::tooManyAttempts($tenantKey, $perDay)) {
            return response()->json([
                'message' => 'Tu empresa alcanzó el límite diario del asistente.',
            ], 429);
        }

        try {
            $this->orchestrator->assertWithinBudget($tenant);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'budget_exhausted') {
                return response()->json([
                    'message' => 'Se agotó el presupuesto de IA de este mes. Contacta a soporte para ampliarlo.',
                ], 402);
            }
            throw $e;
        }

        RateLimiter::hit($userKey, 3600);
        RateLimiter::hit($tenantKey, 86400);

        try {
            $result = $this->orchestrator->handleUserMessage(
                $tenant,
                $user,
                $session,
                (string) ($validated['content'] ?? ''),
                $request->hasFile('attachment') ? [$request->file('attachment')] : [],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No pude procesar tu mensaje. Intenta de nuevo.',
                'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 502);
        }

        // Devolvemos los últimos mensajes nuevos (user + assistant + tool) para
        // que el frontend los agregue al thread sin recargar.
        $newMessages = $session->messages()
            ->where('id', '>', $result['message']->id - 20)
            ->where('id', '<=', $result['message']->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiAssistantMessage $m) => $this->serializeMessage($m))
            ->values()
            ->all();

        return response()->json([
            'session_id' => $session->id,
            'messages' => $newMessages,
            'cards' => $result['cards'],
            'budget_remaining_cents' => $result['budget_remaining_cents'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AiAssistantMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'tool_name' => $m->tool_name,
            'tool_status' => $m->tool_status,
            'tool_result' => $m->role === 'tool' ? $m->tool_result : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
```

- [x] **Step 2: Reemplazar `app/Http/Controllers/Empresa/AsistenteController.php` completo**

```php
<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\SynthesizesAssistantSpeech;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

class AsistenteController extends Controller
{
    use HandlesAssistantChat;
    use SynthesizesAssistantSpeech;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Empresa/Asistente';
    }

    protected function indexRouteName(): string
    {
        return 'empresa.asistente';
    }
}
```

- [x] **Step 3: Reemplazar `app/Http/Controllers/Sucursal/AsistenteController.php` completo**

```php
<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\SynthesizesAssistantSpeech;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

/**
 * Asistente para admin-sucursal. La lógica es idéntica al de Empresa; lo que
 * limita lo que puede consultar es el `branch_id` que cada Tool reescribe en
 * `AbstractAssistantTool::resolveBranch()` cuando el usuario tiene rol
 * admin-sucursal. Aquí sólo cambia el layout/página de Inertia.
 */
class AsistenteController extends Controller
{
    use HandlesAssistantChat;
    use SynthesizesAssistantSpeech;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Sucursal/Asistente';
    }

    protected function indexRouteName(): string
    {
        return 'sucursal.asistente';
    }
}
```

- [x] **Step 4: Correr los tests existentes del asistente (regresión pura)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Ai/AssistantControllerTest.php tests/Feature/Ai/SucursalAsistenteControllerTest.php tests/Feature/Ai/AssistantTranscribeTest.php tests/Feature/Ai/AssistantSpeakTest.php`
Expected: PASS todos.

- [x] **Step 5: Formato + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Concerns/HandlesAssistantChat.php app/Http/Controllers/Empresa/AsistenteController.php app/Http/Controllers/Sucursal/AsistenteController.php
git commit -m "refactor(asistente): unificar controllers gemelos en trait HandlesAssistantChat"
```

---

### Task 8: Test del grupo de rutas de la mini-app (rojo primero)

**Files:**
- Test: `tests/Feature/Ai/AssistantAppControllerTest.php`

- [x] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantAppControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        RateLimiter::clear('ai-assistant:user:'.$this->adminEmpresa->id);
        RateLimiter::clear('ai-assistant:user:'.$this->adminSucursal->id);
        RateLimiter::clear('ai-assistant:tenant:'.$this->tenant->id);
    }

    public function test_admin_empresa_can_view_mini_app(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_admin_sucursal_can_view_mini_app(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_cajero_cannot_view_mini_app(): void
    {
        $this->actingAs($this->cajero);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertForbidden();
    }

    public function test_creating_a_session_redirects_to_mini_app(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->post(route('asistente.sesiones.store', $this->tenant->slug));

        $session = AiAssistantSession::firstOrFail();
        $response->assertRedirect(route('asistente.index', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
        ]));
        $this->assertSame($this->adminEmpresa->id, $session->user_id);
    }

    public function test_sending_a_message_works_via_neutral_route(): void
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);
        Http::fake([
            '*/chat/completions' => Http::response($this->fakeFinalAssistantResponse('Hola, ¿en qué ayudo?')),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'hola'],
        );

        $response->assertOk();
        $this->assertSame(2, AiAssistantMessage::count());
    }

    public function test_user_cannot_post_to_another_users_session(): void
    {
        $strangerSession = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminSucursal->id,
            'message_count' => 0,
        ]);
        Http::fake();

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $strangerSession->id]),
            ['content' => 'fuga'],
        );

        $response->assertNotFound();
        $this->assertSame(0, AiAssistantMessage::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeFinalAssistantResponse(string $text): array
    {
        return [
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $text],
            ]],
        ];
    }
}
```

- [x] **Step 2: Verificar que falla**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Ai/AssistantAppControllerTest.php`
Expected: FAIL con `Route [asistente.index] not defined`.

---

### Task 9: `AssistantAppController` + rutas `asistente.*` (verde)

**Files:**
- Create: `app/Http/Controllers/Asistente/AssistantAppController.php`
- Modify: `routes/web.php` (import + grupo nuevo antes del grupo agenda, `routes/web.php:545`)

- [x] **Step 1: Crear el controller**

Sin `SynthesizesAssistantSpeech`: la mini-app no expone TTS (deshabilitado en UI desde 2026-05-18); si se reactiva, se agrega el trait y la ruta.

```php
<?php

namespace App\Http\Controllers\Asistente;

use App\Http\Controllers\Concerns\HandlesAssistantChat;
use App\Http\Controllers\Concerns\TranscribesAssistantAudio;
use App\Http\Controllers\Controller;
use App\Services\Ai\Assistant\AssistantOrchestrator;

/**
 * Mini-app del asistente (/{tenant}/asistente): experiencia móvil a pantalla
 * completa compartida por admin-empresa y admin-sucursal (D1: sin cajero).
 * El alcance por rol/sucursal lo resuelven las tools y los confirmers
 * server-side, exactamente igual que en las páginas clásicas.
 */
class AssistantAppController extends Controller
{
    use HandlesAssistantChat;
    use TranscribesAssistantAudio;

    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    protected function inertiaPage(): string
    {
        return 'Asistente/App';
    }

    protected function indexRouteName(): string
    {
        return 'asistente.index';
    }
}
```

- [x] **Step 2: Agregar el import en `routes/web.php`**

Junto al import existente de la línea 8 (`use App\Http\Controllers\Ai\AssistantDraftController;`), agregar:

```php
use App\Http\Controllers\Asistente\AssistantAppController;
```

- [x] **Step 3: Agregar el grupo de rutas**

En `routes/web.php`, dentro del grupo `{tenant}`, inmediatamente ANTES del comentario `// Agenda (compartida por los 4 roles bajo /{tenant}/agenda)` (línea 545):

```php
        // Mini-app del asistente (compartida empresa+sucursal bajo /{tenant}/asistente).
        // Cajero excluido a propósito (decisión D1 del spec 2026-07-06-asistente-mini-app).
        Route::middleware('role:admin-empresa|admin-sucursal|superadmin')
            ->prefix('asistente')
            ->name('asistente.')
            ->group(function () {
                Route::get('/', [AssistantAppController::class, 'index'])->name('index');
                Route::post('sesiones', [AssistantAppController::class, 'createSession'])->name('sesiones.store');
                Route::post('sesiones/{session}/mensajes', [AssistantAppController::class, 'sendMessage'])->name('mensajes.store');
                Route::post('transcribir', [AssistantAppController::class, 'transcribe'])->name('transcribir');
                // Confirmación/cancelación de borradores (2ª petición HTTP, botón UI).
                Route::post('drafts/{draft}/confirmar', [AssistantDraftController::class, 'confirm'])->name('drafts.confirm');
                Route::post('drafts/{draft}/cancelar', [AssistantDraftController::class, 'cancel'])->name('drafts.cancel');
            });
```

- [x] **Step 4: Verificar que el test pasa**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Ai/AssistantAppControllerTest.php`
Expected: PASS (6 tests). Nota: `test_admin_empresa_can_view_mini_app` fallará en `assertInertia` con "page not found" solo si Vite valida páginas — la página Vue se crea en la Task 11; `assertInertia` no requiere que el archivo exista, así que debe pasar ya.

- [x] **Step 5: Formato + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Asistente/AssistantAppController.php routes/web.php tests/Feature/Ai/AssistantAppControllerTest.php
git commit -m "feat(asistente): grupo de rutas /{tenant}/asistente para la mini-app (empresa+sucursal)"
```

---

### Task 10: `AssistantAppLayout.vue`

**Files:**
- Create: `resources/js/Layouts/AssistantAppLayout.vue`

- [x] **Step 1: Crear el layout**

Sin sidebar; header compacto con identidad del negocio y botón permanente "Salir" → `route('dashboard')` (redirige por rol). Altura `100dvh` con safe-areas para móvil.

```vue
<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const tenantName = computed(() => page.props.auth.tenant?.name || 'Mi negocio');
const branchName = computed(() => page.props.auth.branch?.name || null);
</script>

<template>
    <div
        class="flex h-[100dvh] flex-col bg-gray-50"
        style="padding-top: env(safe-area-inset-top); padding-left: env(safe-area-inset-left); padding-right: env(safe-area-inset-right);"
    >
        <header class="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-white px-4">
            <div class="flex min-w-0 items-center gap-2.5">
                <img
                    v-if="page.props.auth.tenant?.logo_url"
                    :src="page.props.auth.tenant.logo_url"
                    :alt="tenantName"
                    class="h-8 w-8 rounded-lg object-cover"
                />
                <div v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-red-600 text-sm font-bold text-white">
                    {{ tenantName.charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold leading-tight text-gray-900">{{ tenantName }}</p>
                    <p v-if="branchName" class="truncate text-xs leading-tight text-gray-500">{{ branchName }}</p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <slot name="header-actions" />
                <Link
                    :href="route('dashboard')"
                    class="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-800"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    Salir al panel
                </Link>
            </div>
        </header>

        <main class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
```

- [x] **Step 2: Commit**

```bash
git add resources/js/Layouts/AssistantAppLayout.vue
git commit -m "feat(asistente): layout AssistantAppLayout (pantalla completa, sin sidebar)"
```

---

### Task 11: Página `Pages/Asistente/App.vue` (mini-app mobile-first)

**Files:**
- Create: `resources/js/Pages/Asistente/App.vue`

- [x] **Step 1: Crear la página**

Móvil (<lg): chat a pantalla completa, sesiones en bottom-sheet abierto desde el header. Desktop (≥lg): columna de sesiones a la izquierda, chat centrado. La barra de input queda pegada abajo con safe-area.

```vue
<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AssistantAppLayout from '@/Layouts/AssistantAppLayout.vue';
import MessageThread from '@/Components/Asistente/chat/MessageThread.vue';
import ChatInputBar from '@/Components/Asistente/chat/ChatInputBar.vue';
import SessionsPanel from '@/Components/Asistente/chat/SessionsPanel.vue';
import { useAssistantChat } from '@/composables/useAssistantChat';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    activeSessionId: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    budget: { type: Object, default: () => ({ remaining_cents: 0, cap_cents: 0 }) },
});

const routes = {
    index: 'asistente.index',
    createSession: 'asistente.sesiones.store',
    sendMessage: 'asistente.mensajes.store',
    transcribe: 'asistente.transcribir',
    draftConfirm: 'asistente.drafts.confirm',
    draftCancel: 'asistente.drafts.cancel',
    // TTS (ElevenLabs) deshabilitado — si se reactiva, agregar speak + trait en el controller.
};

const chat = useAssistantChat(props, routes);
const sessionsOpen = ref(false);
</script>

<template>
    <Head title="Asistente" />
    <AssistantAppLayout>
        <template #header-actions>
            <button
                @click="sessionsOpen = true"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 lg:hidden"
                title="Conversaciones"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </button>
        </template>

        <div class="mx-auto flex min-h-0 w-full max-w-2xl flex-1 flex-col lg:max-w-6xl lg:flex-row lg:gap-6 lg:p-6">
            <!-- Columna de sesiones (solo desktop) -->
            <aside class="hidden lg:block lg:w-[260px] lg:shrink-0 lg:overflow-y-auto">
                <SessionsPanel :chat="chat" />
            </aside>

            <!-- Chat -->
            <section class="flex min-h-0 flex-1 flex-col bg-white lg:rounded-2xl lg:border lg:border-gray-200 lg:shadow-sm">
                <MessageThread :chat="chat" />
                <div style="padding-bottom: env(safe-area-inset-bottom);">
                    <ChatInputBar :chat="chat" />
                </div>
            </section>
        </div>

        <!-- Bottom-sheet de conversaciones (móvil) -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="sessionsOpen" class="fixed inset-0 z-40 bg-black/40 lg:hidden" @click="sessionsOpen = false" />
        </Transition>
        <Transition
            enter-active-class="transition-transform duration-250"
            leave-active-class="transition-transform duration-200"
            enter-from-class="translate-y-full"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="sessionsOpen"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[70dvh] overflow-y-auto rounded-t-2xl bg-white p-4 shadow-2xl lg:hidden"
                style="padding-bottom: calc(1rem + env(safe-area-inset-bottom));"
            >
                <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-gray-300" />
                <SessionsPanel :chat="chat" @navigate="sessionsOpen = false" />
            </div>
        </Transition>
    </AssistantAppLayout>
</template>
```

- [x] **Step 2: Build**

Run: `vendor/bin/sail npm run build`
Expected: build exitoso (la página entra al manifest de Vite).

- [x] **Step 3: Commit**

```bash
git add resources/js/Pages/Asistente/App.vue
git commit -m "feat(asistente): pagina mobile-first de la mini-app (Asistente/App)"
```

---

### Task 12: Items de sidebar (Empresa y Sucursal)

**Files:**
- Modify: `resources/js/Layouts/EmpresaLayout.vue:22`
- Modify: `resources/js/Layouts/SucursalLayout.vue:33`

- [x] **Step 1: En `EmpresaLayout.vue`, reemplazar el item del asistente**

Antes (línea 22):
```js
    { label: 'Asistente', route: 'empresa.asistente', match: 'empresa.asistente', icon: 'asistente' },
```
Después (el item nuevo apunta a la mini-app; el clásico queda accesible durante la transición):
```js
    { label: 'Asistente', route: 'asistente.index', match: 'asistente.', icon: 'asistente' },
    { label: 'Asistente clásico', route: 'empresa.asistente', match: 'empresa.asistente', icon: 'asistente' },
```

- [x] **Step 2: En `SucursalLayout.vue`, reemplazar el item del asistente**

Antes (línea 33):
```js
    { label: 'Asistente', route: 'sucursal.asistente', match: 'sucursal.asistente', icon: 'asistente' },
```
Después:
```js
    { label: 'Asistente', route: 'asistente.index', match: 'asistente.', icon: 'asistente' },
    { label: 'Asistente clásico', route: 'sucursal.asistente', match: 'sucursal.asistente', icon: 'asistente' },
```

Nota: `isActive` concatena `match + '*'` → `'asistente.*'` matchea `asistente.index` sin colisionar con `empresa.asistente*` ni `sucursal.asistente*`.

- [x] **Step 3: Build + commit**

Run: `vendor/bin/sail npm run build` — Expected: exitoso.

```bash
git add resources/js/Layouts/EmpresaLayout.vue resources/js/Layouts/SucursalLayout.vue
git commit -m "feat(asistente): items de sidebar hacia la mini-app (empresa y sucursal)"
```

---

### Task 13: Documentación (definition of done del repo)

**Files:**
- Modify: `carniceria-saas/docs/modulos/asistente-ia.md`
- Modify: `carniceria-saas/docs/README.md`
- Modify: `carniceria-saas/docs/superpowers/specs/2026-07-06-asistente-mini-app-design.md` (header `Estado:`)

- [x] **Step 1: Agregar a `docs/modulos/asistente-ia.md` una sección "Mini-app móvil"**

Contenido mínimo a cubrir (redactar siguiendo el estilo del doc):
- Ruta `/{tenant}/asistente` (grupo multi-rol `role:admin-empresa|admin-sucursal|superadmin`, nombres `asistente.*`), controller `Asistente\AssistantAppController`, página `Pages/Asistente/App.vue`, layout `AssistantAppLayout` (sin sidebar, botón "Salir al panel" → `route('dashboard')`).
- Arquitectura compartida (D3): `useAssistantChat` + `chat/{MessageThread,ChatInputBar,SessionsPanel,ToolResultCard}` son las MISMAS piezas que usa el asistente clásico; el trait `HandlesAssistantChat` unifica los tres controllers.
- Cajero excluido (D1). TTS sin exponer en la mini-app.
- Actualizar la sección de rutas del doc con el grupo nuevo.

- [x] **Step 2: Actualizar `docs/README.md`**

En la tabla "Estado del sistema", en la fila del asistente IA, reflejar "mini-app móvil F1 implementada (F2 cobros FIFO pendiente)".

- [x] **Step 3: Flip del `Estado:` del spec**

En `docs/superpowers/specs/2026-07-06-asistente-mini-app-design.md` cambiar:
```
**Estado:** Aprobado (decisiones D1–D4 resueltas el 2026-07-06, ver §15) — pendiente de implementación
```
por:
```
**Estado:** F0 y F1 implementados (fecha real) — ver docs/modulos/asistente-ia.md; F2–F5 pendientes
```

- [x] **Step 4: Commit**

```bash
git add docs/modulos/asistente-ia.md docs/README.md docs/superpowers/specs/2026-07-06-asistente-mini-app-design.md
git commit -m "docs(asistente): documentar mini-app F0+F1 y actualizar estados"
```

---

### Task 14: Verificación final

- [x] **Step 1: Suite completa del asistente**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Ai/`
Expected: PASS todos (incluye los 22 archivos existentes + el nuevo).

- [ ] **Step 2: Checklist manual de la mini-app** (con `vendor/bin/sail composer run dev`; en el navegador, viewport 390×844)

Como `admin@eltoro.test` (empresa) y luego `sucursal@eltoro.test` (sucursal), en `/el-toro/asistente`:
1. El sidebar muestra "Asistente" → abre la mini-app SIN sidebar; "Asistente clásico" sigue funcionando igual que antes.
2. Header: nombre del negocio (y sucursal para admin-sucursal) + botón "Salir al panel" → regresa al dashboard del rol.
3. Botón de historial (reloj) → bottom-sheet con sesiones; crear/cambiar sesión cierra el sheet.
4. Enviar "¿cuánto vendí hoy?" → card de ventas legible a 390px.
5. Dictado por voz y adjunto de imagen funcionan (draft de gasto confirmable de punta a punta: confirmar crea el gasto; segunda confirmación da 409).
6. Con cajero (`cajero@eltoro.test`) navegar a `/el-toro/asistente` → 403.
7. En ≥1024px: columna de sesiones visible a la izquierda, sheet desaparece.
8. El asistente clásico (`/el-toro/empresa/asistente`) se ve y comporta EXACTAMENTE igual que antes del refactor.

- [x] **Step 3: (Opcional, recomendado) suite completa**

Run: `vendor/bin/sail composer run test`
Expected: PASS — confirma que el refactor de controllers/rutas no rompió nada más.

---

## Self-review (hecho al redactar)

- **Cobertura del spec (F0+F1):** descomposición ✔ (Tasks 1-6), trait backend ✔ (Task 7), rutas+controller ✔ (Tasks 8-9), layout ✔ (Task 10), página mobile-first con bottom-sheet ✔ (Task 11), sidebar ✔ (Task 12), docs ✔ (Task 13), tests de acceso por rol/cross-user ✔ (Task 8). Fuera de alcance declarado: `CustomerGlobalPaymentService` (va con F2), modo simple y QuickActions (F4).
- **Consistencia de tipos/nombres:** `useAssistantChat(props, routes)` usado igual en Task 6 y Task 11; `chat.selectImage(file)` definido en Task 1 y consumido en Task 4; `stickRequest` definido en Task 1 y observado en Task 3; `inertiaPage()`/`indexRouteName()` idénticos en Tasks 7 y 9.
- **Sin placeholders:** todo el código está completo y copiable.
