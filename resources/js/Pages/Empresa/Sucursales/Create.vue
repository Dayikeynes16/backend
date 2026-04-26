<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPickerPro from '@/Components/MapPickerPro.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ tenant: Object });

// schedule legacy se autogenera en el backend desde `hours` al editar la
// sucursal después de crearla. El horario detallado se configura en Edit.
const form = useForm({ name: '', phone: '', address: '', latitude: '', longitude: '' });

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
                        <InputLabel for="address" value="Dirección" />
                        <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" placeholder="Av. Juárez 123, Centro, Villahermosa" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <p class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700 ring-1 ring-inset ring-blue-200">
                        💡 El <strong>horario detallado</strong> y los <strong>pedidos en línea</strong> los configuras al editar la sucursal una vez creada.
                    </p>
                </div>
            </section>

            <!-- Ubicación -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ubicación</h2>
                    <p class="mt-1 text-sm text-gray-500">Coloca el pin en la entrada de la sucursal y confírmalo.</p>
                </div>
                <div class="space-y-5 p-6">
                    <MapPickerPro
                        :latitude="form.latitude"
                        :longitude="form.longitude"
                        @confirmed="(lat, lng) => { form.latitude = lat; form.longitude = lng; }"
                        @address-suggested="(addr) => { if (!form.address) form.address = addr; }" />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="latitude" value="Latitud" />
                            <TextInput id="latitude" v-model="form.latitude" type="text" class="mt-1.5 block w-full font-mono text-sm tabular-nums" placeholder="17.9891" />
                            <InputError :message="form.errors.latitude" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="longitude" value="Longitud" />
                            <TextInput id="longitude" v-model="form.longitude" type="text" class="mt-1.5 block w-full font-mono text-sm tabular-nums" placeholder="-92.9475" />
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
