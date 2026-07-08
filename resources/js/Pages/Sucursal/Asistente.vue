<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import AsistenteChat from '@/Components/Asistente/AsistenteChat.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    sessions: { type: Array, default: () => [] },
    activeSessionId: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    budget: { type: Object, default: () => ({ remaining_cents: 0, cap_cents: 0 }) },
});

const routes = {
    index: 'sucursal.asistente',
    createSession: 'sucursal.asistente.sesiones.store',
    sendMessage: 'sucursal.asistente.mensajes.store',
    transcribe: 'sucursal.asistente.transcribir',
    draftConfirm: 'sucursal.asistente.drafts.confirm',
    draftCancel: 'sucursal.asistente.drafts.cancel',
    speak: 'sucursal.asistente.mensajes.voz', // TTS con OpenAI (2026-07-07)
};
</script>

<template>
    <Head title="Asistente" />
    <SucursalLayout>
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
    </SucursalLayout>
</template>
