<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });

const form = useForm({ name: '', description: '', price: '', unit_type: 'kg' });

const submit = () => {
    form.post(route('sucursal.productos.store', props.tenant.slug));
};
</script>

<template>
    <Head title="Nuevo Producto" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Nuevo Producto</h2>
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
                            <InputLabel for="description" value="Descripcion" />
                            <textarea id="description" v-model="form.description" rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" />
                            <InputError :message="form.errors.description" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="unit_type" value="Tipo de unidad" />
                            <select id="unit_type" v-model="form.unit_type"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                <option value="kg">Kilogramo (venta a granel)</option>
                                <option value="piece">Pieza (precio fijo)</option>
                                <option value="cut">Corte (bistec, chuleta, etc.)</option>
                            </select>
                            <InputError :message="form.errors.unit_type" class="mt-2" />
                        </div>
                        <div>
                            <InputLabel for="price" :value="form.unit_type === 'kg' ? 'Precio por kg' : 'Precio por unidad'" />
                            <TextInput id="price" v-model="form.price" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.price" class="mt-2" />
                        </div>
                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Crear Producto</PrimaryButton>
                            <Link :href="route('sucursal.productos.index', tenant.slug)" class="text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Cancelar</Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
