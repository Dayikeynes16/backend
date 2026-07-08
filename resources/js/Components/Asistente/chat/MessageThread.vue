<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import ToolResultCard from './ToolResultCard.vue';
import QuickActions from '../app/QuickActions.vue';

const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

// Quick actions solo bajo la ÚLTIMA respuesta del asistente (no en el historial).
const lastAssistantId = computed(() => {
    const items = props.chat.renderItems;
    for (let i = items.length - 1; i >= 0; i--) {
        if (items[i].kind === 'assistant') return items[i].id;
    }
    return null;
});

const quickKindFor = (item) =>
    item.cards?.find((c) => c.kind !== 'assistant_draft' && c.kind !== 'unknown')?.kind || null;

// El modelo responde con negritas markdown (**texto**). Escapamos HTML primero
// (nunca confiar en la salida del modelo) y solo entonces convertimos ** a
// <strong> — sin ningún otro markdown.
function formatContent(text) {
    const escaped = String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
}

// ── Estado "pensando" con texto rotativo (no sabemos qué tool corre — la
// respuesta llega completa — pero la progresión comunica actividad real).
const THINKING_STEPS = ['Pensando…', 'Consultando la información…', 'Preparando la respuesta…'];
const thinkingStep = ref(0);
let thinkingTimer = null;

watch(() => props.chat.sending, (sending) => {
    clearInterval(thinkingTimer);
    thinkingStep.value = 0;
    if (sending) {
        thinkingTimer = setInterval(() => {
            thinkingStep.value = Math.min(thinkingStep.value + 1, THINKING_STEPS.length - 1);
        }, 2600);
    }
});

onBeforeUnmount(() => clearInterval(thinkingTimer));

// ── Scroll: pegado al fondo cuando el usuario está al final; si sube a leer,
// no lo movemos y aparece el botón flotante "ir al final".
const threadRef = ref(null);
const STICK_TOLERANCE_PX = 96;
const atBottom = ref(true);

const isAtBottom = () => {
    const el = threadRef.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight <= STICK_TOLERANCE_PX;
};

const scrollToBottom = (smooth = true) => {
    const el = threadRef.value;
    if (!el) return;
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
};

watch(() => props.chat.messages, async () => {
    // Captura ANTES del flush del DOM: si estábamos al fondo, seguimos al fondo.
    const shouldStick = atBottom.value;
    await nextTick();
    if (shouldStick) scrollToBottom();
    atBottom.value = isAtBottom();
}, { deep: true });

// El composable pide scroll forzado al enviar mensaje o cambiar de sesión.
watch(() => props.chat.stickRequest, async () => {
    atBottom.value = true;
    await nextTick();
    // Al cambiar de sesión (hilo completo nuevo) el salto instantáneo se siente
    // mejor que ver pasar toda la conversación.
    scrollToBottom(props.chat.sending);
});

const onThreadScroll = () => {
    atBottom.value = isAtBottom();
};

const showScrollButton = computed(() => !atBottom.value && props.chat.messages.length > 0);
</script>

