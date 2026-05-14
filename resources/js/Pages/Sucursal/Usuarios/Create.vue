<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });

const form = useForm({ name: '', email: '', password: '' });

const submit = () => {
    form.post(route('sucursal.usuarios.store', props.tenant.slug));
};
</script>

<template>
    <Head title="Nuevo cajero" />
    <SucursalLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('sucursal.usuarios.index', tenant.slug)"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" title="Volver">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Nuevo cajero</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Crea una cuenta para que opere caja en esta sucursal.</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-2xl">
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <form @submit.prevent="submit" class="space-y-5 px-6 py-6">
                    <div>
                        <label for="name" class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre</label>
                        <input id="name" v-model="form.name" type="text" required maxlength="255" placeholder="Ej. Lucía Martínez"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-semibold text-gray-600">Email</label>
                        <input id="email" v-model="form.email" type="email" required placeholder="cajero@ejemplo.com"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-semibold text-gray-600">Contraseña</label>
                        <input id="password" v-model="form.password" type="password" required placeholder="Mínimo 8 caracteres"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>
                </form>

                <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                    <Link :href="route('sucursal.usuarios.index', tenant.slug)"
                        class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</Link>
                    <button type="button" @click="submit" :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        {{ form.processing ? 'Creando…' : 'Crear cajero' }}
                    </button>
                </div>
            </div>
        </div>
    </SucursalLayout>
</template>
