<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPicker from '@/Components/MapPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ branch: Object, tenant: Object });

const form = useForm({
    name: props.branch.name,
    phone: props.branch.phone || '',
    address: props.branch.address || '',
    latitude: props.branch.latitude || '',
    longitude: props.branch.longitude || '',
    schedule: props.branch.schedule || '',
    payment_methods_enabled: props.branch.payment_methods_enabled || ['cash', 'card', 'transfer'],
});

const submit = () => form.put(route('sucursal.configuracion.update', props.tenant.slug));
</script>

<template>
    <Head title="Configuracion" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Configuracion</h1>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Datos de la Sucursal</h2>
                    <p class="mt-1 text-sm text-gray-500">Informacion de contacto y horario de este punto de venta.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Telefono" />
                            <TextInput id="phone" v-model="form.phone" type="text" class="mt-1.5 block w-full" />
                            <InputError :message="form.errors.phone" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="address" value="Direccion" />
                        <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="schedule" value="Horario" />
                        <TextInput id="schedule" v-model="form.schedule" type="text" class="mt-1.5 block w-full" placeholder="Lun-Sab 7:00am - 8:00pm" />
                        <InputError :message="form.errors.schedule" class="mt-1" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ubicacion</h2>
                    <p class="mt-1 text-sm text-gray-500">Mueve el mapa para posicionar la sucursal.</p>
                </div>
                <div class="space-y-5 p-6">
                    <MapPicker v-model:latitude="form.latitude" v-model:longitude="form.longitude" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="lat" value="Latitud" />
                            <TextInput id="lat" v-model="form.latitude" type="text" class="mt-1.5 block w-full" placeholder="17.9891" />
                        </div>
                        <div>
                            <InputLabel for="lng" value="Longitud" />
                            <TextInput id="lng" v-model="form.longitude" type="text" class="mt-1.5 block w-full" placeholder="-92.9475" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Payment Methods -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Metodos de Pago</h2>
                    <p class="mt-1 text-sm text-gray-500">Habilita o deshabilita los metodos de pago disponibles en esta sucursal.</p>
                </div>
                <div class="space-y-3 p-6">
                    <label v-for="method in [{id:'cash',label:'Efectivo',icon:'text-green-600'},{id:'card',label:'Tarjeta',icon:'text-blue-600'},{id:'transfer',label:'Transferencia',icon:'text-purple-600'}]"
                        :key="method.id" class="flex items-center justify-between rounded-xl p-4 ring-1 ring-gray-100 transition hover:bg-gray-50 cursor-pointer">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold" :class="method.icon">{{ method.label }}</span>
                        </div>
                        <input type="checkbox" :value="method.id" v-model="form.payment_methods_enabled"
                            class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                    </label>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Guardar Cambios</button>
            </div>
        </form>

        <FlashToast />
    </SucursalLayout>
</template>