<template>
    <div class="relative flex min-h-0 flex-1 flex-col">
        <div ref="threadRef" @scroll.passive="onThreadScroll" class="flex-1 overflow-y-auto px-4 py-5 sm:px-5">
            <div class="mx-auto w-full max-w-3xl space-y-1">
                <div v-if="!chat.activeSessionId" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
                    <p class="mb-2">No hay conversación activa.</p>
                    <button @click="chat.newSession()" class="font-semibold text-orange-700 underline-offset-2 hover:underline">
                        Crear una nueva
                    </button>
                </div>

                <!-- Estado vacío: saludo + sugerencias que se envían con un tap -->
                <div v-else-if="!chat.messages.length" class="msg-in flex min-h-full flex-col justify-center py-6">
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-red-600 text-white shadow-sm">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.847-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.091L15.75 12l-2.847.813a4.5 4.5 0 0 0-3.09 3.091ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 0 0 2.456-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                    </div>
                    <p class="mt-3 text-center text-base font-semibold text-gray-900">¿En qué te ayudo hoy?</p>
                    <p class="mx-auto mt-1 max-w-sm text-center text-sm text-gray-500">
                        Pregúntame por tus ventas, gastos, clientes o proveedores — o registra algo con texto, voz o una foto.
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <button
                            v-for="(p, i) in chat.examplePrompts"
                            :key="i"
                            @click="chat.inputText = p; chat.send()"
                            class="rounded-full border border-gray-200 bg-white px-3.5 py-2 text-xs font-medium text-gray-700 transition-colors duration-150 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-900"
                        >
                            {{ p }}
                        </button>
                    </div>
                </div>

                <template v-for="item in chat.renderItems" :key="`${item.kind}-${item.id}`">
                    <!-- Usuario -->
                    <div v-if="item.kind === 'user'" class="msg-in flex justify-end pt-4">
                        <div class="max-w-[75%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-gradient-to-br from-orange-500 to-red-600 px-3.5 py-2 text-[15px] leading-relaxed text-white">
                            {{ item.message.content }}
                        </div>
                    </div>

                    <!-- Asistente: presentación ligera con avatar, sin burbuja pesada -->
                    <div v-else-if="item.kind === 'assistant'" class="msg-in flex gap-2.5 pt-4">
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-red-600 text-white" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.847-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.091L15.75 12l-2.847.813a4.5 4.5 0 0 0-3.09 3.091Z" /></svg>
                        </span>
                        <div class="min-w-0 flex-1 space-y-2.5">
                            <div class="flex items-end gap-2">
                                <div
                                    class="max-w-[90%] whitespace-pre-wrap rounded-2xl rounded-tl-md bg-gray-100/80 px-3.5 py-2.5 text-[15px] leading-relaxed text-gray-800 [&_strong]:font-semibold [&_strong]:text-gray-900"
                                    v-html="formatContent(item.message.content)"
                                />
                                <button
                                    v-if="chat.routes.speak && item.message.id > 0"
                                    type="button"
                                    @click="chat.playMessage(item.message)"
                                    :disabled="chat.loadingVoiceFor === item.message.id"
                                    :title="chat.playingMessageId === item.message.id ? 'Detener' : 'Escuchar respuesta'"
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-400 transition-colors duration-150 hover:border-orange-300 hover:text-orange-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <svg v-if="chat.loadingVoiceFor === item.message.id" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.3" /><path d="M22 12a10 10 0 0 1-10 10" /></svg>
                                    <svg v-else-if="chat.playingMessageId === item.message.id" class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="4" height="12" rx="1" /><rect x="14" y="6" width="4" height="12" rx="1" /></svg>
                                    <svg v-else class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
                                </button>
                            </div>
                            <ToolResultCard v-for="c in item.cards" :key="c.id" :card="c" :routes="chat.routes" class="msg-in" />
                            <QuickActions
                                v-if="item.id === lastAssistantId && quickKindFor(item)"
                                :kind="quickKindFor(item)"
                                :chat="chat"
                            />
                        </div>
                    </div>

                    <div v-else-if="item.kind === 'orphan_cards'" class="msg-in space-y-2.5 pl-9 pt-4">
                        <ToolResultCard v-for="c in item.cards" :key="c.id" :card="c" :routes="chat.routes" />
                    </div>

                    <div v-else-if="item.kind === 'tool_error'" class="msg-in flex pl-9 pt-4">
                        <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs text-red-800">
                            Acción {{ item.message.tool_name }} rechazada: {{ item.message.tool_result?.message || item.message.tool_status }}
                        </div>
                    </div>
                </template>

                <!-- Indicador de actividad: parte de la conversación, bajo el último mensaje -->
                <div v-if="chat.sending" class="msg-in flex items-center gap-2.5 pt-4" role="status">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-red-600 text-white" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.847-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.091L15.75 12l-2.847.813a4.5 4.5 0 0 0-3.09 3.091Z" /></svg>
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-sm text-gray-500">
                        <span class="inline-flex gap-1" aria-hidden="true">
                            <span class="dot h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                            <span class="dot h-1.5 w-1.5 rounded-full bg-gray-400" style="animation-delay: 0.15s"></span>
                            <span class="dot h-1.5 w-1.5 rounded-full bg-gray-400" style="animation-delay: 0.3s"></span>
                        </span>
                        {{ THINKING_STEPS[thinkingStep] }}
                    </span>
                </div>
                <div class="h-2" aria-hidden="true"></div>
            </div>
        </div>

        <!-- Botón flotante "ir al final" -->
        <Transition
            enter-active-class="transition duration-150 ease-out"
            leave-active-class="transition duration-150 ease-in"
            enter-from-class="translate-y-2 opacity-0"
            leave-to-class="translate-y-2 opacity-0"
        >
            <button
                v-if="showScrollButton"
                type="button"
                @click="scrollToBottom()"
                aria-label="Ir al mensaje más reciente"
                class="absolute bottom-4 left-1/2 z-10 flex h-9 w-9 -translate-x-1/2 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 shadow-md transition-colors duration-150 hover:border-orange-300 hover:text-orange-700"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" /></svg>
            </button>
        </Transition>
    </div>
</template>

<style scoped>
@keyframes msg-in {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes dot-pulse {
    0%, 60%, 100% { opacity: 0.35; transform: translateY(0); }
    30% { opacity: 1; transform: translateY(-2px); }
}

.msg-in { animation: msg-in 0.22s ease-out both; }
.dot { animation: dot-pulse 1.2s ease-in-out infinite; }

@media (prefers-reduced-motion: reduce) {
    .msg-in { animation: none; }
    .dot { animation: none; opacity: 0.6; }
}
</style>
