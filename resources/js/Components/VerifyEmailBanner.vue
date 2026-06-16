<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

// Per-session dismissal (resets on logout / new tab session)
const dismissedKey = 'verify-email-banner-dismissed';
const dismissed = ref(typeof window !== 'undefined' && window.sessionStorage?.getItem(dismissedKey) === '1');
const sent = ref(false);
const sending = ref(false);

const show = computed(() => {
    const user = page.props.auth?.user;
    if (!user || user.email_verified_at) return false;
    return !dismissed.value;
});

const resend = () => {
    if (sending.value || sent.value) return;
    sending.value = true;
    router.post(route('verification.send'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { sent.value = true; },
        onFinish: () => { sending.value = false; },
    });
};

const dismiss = () => {
    dismissed.value = true;
    window.sessionStorage?.setItem(dismissedKey, '1');
};
</script>

<template>
    <div v-if="show"
         class="border-b border-amber-200 bg-gradient-to-r from-amber-50 via-amber-50 to-orange-50">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-8">
            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
            </div>
            <p class="flex-1 text-sm text-amber-900">
                <span class="font-semibold">Verifica tu correo.</span>
                <span class="hidden sm:inline"> Te enviamos un enlace para confirmar tu dirección. Es opcional, pero protege tu cuenta.</span>
            </p>
            <button v-if="!sent" type="button" @click="resend" :disabled="sending"
                    class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-50">
                {{ sending ? 'Enviando…' : 'Reenviar enlace' }}
            </button>
            <span v-else class="rounded-md bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                ¡Enviado! Revisá tu correo.
            </span>
            <button type="button" @click="dismiss" class="text-amber-700 transition hover:text-amber-900" title="Ocultar">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>
</template>
