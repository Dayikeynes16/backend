<script setup>
const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

// `navigate` permite a la mini-app cerrar el bottom-sheet al crear/cambiar
// sesión; la página clásica simplemente ignora el evento.
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
