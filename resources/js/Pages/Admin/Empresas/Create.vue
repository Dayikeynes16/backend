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
    const b = Number(form.max_branches);
    if (b >= 10) return { text: 'Con 10 o mas sucursales recomendamos configurar al menos 30 usuarios para cubrir administradores y cajeros de cada punto de venta.', min: 30 };
    if (b >= 5) return { text: 'Con 5 o mas sucursales recomendamos al menos 15 usuarios para una operacion fluida.', min: 15 };
    if (b >= 2) return { text: 'Con 2 o mas sucursales recomendamos al menos 5 usuarios para cubrir cada punto de venta.', min: 5 };
    return null;
});

const showRecommendation = computed(() => recommendation.value && Number(form.max_users) < recommendation.value.min);

const submit = () => form.post(route('admin.empresas.store'));
</script>

<template>
    <Head title="Nueva Empresa" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('admin.empresas.index')" class="text-gray-400 transition hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">Nueva Empresa</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <!-- 1. Información General -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Informacion General</h2>
                    <p class="mt-1 text-sm text-gray-500">Datos basicos de identificacion de la empresa dentro del sistema.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre de la empresa" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required autofocus />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="slug" value="Slug (URL)" />
                            <div class="mt-1.5 flex rounded-md shadow-sm">
                                <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-400">app.com/</span>
                                <input id="slug" v-model="form.slug" type="text" required class="block w-full min-w-0 rounded-none rounded-r-md border-gray-300 text-sm focus:border-red-400 focus:ring-red-300" />
                            </div>
                            <InputError :message="form.errors.slug" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="rfc" value="RFC" />
                        <TextInput id="rfc" v-model="form.rfc" type="text" class="mt-1.5 block w-full sm:w-1/2" placeholder="XAXX010101000" />
                        <InputError :message="form.errors.rfc" class="mt-1" />
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="address" value="Direccion" />
                            <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" />
                            <InputError :message="form.errors.address" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1.5 block w-full" placeholder="993-000-0000" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2. Configuración SaaS -->
            <section class="rounded-xl border-l-4 border-orange-500 bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Configuracion SaaS</h2>
                    <p class="mt-1 text-sm text-gray-500">Define los limites de uso de esta empresa dentro de la plataforma. Estos valores se pueden modificar despues.</p>
                </div>
                <div class="space-y-6 p-6">
                    <div class="grid gap-6 sm:grid-cols-3">
                        <div>
                            <InputLabel for="max_branches" value="Maximo de sucursales" />
                            <TextInput id="max_branches" v-model="form.max_branches" type="number" min="1" max="100" class="mt-1.5 block w-full" required />
                            <p class="mt-1.5 text-xs leading-relaxed text-gray-400">Cantidad maxima de sucursales que esta empresa puede crear dentro del sistema.</p>
                            <InputError :message="form.errors.max_branches" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_users" value="Maximo de usuarios" />
                            <TextInput id="max_users" v-model="form.max_users" type="number" min="1" max="500" class="mt-1.5 block w-full" required />
                            <p class="mt-1.5 text-xs leading-relaxed text-gray-400">Total de usuarios permitidos incluyendo administradores, operadores y cajeros.</p>
                            <InputError :message="form.errors.max_users" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="max_sales_per_branch_month" value="Max. ventas / sucursal / 30 dias" />
                            <TextInput id="max_sales_per_branch_month" v-model="form.max_sales_per_branch_month" type="number" min="1" max="10000" class="mt-1.5 block w-full" required />
                            <p class="mt-1.5 text-xs leading-relaxed text-gray-400">Limite de ventas completadas por sucursal en un periodo de 30 dias.</p>
                            <InputError :message="form.errors.max_sales_per_branch_month" class="mt-1" />
                        </div>
                    </div>

                    <!-- Recommendation -->
                    <Transition enter-active-class="transition duration-300" leave-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-2" leave-to-class="opacity-0 -translate-y-2">
                        <div v-if="showRecommendation" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            <div>
                                <p class="text-sm font-semibold text-amber-800">Recomendacion</p>
                                <p class="mt-0.5 text-sm leading-relaxed text-amber-700">{{ recommendation.text }}</p>
                            </div>
                        </div>
                    </Transition>
                </div>
            </section>

            <!-- 3. Usuario Administrador -->
            <section class="rounded-xl border-l-4 border-red-500 bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Usuario Administrador</h2>
                    <p class="mt-1 text-sm text-gray-500">Se creara automaticamente un usuario con rol <span class="font-semibold text-gray-700">admin-empresa</span> que podra gestionar sucursales y usuarios de esta empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="admin_name" value="Nombre del administrador" />
                            <TextInput id="admin_name" v-model="form.admin_name" type="text" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.admin_name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="admin_email" value="Email" />
                            <TextInput id="admin_email" v-model="form.admin_email" type="email" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.admin_email" class="mt-1" />
                        </div>
                    </div>
                    <div class="sm:w-1/2">
                        <InputLabel for="admin_password" value="Contrasena" />
                        <TextInput id="admin_password" v-model="form.admin_password" type="password" class="mt-1.5 block w-full" required />
                        <p class="mt-1.5 text-xs text-gray-400">Minimo 8 caracteres. El administrador podra cambiarla despues.</p>
                        <InputError :message="form.errors.admin_password" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pb-8">
                <Link :href="route('admin.empresas.index')" class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                    Crear Empresa
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
