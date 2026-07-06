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
