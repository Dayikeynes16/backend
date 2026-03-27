<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });

const form = useForm({
    name: props.tenant.name,
    phone: props.tenant.phone || '',
    address: props.tenant.address || '',
});

const submit = () => {
    form.put(route('empresa.configuracion.update', props.tenant.slug));
};
</script>

<template>
    <Head title="Configuracion" />
    <EmpresaLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Configuracion</h1>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-2xl space-y-8">
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Datos de la empresa</h2>
                    <p class="mt-1 text-sm text-gray-500">Informacion visible para los usuarios de tu empresa. Los limites SaaS solo pueden ser modificados por el administrador de la plataforma.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div>
                        <InputLabel for="name" value="Nombre de la empresa" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1.5 block w-full" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="address" value="Direccion" />
                            <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" />
                            <InputError :message="form.errors.address" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Read-only SaaS info -->
            <section class="rounded-xl bg-gray-50 shadow-sm ring-1 ring-gray-200">
                <div class="px-6 py-5">
                    <h2 class="text-base font-bold text-gray-700">Limites de tu plan</h2>
                    <p class="mt-1 text-sm text-gray-400">Estos valores son configurados por el administrador de la plataforma.</p>
                </div>
                <div class="border-t border-gray-200 px-6 py-4">
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-400">Max. sucursales</p>
                            <p class="mt-1 text-lg font-bold text-gray-600">{{ tenant.max_branches }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-400">Max. usuarios</p>
                            <p class="mt-1 text-lg font-bold text-gray-600">{{ tenant.max_users }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-400">Max. ventas/suc/30d</p>
                            <p class="mt-1 text-lg font-bold text-gray-600">{{ tenant.max_sales_per_branch_month }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">
                    Guardar Cambios
                </button>
            </div>
        </form>

        <FlashToast />
    </EmpresaLayout>
</template>
