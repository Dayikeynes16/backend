<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    empresa: Object,
});

const form = useForm({
    name: props.empresa.name,
    slug: props.empresa.slug,
    rfc: props.empresa.rfc || '',
    address: props.empresa.address || '',
    phone: props.empresa.phone || '',
    status: props.empresa.status,
});

const submit = () => {
    form.put(route('admin.empresas.update', props.empresa.id));
};

const destroy = () => {
    if (confirm('¿Eliminar esta empresa? Se borrarán todos sus datos.')) {
        router.delete(route('admin.empresas.destroy', props.empresa.id));
    }
};
</script>

<template>
    <Head title="Editar Empresa" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Editar: {{ empresa.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <form @submit.prevent="submit" class="space-y-6 p-6">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="slug" value="Slug (URL)" />
                            <TextInput id="slug" v-model="form.slug" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.slug" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="rfc" value="RFC" />
                            <TextInput id="rfc" v-model="form.rfc" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.rfc" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="address" value="Direccion" />
                            <TextInput id="address" v-model="form.address" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.address" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.phone" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="status" value="Estado" />
                            <select
                                id="status"
                                v-model="form.status"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            >
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                            <InputError :message="form.errors.status" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                <Link :href="route('admin.empresas.index')" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                                    Cancelar
                                </Link>
                            </div>
                            <DangerButton @click="destroy" type="button">Eliminar</DangerButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
