<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AssistantAppLayout from '@/Layouts/AssistantAppLayout.vue';
import MessageThread from '@/Components/Asistente/chat/MessageThread.vue';
import ChatInputBar from '@/Components/Asistente/chat/ChatInputBar.vue';
import SessionsPanel from '@/Components/Asistente/chat/SessionsPanel.vue';
import SimpleHome from '@/Components/Asistente/app/SimpleHome.vue';
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
    speak: 'asistente.mensajes.voz', // TTS con OpenAI (2026-07-07)
};

const chat = useAssistantChat(props, routes);
const sessionsOpen = ref(false); // drawer móvil

// Sidebar de escritorio colapsable (preferencia persistida).
const SIDEBAR_PREF_KEY = 'assistant-sidebar-open';
const sidebarOpen = ref((localStorage.getItem(SIDEBAR_PREF_KEY) ?? '1') === '1');

function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value;
    localStorage.setItem(SIDEBAR_PREF_KEY, sidebarOpen.value ? '1' : '0');
}

// Modo simple (F4): pantalla de acciones grandes cuando el hilo está vacío.
// Preferencia persistida; "Hablar con el asistente" la apaga y el botón de
// inicio del header la restaura.
const SIMPLE_PREF_KEY = 'assistant-simple-home';
const simplePref = ref((localStorage.getItem(SIMPLE_PREF_KEY) ?? '1') === '1');

const showSimpleHome = computed(() => simplePref.value && chat.messages.length === 0 && !chat.sending);

function dismissSimpleHome() {
    simplePref.value = false;
    localStorage.setItem(SIMPLE_PREF_KEY, '0');
}

function goHome() {
    simplePref.value = true;
    localStorage.setItem(SIMPLE_PREF_KEY, '1');
    // Con mensajes en el hilo, "inicio" abre una conversación nueva.
    if (chat.messages.length > 0) chat.newSession();
}
</script>

<template>
    <Head title="Asistente" />
    <AssistantAppLayout>
        <template #header-actions>
            <!-- Voz: toggle grande y claro (autoplay de respuestas) -->
            <button
                v-if="chat.routes.speak"
                @click="chat.voiceAutoplay = !chat.voiceAutoplay"
                :title="chat.voiceAutoplay ? 'Voz activada — tocar para silenciar' : 'Voz desactivada — tocar para activar'"
                :aria-label="chat.voiceAutoplay ? 'Silenciar respuestas' : 'Leer respuestas en voz alta'"
                :aria-pressed="chat.voiceAutoplay"
                class="flex h-9 w-9 items-center justify-center rounded-lg transition-[transform,background-color,color] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] active:scale-95"
                :class="chat.voiceAutoplay ? 'bg-orange-100 text-orange-700' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-600'"
            >
                <svg v-if="chat.voiceAutoplay" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
            </button>
            <button
                @click="toggleSidebar"
                class="hidden h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition-colors duration-150 hover:bg-gray-100 hover:text-gray-700 lg:flex"
                :title="sidebarOpen ? 'Ocultar conversaciones' : 'Mostrar conversaciones'"
                :aria-label="sidebarOpen ? 'Ocultar conversaciones' : 'Mostrar conversaciones'"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5m-16.5 6.75h16.5m-16.5 6.75h16.5" />
                </svg>
            </button>
            <button
                v-if="!showSimpleHome"
                @click="goHome"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition-colors duration-150 hover:bg-gray-100 hover:text-gray-700"
                title="Inicio"
                aria-label="Inicio del asistente"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                </svg>
            </button>
            <button
                @click="sessionsOpen = true"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition-colors duration-150 hover:bg-gray-100 hover:text-gray-700 lg:hidden"
                title="Conversaciones"
                aria-label="Conversaciones anteriores"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </button>
        </template>

        <div class="flex min-h-0 w-full flex-1">
            <!-- Sidebar de conversaciones (desktop, colapsable) -->
            <aside
                class="hidden shrink-0 overflow-hidden border-r border-gray-100 bg-white transition-[width] duration-200 ease-out lg:block"
                :class="sidebarOpen ? 'lg:w-[264px]' : 'lg:w-0 lg:border-r-0'"
            >
                <div class="h-full w-[264px] overflow-y-auto p-3">
                    <SessionsPanel :chat="chat" />
                </div>
            </aside>

            <!-- Chat: protagonista, centrado con ancho de lectura -->
            <section class="flex min-h-0 flex-1 flex-col bg-white">
                <SimpleHome v-if="showSimpleHome" :chat="chat" @dismiss="dismissSimpleHome" />
                <MessageThread v-else :chat="chat" />

                <!-- Estado de voz: visible, animado y con Detener grande -->
                <Transition
                    enter-active-class="transition-[transform,opacity] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)]"
                    leave-active-class="transition-[transform,opacity] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"
                    enter-from-class="translate-y-2 opacity-0"
                    leave-to-class="translate-y-2 opacity-0"
                >
                    <div v-if="chat.loadingVoiceFor || chat.playingMessageId" class="px-3 pb-1 sm:px-4">
                        <div class="mx-auto flex w-full max-w-3xl items-center justify-between gap-3 rounded-2xl border border-orange-200/70 bg-orange-50 px-4 py-2.5">
                            <span class="flex min-w-0 items-center gap-2.5 text-sm font-medium text-orange-900">
                                <svg v-if="chat.loadingVoiceFor" class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.3" /><path d="M22 12a10 10 0 0 1-10 10" /></svg>
                                <span v-else class="eq flex h-4 items-end gap-[3px]" aria-hidden="true">
                                    <span class="w-[3px] rounded-full bg-orange-600"></span>
                                    <span class="w-[3px] rounded-full bg-orange-600" style="animation-delay: 0.18s"></span>
                                    <span class="w-[3px] rounded-full bg-orange-600" style="animation-delay: 0.36s"></span>
                                </span>
                                {{ chat.loadingVoiceFor ? 'Generando voz…' : 'Reproduciendo respuesta' }}
                            </span>
                            <button
                                type="button"
                                @click="chat.stopAudio()"
                                class="shrink-0 rounded-xl bg-white px-4 py-2 text-sm font-bold text-orange-700 shadow-sm transition-transform duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] active:scale-95"
                            >
                                Detener
                            </button>
                        </div>
                    </div>
                </Transition>

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
            enter-active-class="transition-transform duration-300"
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

<style scoped>
@keyframes eq-bounce {
    0%, 100% { height: 35%; }
    50% { height: 100%; }
}

.eq span { height: 35%; animation: eq-bounce 0.9s ease-in-out infinite; }

@media (prefers-reduced-motion: reduce) {
    .eq span { animation: none; height: 70%; }
}
</style>
