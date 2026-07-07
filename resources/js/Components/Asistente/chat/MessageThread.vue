<script setup>
import { computed, nextTick, ref, watch } from 'vue';
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

const threadRef = ref(null);

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

watch(() => props.chat.messages, async () => {
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

// El composable pide scroll forzado al enviar mensaje o cambiar de sesión.
watch(() => props.chat.stickRequest, async () => {
    wasAtBottom = true;
    await nextTick();
    if (threadRef.value) threadRef.value.scrollTop = threadRef.value.scrollHeight;
});

// Mantenemos el flag al día con scrolls manuales del usuario.
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
                <QuickActions
                    v-if="item.id === lastAssistantId && quickKindFor(item)"
                    :kind="quickKindFor(item)"
                    :chat="chat"
                />
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
