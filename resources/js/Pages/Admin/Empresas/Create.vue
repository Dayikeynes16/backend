<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const form = useForm({
    name: '',
    slug: '',
    rfc: '',
    address: '',
    phone: '',
    max_branches: 1,
    max_users: 5,
    max_sales_per_branch_month: 500,
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

watch(() => form.name, (name) => {
    form.slug = name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
});

const recommendation = computed(() => {
    const b = form.max_branches;
    if (b >= 10) return { text: 'Con 10+ sucursales recomendamos al menos 30 usuarios.', min: 30 };
    if (b >= 5) return { text: 'Con 5+ sucursales recomendamos al menos 15 usuarios.', min: 15 };
    if (b >= 2) return { text: 'Con 2+ sucursales recomendamos al menos 5 usuarios.', min: 5 };
    return null;
});

const showRecommendation = computed(() => {
    return recommendation.value && form.max_users < recommendation.value.min;
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
                <Link :href="route('admin.empresas.index')" class="text-gray-400 transition hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-semibold text-gray-900">Nueva Empresa</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6">
            <!-- 1. Información General -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Informacion General</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Datos de identificacion de la empresa.</p>
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
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-xs text-gray-400">app.com/</span>
                                <input id="slug" v-model="form.slug" type="text" required class="block w-full min-w-0 rounded-none rounded-r-md border-gray-300 text-sm focus:border-red-300 focus:ring-red-200" />
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
            </section>

            <!-- 2. Configuración SaaS -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Configuracion SaaS</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Limites de uso de la empresa dentro de la plataforma.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-3">
                        <div>
                            <InputLabel for="max_branches" value="Max. sucursales" />
                            <TextInput id="max_branches" v-model="form.max_branches" type="number" min="1" max="100" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_branches" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_users" value="Max. usuarios" />
                            <TextInput id="max_users" v-model="form.max_users" type="number" min="1" max="500" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_users" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_sales_per_branch_month" value="Max. ventas/sucursal/30d" />
                            <TextInput id="max_sales_per_branch_month" v-model="form.max_sales_per_branch_month" type="number" min="1" max="10000" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_sales_per_branch_month" class="mt-1" />
                        </div>
                    </div>

                    <!-- Recommendation -->
                    <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0 -translate-y-1" leave-to-class="opacity-0 -translate-y-1">
                        <div v-if="showRecommendation" class="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                            <p class="text-xs text-blue-700">{{ recommendation.text }}</p>
                        </div>
                    </Transition>
                </div>
            </section>

            <!-- 3. Usuario Administrador -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Usuario Administrador</h2>
                    <p class="mt-0.5 text-xs text-gray-400">Se creara un usuario con rol <span class="font-semibold text-gray-600">admin-empresa</span> para esta empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="admin_name" value="Nombre del administrador" />
                            <TextInput id="admin_name" v-model="form.admin_name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.admin_name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="admin_email" value="Email" />
                            <TextInput id="admin_email" v-model="form.admin_email" type="email" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.admin_email" class="mt-1" />
                        </div>
                    </div>
                    <div class="sm:w-1/2">
                        <InputLabel for="admin_password" value="Contrasena" />
                        <TextInput id="admin_password" v-model="form.admin_password" type="password" class="mt-1 block w-full" required />
                        <p class="mt-1 text-[11px] text-gray-400">Minimo 8 caracteres.</p>
                        <InputError :message="form.errors.admin_password" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pb-8">
                <Link :href="route('admin.empresas.index')" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-red-500 disabled:opacity-50">
                    Crear Empresa
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
