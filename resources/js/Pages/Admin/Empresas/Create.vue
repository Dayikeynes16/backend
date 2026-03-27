<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const form = useForm({
    name: '',
    slug: '',
    rfc: '',
    address: '',
    phone: '',
    max_branches: 1,
});

// Auto-generate slug from name
watch(() => form.name, (name) => {
    form.slug = name
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
});

const submit = () => {
    form.post(route('admin.empresas.store'));
};
</script>

<template>
    <Head title="Nueva Empresa" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('admin.empresas.index')" class="text-gray-400 hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-semibold text-gray-900">Nueva Empresa</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <!-- Section: Información General -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">Informacion General</h2>
                    <p class="mt-0.5 text-sm text-gray-400">Datos de identificacion de la empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre de la empresa" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required autofocus />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="slug" value="Slug (URL)" />
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-400">app.com/</span>
                                <input id="slug" v-model="form.slug" type="text" required
                                    class="block w-full min-w-0 rounded-none rounded-r-md border-gray-300 text-sm focus:border-red-300 focus:ring-red-200" />
                            </div>
                            <InputError :message="form.errors.slug" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="rfc" value="RFC" />
                        <TextInput id="rfc" v-model="form.rfc" type="text" class="mt-1 block w-full sm:w-1/2" placeholder="XAXX010101000" />
                        <InputError :message="form.errors.rfc" class="mt-1" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="address" value="Direccion" />
                            <TextInput id="address" v-model="form.address" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.address" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" placeholder="993-000-0000" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Configuración SaaS -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">Configuracion SaaS</h2>
                    <p class="mt-0.5 text-sm text-gray-400">Limites y capacidades de la empresa dentro de la plataforma.</p>
                </div>
                <div class="p-6">
                    <div class="max-w-xs">
                        <InputLabel for="max_branches" value="Maximo de sucursales" />
                        <TextInput id="max_branches" v-model="form.max_branches" type="number" min="1" max="100" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-gray-400">Cantidad maxima de sucursales que esta empresa puede crear.</p>
                        <InputError :message="form.errors.max_branches" class="mt-1" />
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3">
                <Link :href="route('admin.empresas.index')" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                    Cancelar
                </Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50"
                >
                    Crear Empresa
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
