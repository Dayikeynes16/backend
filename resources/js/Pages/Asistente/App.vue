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
    // TTS (ElevenLabs) deshabilitado — si se reactiva, agregar speak + trait en el controller.
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
