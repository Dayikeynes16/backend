<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ usuario: Object, tenant: Object });

const form = useForm({
    name: props.usuario.name,
    email: props.usuario.email,
    password: '',
});

const submit = () => {
    form.put(route('sucursal.usuarios.update', [props.tenant.slug, props.usuario.id]));
};

const initialOf = (name) => (name || '?').trim().charAt(0).toUpperCase();
const initials = computed(() => initialOf(props.usuario.name));

const showDelete = ref(false);
const doDelete = () => {
    router.delete(route('sucursal.usuarios.destroy', [props.tenant.slug, props.usuario.id]), {
        onFinish: () => { showDelete.value = false; },
    });
};
</script>

<template>
    <Head title="Editar cajero" />
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
                    <h1 class="text-xl font-bold text-gray-900">Editar cajero</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Actualiza los datos o la contraseña de este cajero.</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-2xl">
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <!-- Encabezado con avatar -->
                <div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/40 px-6 py-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-red-500 text-lg font-bold text-white shadow-sm">
                        {{ initials }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-gray-900">{{ usuario.name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ usuario.email }}</p>
                    </div>
                    <span class="ml-auto rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Cajero</span>
                </div>

                <form @submit.prevent="submit" class="space-y-5 px-6 py-6">
                    <div>
                        <label for="name" class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre</label>
                        <input id="name" v-model="form.name" type="text" required maxlength="255"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-semibold text-gray-600">Email</label>
                        <input id="email" v-model="form.email" type="email" required
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-semibold text-gray-600">
                            Contraseña <span class="font-normal text-gray-400">· dejar vacío para no cambiarla</span>
                        </label>
                        <input id="password" v-model="form.password" type="password" placeholder="Nueva contraseña"
                            class="block w-full rounded-xl border-gray-200 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-red-400 focus:ring-red-300" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>
                </form>

                <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                    <button type="button" @click="showDelete = true"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        Eliminar
                    </button>
                    <div class="flex items-center gap-3">
                        <Link :href="route('sucursal.usuarios.index', tenant.slug)"
                            class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200">Cancelar</Link>
                        <button type="button" @click="submit" :disabled="form.processing"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog v-if="showDelete"
            title="Eliminar cajero"
            :message="`Vas a eliminar a ${usuario.name}. Esta acción no se puede deshacer.`"
            confirm-label="Eliminar"
            variant="danger"
            @confirm="doDelete"
            @cancel="showDelete = false" />

        <FlashToast />
    </SucursalLayout>
</template>
