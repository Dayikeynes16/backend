<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({ sucursal: Object, tenant: Object });

const form = useForm({
    name: props.sucursal.name,
    phone: props.sucursal.phone || '',
    address: props.sucursal.address || '',
    latitude: props.sucursal.latitude || '',
    longitude: props.sucursal.longitude || '',
    schedule: props.sucursal.schedule || '',
    status: props.sucursal.status,
});

const submit = () => form.put(route('empresa.sucursales.update', [props.tenant.slug, props.sucursal.id]));

const destroy = () => {
    if (confirm('¿Eliminar esta sucursal? Se borraran todos sus productos, ventas y usuarios.')) {
        router.delete(route('empresa.sucursales.destroy', [props.tenant.slug, props.sucursal.id]));
    }
};

const roleBadge = (name) => ({
    'admin-sucursal': { label: 'Admin Sucursal', cls: 'bg-orange-50 text-orange-700 ring-orange-600/20' },
    'cajero': { label: 'Cajero', cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' },
}[name] || { label: name, cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' });
</script>

<template>
    <Head :title="`Editar: ${sucursal.name}`" />
    <EmpresaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <Link :href="route('empresa.sucursales.index', tenant.slug)" class="text-gray-400 transition hover:text-gray-600">Sucursales</Link>
                <svg class="h-4 w-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                <span class="font-bold text-gray-900">{{ sucursal.name }}</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-8">
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Informacion de la Sucursal</h2>
                    <p class="mt-1 text-sm text-gray-500">Datos de contacto, ubicacion y estado del punto de venta.</p>
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
                    <p class="text-xs text-gray-400">Coordenadas geograficas. Puedes obtenerlas desde Google Maps haciendo click derecho en la ubicacion.</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel for="schedule" value="Horario" />
                            <TextInput id="schedule" v-model="form.schedule" type="text" class="mt-1.5 block w-full" />
                            <InputError :message="form.errors.schedule" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="status" value="Estado" />
                            <select id="status" v-model="form.status" class="mt-1.5 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300">
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                            <InputError :message="form.errors.status" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Team -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Equipo de esta Sucursal</h2>
                    <p class="mt-1 text-sm text-gray-500">Usuarios asignados a este punto de venta.</p>
                </div>
                <div class="p-6">
                    <div v-if="!sucursal.users || sucursal.users.length === 0" class="rounded-xl border-2 border-dashed border-gray-200 px-6 py-10 text-center">
                        <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <p class="mt-3 text-sm font-medium text-gray-400">No hay usuarios asignados a esta sucursal.</p>
                    </div>
                    <div v-else class="space-y-2">
                        <div v-for="user in sucursal.users" :key="user.id" class="flex items-center gap-3 rounded-xl bg-gray-50 px-4 py-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-xs font-bold text-gray-600">{{ user.name.charAt(0) }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ user.name }}</p>
                                <p class="truncate text-xs text-gray-500">{{ user.email }}</p>
                            </div>
                            <span v-if="user.roles?.[0]" :class="roleBadge(user.roles[0].name).cls" class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ roleBadge(user.roles[0].name).label }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('empresa.sucursales.index', tenant.slug)" class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-100">Cancelar</Link>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Guardar Cambios</button>
            </div>

            <section class="rounded-xl border-2 border-red-200 bg-red-50">
                <div class="flex items-center justify-between px-6 py-5">
                    <div>
                        <p class="text-sm font-semibold text-red-900">Eliminar sucursal</p>
                        <p class="mt-0.5 text-xs text-red-600/70">Se eliminaran productos, ventas y usuarios asociados.</p>
                    </div>
                    <button type="button" @click="destroy" class="rounded-lg border-2 border-red-300 bg-white px-5 py-2 text-sm font-bold text-red-700 transition hover:border-red-400 hover:bg-red-50">Eliminar</button>
                </div>
            </section>

            <div class="h-6"></div>
        </form>

        <FlashToast />
    </EmpresaLayout>
</template>
