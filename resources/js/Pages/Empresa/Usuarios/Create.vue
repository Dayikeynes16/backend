<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ sucursales: Array, tenant: Object });

const form = useForm({ name: '', email: '', password: '', role: 'admin-sucursal', branch_id: '' });

const submit = () => {
    form.post(route('empresa.usuarios.store', props.tenant.slug));
};
</script>

<template>
    <Head title="Nuevo Usuario" />
    <EmpresaLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Nuevo Usuario</h2>
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
                            <InputLabel for="email" value="Email" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.email" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="password" value="Contrasena" />
                            <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.password" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="role" value="Rol" />
                            <select id="role" v-model="form.role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                <option value="admin-sucursal">Admin Sucursal</option>
                                <option value="cajero">Cajero</option>
                            </select>
                            <InputError :message="form.errors.role" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="branch_id" value="Sucursal" />
                            <select id="branch_id" v-model="form.branch_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" required>
                                <option value="" disabled>Seleccionar sucursal</option>
                                <option v-for="s in sucursales" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <InputError :message="form.errors.branch_id" class="mt-2" />
                        </div>
                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Crear Usuario</PrimaryButton>
                            <Link :href="route('empresa.usuarios.index', tenant.slug)" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Cancelar</Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </EmpresaLayout>
</template>
