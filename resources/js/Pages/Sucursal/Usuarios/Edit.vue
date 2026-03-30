<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({ usuario: Object, tenant: Object });

const form = useForm({
    name: props.usuario.name,
    email: props.usuario.email,
    password: '',
});

const submit = () => {
    form.put(route('sucursal.usuarios.update', [props.tenant.slug, props.usuario.id]));
};

const destroy = () => {
    if (confirm('¿Eliminar este cajero?')) {
        router.delete(route('sucursal.usuarios.destroy', [props.tenant.slug, props.usuario.id]));
    }
};
</script>

<template>
    <Head title="Editar Cajero" />
    <SucursalLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Editar: {{ usuario.name }}</h2>
        </template>
        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit" class="space-y-6 p-6">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="email" value="Email" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.email" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="password" value="Contrasena (dejar vacio para no cambiar)" />
                            <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" />
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                <Link :href="route('sucursal.usuarios.index', tenant.slug)" class="text-sm text-gray-600 hover:text-gray-900">Cancelar</Link>
                            </div>
                            <DangerButton @click="destroy" type="button">Eliminar</DangerButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </SucursalLayout>
</template>
