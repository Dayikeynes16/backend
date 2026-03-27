<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    show: Boolean,
    user: Object,
    sendResetRoute: String,
    forceResetRoute: String,
});

const emit = defineEmits(['close']);

const mode = ref('choose');
const form = useForm({ password: '' });

watch(() => props.show, (val) => {
    if (val) {
        mode.value = 'choose';
        form.reset();
        form.clearErrors();
    }
});

const sendLink = () => {
    router.post(props.sendResetRoute, {}, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};

const forceReset = () => {
    form.post(props.forceResetRoute, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="emit('close')">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl ring-1 ring-gray-100" @click.stop>
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-base font-bold text-gray-900">Resetear contrasena</h3>
                    <button @click="emit('close')" class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <p class="mb-5 text-sm text-gray-500">
                    Usuario: <span class="font-semibold text-gray-900">{{ user?.name }}</span>
                    <span class="text-gray-400">({{ user?.email }})</span>
                </p>

                <!-- Choose mode -->
                <div v-if="mode === 'choose'" class="space-y-3">
                    <button @click="sendLink" class="flex w-full items-start gap-3 rounded-xl border border-gray-200 p-4 text-left transition hover:border-red-200 hover:bg-red-50/50">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50">
                            <svg class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Enviar enlace por correo</p>
                            <p class="mt-0.5 text-xs text-gray-500">El usuario recibira un enlace seguro para definir su nueva contrasena. Opcion mas segura.</p>
                        </div>
                    </button>

                    <button @click="mode = 'force'" class="flex w-full items-start gap-3 rounded-xl border border-gray-200 p-4 text-left transition hover:border-orange-200 hover:bg-orange-50/50">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-orange-50">
                            <svg class="h-5 w-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Contrasena temporal</p>
                            <p class="mt-0.5 text-xs text-gray-500">Define una contrasena temporal. El usuario debera cambiarla al iniciar sesion.</p>
                        </div>
                    </button>
                </div>

                <!-- Force reset form -->
                <div v-if="mode === 'force'" class="space-y-4">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                        <p class="text-xs text-amber-700">El usuario sera obligado a cambiar esta contrasena en su proximo inicio de sesion.</p>
                    </div>

                    <div>
                        <InputLabel for="temp_password" value="Contrasena temporal" />
                        <TextInput id="temp_password" v-model="form.password" type="password" class="mt-1.5 block w-full" required />
                        <p class="mt-1 text-xs text-gray-400">Minimo 8 caracteres.</p>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>

                    <div class="flex gap-3">
                        <button @click="mode = 'choose'" type="button" class="flex-1 rounded-lg border border-gray-200 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                            Volver
                        </button>
                        <button @click="forceReset" :disabled="form.processing || !form.password" type="button"
                            class="flex-1 rounded-lg bg-red-600 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50">
                            Asignar contrasena
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
