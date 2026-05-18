<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import AsistenteChat from '@/Components/Asistente/AsistenteChat.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    sessions: { type: Array, default: () => [] },
    activeSessionId: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    budget: { type: Object, default: () => ({ remaining_cents: 0, cap_cents: 0 }) },
});

const routes = {
    index: 'empresa.asistente',
    createSession: 'empresa.asistente.sesiones.store',
    sendMessage: 'empresa.asistente.mensajes.store',
    transcribe: 'empresa.asistente.transcribir',
    // TTS (ElevenLabs) deshabilitado 2026-05-18 — voz no satisfactoria.
    // Re-habilitar agregando: speak: 'empresa.asistente.mensajes.voz',
};
</script>

<template>
    <Head title="Asistente" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-bold text-gray-900">Asistente</h1>
                <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-bold text-orange-800">Beta</span>
            </div>
        </template>

        <AsistenteChat
            :sessions="sessions"
            :active-session-id="activeSessionId"
            :messages="messages"
            :budget="budget"
            :routes="routes"
        />
    </EmpresaLayout>
</template>
