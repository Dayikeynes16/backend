<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({ usuario: Object, sucursales: Array, tenant: Object });

const form = useForm({
    name: props.usuario.name,
    email: props.usuario.email,
    password: '',
    role: props.usuario.roles?.[0]?.name || 'cajero',
    branch_id: props.usuario.branch_id || '',
});

const submit = () => form.put(route('empresa.usuarios.update', [props.tenant.slug, props.usuario.id]));

const destroy = () => {
    if (confirm('¿Eliminar este usuario?')) {
        router.delete(route('empresa.usuarios.destroy', [props.tenant.slug, props.usuario.id]));
    }
};
</script>

<template>
    <Head :title="`Editar: ${usuario.name}`" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('empresa.usuarios.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Usuarios</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">{{ usuario.name }}</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Editar Usuario</h2>
                    <p class="mt-1 text-sm text-gray-500">Modifica los datos, rol o sucursal del usuario.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre completo" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="email" value="Email" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.email" class="mt-1" />
                        </div>
                    </div>
                    <div class="sm:w-1/2">
                        <InputLabel for="password" value="Contrasena" />
                        <TextInput id="password" v-model="form.password" type="password" class="mt-1.5 block w-full" />
                        <p class="mt-1 text-xs text-gray-400">Dejar vacio para no cambiar la contrasena actual.</p>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="role" value="Rol" />
                            <select id="role" v-model="form.role" class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="admin-sucursal">Admin Sucursal</option>
                                <option value="cajero">Cajero</option>
                            </select>
                            <InputError :message="form.errors.role" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="branch_id" value="Sucursal" />
                            <select id="branch_id" v-model="form.branch_id" required class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="" disabled>Seleccionar sucursal</option>
                                <option v-for="s in sucursales" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <InputError :message="form.errors.branch_id" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('empresa.usuarios.index', tenant.slug)" class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Guardar Cambios</button>
            </div>

            <section class="rounded-xl border-2 border-red-200 bg-red-50">
                <div class="flex items-center justify-between px-6 py-5">
                    <div>
                        <p class="text-sm font-semibold text-red-900">Eliminar usuario</p>
                        <p class="mt-0.5 text-xs text-red-600/70">Esta accion no se puede deshacer.</p>
                    </div>
                    <button type="button" @click="destroy" class="rounded-lg border-2 border-red-300 bg-white px-5 py-2 text-sm font-bold text-red-700 transition hover:border-red-400 hover:bg-red-50">Eliminar</button>
                </div>
            </section>

            <div class="h-6"></div>
        </form>
    </EmpresaLayout>
</template>
