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
