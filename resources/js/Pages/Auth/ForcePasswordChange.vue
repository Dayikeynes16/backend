<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.force-change.update'), {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Cambiar Contrasena" />

    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-md">
            <div class="rounded-2xl bg-white p-8 shadow-lg ring-1 ring-gray-100">
                <div class="mb-6 text-center">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-gradient-to-br from-red-600 to-orange-500 shadow-md">
                        <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </div>
                    <h1 class="text-xl font-bold text-gray-900">Cambio de contrasena requerido</h1>
                    <p class="mt-2 text-sm text-gray-500">Tu administrador ha solicitado que cambies tu contrasena antes de continuar.</p>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <InputLabel for="password" value="Nueva contrasena" />
                        <TextInput id="password" v-model="form.password" type="password" class="mt-1.5 block w-full" required autofocus />
                        <p class="mt-1 text-xs text-gray-400">Minimo 8 caracteres.</p>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="password_confirmation" value="Confirmar contrasena" />
                        <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1.5 block w-full" required />
                        <InputError :message="form.errors.password_confirmation" class="mt-1" />
                    </div>

                    <PrimaryButton :disabled="form.processing" class="w-full justify-center bg-red-600 hover:bg-red-700">
                        Cambiar Contrasena
                    </PrimaryButton>
                </form>
            </div>
        </div>
    </div>
</template>
