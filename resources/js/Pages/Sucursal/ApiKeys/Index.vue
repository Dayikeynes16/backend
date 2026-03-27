<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    apiKeys: Array,
    tenant: Object,
    newKey: { type: String, default: null },
});

const showCreate = ref(false);
const copied = ref(false);

const form = useForm({ name: '' });

const submit = () => {
    form.post(route('sucursal.api-keys.store', props.tenant.slug), {
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
        },
    });
};

const revoke = (id) => {
    if (confirm('¿Revocar esta API Key? Las apps que la usen dejarán de funcionar.')) {
        router.delete(route('sucursal.api-keys.destroy', [props.tenant.slug, id]));
    }
};

const copyKey = () => {
    navigator.clipboard.writeText(props.newKey);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
};
</script>

<template>
    <Head title="API Keys" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">API Keys</h2>
                <PrimaryButton @click="showCreate = !showCreate">
                    {{ showCreate ? 'Cancelar' : 'Nueva API Key' }}
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-6">

                <!-- New key alert -->
                <div v-if="newKey" class="rounded-lg border border-green-300 bg-green-50 p-4 dark:border-green-700 dark:bg-green-900/30">
                    <p class="mb-2 text-sm font-semibold text-green-800 dark:text-green-200">
                        API Key generada exitosamente. Copia esta key ahora — no se mostrara de nuevo.
                    </p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded bg-green-100 px-3 py-2 font-mono text-sm text-green-900 dark:bg-green-800 dark:text-green-100">
                            {{ newKey }}
                        </code>
                        <button @click="copyKey" class="rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">
                            {{ copied ? 'Copiada' : 'Copiar' }}
                        </button>
                    </div>
                </div>

                <!-- Create form -->
                <div v-if="showCreate" class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <form @submit.prevent="submit" class="flex items-end gap-4 p-6">
                        <div class="flex-1">
                            <InputLabel for="name" value="Nombre de la key (ej: Kiosco principal)" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>
                        <PrimaryButton :disabled="form.processing">Generar</PrimaryButton>
                    </form>
                </div>

                <!-- Keys list -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Nombre</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Prefijo</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Estado</th>
                                    <th class="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-200">Ultimo uso</th>
                                    <th class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-gray-200">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr v-for="key in apiKeys" :key="key.id">
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-200">{{ key.name }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <code class="rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-gray-700 dark:text-gray-300">{{ key.prefix }}...</code>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span :class="key.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-semibold">
                                            {{ key.status === 'active' ? 'Activa' : 'Revocada' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ key.last_used_at || 'Nunca' }}</td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        <DangerButton v-if="key.status === 'active'" @click="revoke(key.id)" class="text-xs">
                                            Revocar
                                        </DangerButton>
                                    </td>
                                </tr>
                                <tr v-if="apiKeys.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No hay API Keys. Crea una para conectar tu app externa.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
