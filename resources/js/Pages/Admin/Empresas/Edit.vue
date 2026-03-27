<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
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
    max_branches: props.empresa.max_branches || 1,
    status: props.empresa.status,
});

const submit = () => {
    form.put(route('admin.empresas.update', props.empresa.id));
};

const destroy = () => {
    if (confirm('¿Eliminar esta empresa permanentemente? Se borrarán todas sus sucursales, productos, ventas y usuarios. Esta acción no se puede deshacer.')) {
        router.delete(route('admin.empresas.destroy', props.empresa.id));
    }
};

const branchUsage = () => {
    const current = props.empresa.branches_count || 0;
    const max = props.empresa.max_branches || 1;
    const pct = Math.min((current / max) * 100, 100);
    return { current, max, pct };
};
</script>

<template>
    <Head :title="`Editar: ${empresa.name}`" />
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('admin.empresas.index')" class="text-gray-400 hover:text-gray-600">Empresas</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-semibold text-gray-900">{{ empresa.name }}</span>
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
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
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
                        <TextInput id="rfc" v-model="form.rfc" type="text" class="mt-1 block w-full sm:w-1/2" />
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
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Configuración SaaS -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">Configuracion SaaS</h2>
                    <p class="mt-0.5 text-sm text-gray-400">Limites, estado y capacidades de la empresa.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="max_branches" value="Maximo de sucursales" />
                            <TextInput id="max_branches" v-model="form.max_branches" type="number" min="1" max="100" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.max_branches" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="status" value="Estado" />
                            <select id="status" v-model="form.status"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-300 focus:ring-red-200">
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                            <InputError :message="form.errors.status" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Resumen (read-only) -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">Resumen</h2>
                    <p class="mt-0.5 text-sm text-gray-400">Estado actual de uso de la empresa.</p>
                </div>
                <div class="p-6">
                    <div class="grid gap-6 sm:grid-cols-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Sucursales</p>
                            <p class="mt-1 text-2xl font-bold text-gray-900">
                                {{ branchUsage().current }}
                                <span class="text-base font-normal text-gray-400">/ {{ branchUsage().max }}</span>
                            </p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="branchUsage().pct >= 90 ? 'bg-red-500' : branchUsage().pct >= 60 ? 'bg-orange-400' : 'bg-green-500'"
                                    :style="{ width: branchUsage().pct + '%' }"
                                />
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Usuarios</p>
                            <p class="mt-1 text-2xl font-bold text-gray-900">{{ empresa.users_count || 0 }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Creada</p>
                            <p class="mt-1 text-sm font-medium text-gray-600">
                                {{ new Date(empresa.created_at).toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50"
                    >
                        Guardar Cambios
                    </button>
                    <Link :href="route('admin.empresas.index')" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                        Cancelar
                    </Link>
                </div>
            </div>

            <!-- Danger zone -->
            <div class="rounded-xl border border-red-200 bg-red-50/50">
                <div class="px-6 py-4">
                    <h2 class="text-base font-semibold text-red-900">Zona de peligro</h2>
                    <p class="mt-0.5 text-sm text-red-600/70">Estas acciones son irreversibles.</p>
                </div>
                <div class="border-t border-red-200 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-red-900">Eliminar empresa</p>
                            <p class="text-xs text-red-600/70">Se eliminaran todas las sucursales, productos, ventas y usuarios.</p>
                        </div>
                        <button
                            type="button"
                            @click="destroy"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                        >
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </AdminLayout>
</template>
