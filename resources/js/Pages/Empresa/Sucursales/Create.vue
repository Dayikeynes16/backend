<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPicker from '@/Components/MapPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });

const form = useForm({ name: '', phone: '', address: '', latitude: '', longitude: '', schedule: '' });

const submit = () => form.post(route('empresa.sucursales.store', props.tenant.slug));
</script>

<template>
    <Head title="Nueva Sucursal" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('empresa.sucursales.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Sucursales</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">Nueva Sucursal</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Informacion de la Sucursal</h2>
                    <p class="mt-1 text-sm text-gray-500">Datos de contacto y ubicacion del punto de venta.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required autofocus />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1.5 block w-full" placeholder="993-000-0000" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="address" value="Direccion" />
                        <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" placeholder="Av. Juarez 123, Centro, Villahermosa" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="schedule" value="Horario" />
                        <TextInput id="schedule" v-model="form.schedule" type="text" class="mt-1.5 block w-full" placeholder="Lun-Sab 7:00am - 8:00pm" />
                        <InputError :message="form.errors.schedule" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- Ubicación -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ubicacion</h2>
                    <p class="mt-1 text-sm text-gray-500">Selecciona la ubicacion de la sucursal en el mapa o ingresa las coordenadas manualmente.</p>
                </div>
                <div class="space-y-5 p-6">
                    <MapPicker v-model:latitude="form.latitude" v-model:longitude="form.longitude" />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="latitude" value="Latitud" />
                            <TextInput id="latitude" v-model="form.latitude" type="text" class="mt-1.5 block w-full" placeholder="17.9891" />
                            <InputError :message="form.errors.latitude" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="longitude" value="Longitud" />
                            <TextInput id="longitude" v-model="form.longitude" type="text" class="mt-1.5 block w-full" placeholder="-92.9475" />
                            <InputError :message="form.errors.longitude" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3 pb-8">
                <Link :href="route('empresa.sucursales.index', tenant.slug)" class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Crear Sucursal</button>
            </div>
        </form>
    </EmpresaLayout>
</template>
