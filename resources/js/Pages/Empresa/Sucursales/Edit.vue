<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPicker from '@/Components/MapPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({ sucursal: Object, tenant: Object });

const DAY_LABELS = { mon: 'Lun', tue: 'Mar', wed: 'Mié', thu: 'Jue', fri: 'Vie', sat: 'Sáb', sun: 'Dom' };
const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const initialHours = DAY_KEYS.reduce((acc, key) => {
    const entry = props.sucursal.hours?.[key];
    acc[key] = entry ? { open: entry.open, close: entry.close } : { open: '', close: '' };
    return acc;
}, {});

const form = useForm({
    name: props.sucursal.name,
    phone: props.sucursal.phone || '',
    address: props.sucursal.address || '',
    latitude: props.sucursal.latitude || '',
    longitude: props.sucursal.longitude || '',
    schedule: props.sucursal.schedule || '',
    status: props.sucursal.status,
    online_ordering_enabled: !!props.sucursal.online_ordering_enabled,
    delivery_enabled: !!props.sucursal.delivery_enabled,
    pickup_enabled: props.sucursal.pickup_enabled !== undefined ? !!props.sucursal.pickup_enabled : true,
    delivery_tiers: Array.isArray(props.sucursal.delivery_tiers)
        ? props.sucursal.delivery_tiers.map(t => ({ max_km: t.max_km, fee: t.fee }))
        : [],
    min_order_amount: props.sucursal.min_order_amount ?? '',
    public_phone: props.sucursal.public_phone || '',
    hours: initialHours,
});

const addTier = () => {
    const last = form.delivery_tiers[form.delivery_tiers.length - 1];
    const nextKm = last ? Number(last.max_km || 0) + 2 : 2;
    form.delivery_tiers.push({ max_km: nextKm, fee: 50 });
};
const removeTier = (idx) => { form.delivery_tiers.splice(idx, 1); };

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

            <!-- Ubicación -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ubicacion</h2>
                    <p class="mt-1 text-sm text-gray-500">Selecciona la ubicacion en el mapa o ingresa las coordenadas.</p>
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

            <!-- Pedidos online -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Pedidos online</h2>
                        <p class="mt-1 text-sm text-gray-500">Menú público para que tus clientes hagan pedidos desde su celular y los reciban por WhatsApp.</p>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="checkbox" v-model="form.online_ordering_enabled" class="peer sr-only" />
                        <div class="relative h-6 w-11 rounded-full bg-gray-200 transition peer-checked:bg-red-600">
                            <div class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white transition peer-checked:translate-x-5"></div>
                        </div>
                        <span class="text-sm font-semibold text-gray-700">{{ form.online_ordering_enabled ? 'Activo' : 'Inactivo' }}</span>
                    </label>
                </div>

                <div v-if="form.online_ordering_enabled" class="space-y-6 p-6">
                    <!-- Modos de entrega -->
                    <div>
                        <InputLabel value="Modos de entrega" />
                        <div class="mt-1.5 grid gap-3 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:bg-gray-50">
                                <input type="checkbox" v-model="form.pickup_enabled" class="mt-0.5 rounded text-red-600" />
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">Recolección en sucursal</p>
                                    <p class="text-xs text-gray-500">El cliente pasa por su pedido.</p>
                                </div>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:bg-gray-50">
                                <input type="checkbox" v-model="form.delivery_enabled" class="mt-0.5 rounded text-red-600" />
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">Envío a domicilio</p>
                                    <p class="text-xs text-gray-500">Requiere ubicación y tarifas configuradas.</p>
                                </div>
                            </label>
                        </div>
                        <InputError :message="form.errors.delivery_enabled" class="mt-1" />
                    </div>

                    <!-- WhatsApp -->
                    <div>
                        <InputLabel for="public_phone" value="WhatsApp para recibir pedidos" />
                        <TextInput id="public_phone" v-model="form.public_phone" type="text" placeholder="+529931234567" class="mt-1.5 block w-full sm:w-1/2" />
                        <p class="mt-1 text-xs text-gray-400">Formato internacional (con país). Los pedidos llegarán a este número por mensaje con detalle completo.</p>
                        <InputError :message="form.errors.public_phone" class="mt-1" />
                    </div>

                    <!-- Pedido mínimo -->
                    <div>
                        <InputLabel for="min_order_amount" value="Pedido mínimo (opcional)" />
                        <div class="mt-1.5 flex items-center gap-2 sm:w-1/3">
                            <span class="text-sm text-gray-500">$</span>
                            <TextInput id="min_order_amount" v-model="form.min_order_amount" type="number" step="0.01" min="0" placeholder="300" class="block w-full" />
                        </div>
                        <InputError :message="form.errors.min_order_amount" class="mt-1" />
                    </div>

                    <!-- Tarifas de envío -->
                    <div v-if="form.delivery_enabled">
                        <div class="flex items-center justify-between">
                            <InputLabel value="Tarifas de envío por distancia" />
                            <button type="button" @click="addTier" class="inline-flex items-center gap-1 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-red-200 transition hover:bg-red-100">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                Agregar rango
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">Define cuánto cobrar por envío según distancia. Ordenados de cerca a lejos. Fuera del último rango no se entrega.</p>
                        <div v-if="form.delivery_tiers.length === 0" class="mt-3 rounded-xl border-2 border-dashed border-gray-200 px-6 py-6 text-center text-sm text-gray-400">
                            Sin rangos configurados. Agrega al menos uno.
                        </div>
                        <div v-else class="mt-3 space-y-2">
                            <div v-for="(tier, i) in form.delivery_tiers" :key="i" class="flex items-center gap-3 rounded-lg bg-gray-50 p-3">
                                <span class="text-xs font-semibold text-gray-400">#{{ i + 1 }}</span>
                                <div class="flex-1 grid grid-cols-2 gap-3">
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-500">Hasta (km)</p>
                                        <TextInput v-model="tier.max_km" type="number" step="0.1" min="0.1" class="mt-0.5 block w-full" />
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-500">Tarifa ($)</p>
                                        <TextInput v-model="tier.fee" type="number" step="0.01" min="0" class="mt-0.5 block w-full" />
                                    </div>
                                </div>
                                <button type="button" @click="removeTier(i)" class="rounded-full p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-500">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.delivery_tiers" class="mt-1" />
                    </div>

                    <!-- Horarios -->
                    <div>
                        <InputLabel value="Horarios (opcional)" />
                        <p class="mt-1 text-xs text-gray-400">Si configuras horarios, los pedidos web se bloquearán fuera de ellos. Deja un día vacío para cerrarlo.</p>
                        <div class="mt-3 space-y-2">
                            <div v-for="day in DAY_KEYS" :key="day" class="flex items-center gap-3 rounded-lg bg-gray-50 p-3">
                                <span class="w-12 text-sm font-semibold text-gray-700">{{ DAY_LABELS[day] }}</span>
                                <div class="flex-1 grid grid-cols-2 gap-3">
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-500">Abre</p>
                                        <input type="time" v-model="form.hours[day].open" class="mt-0.5 block w-full rounded-md border-gray-300 text-sm focus:border-red-400 focus:ring-red-300" />
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-medium text-gray-500">Cierra</p>
                                        <input type="time" v-model="form.hours[day].close" class="mt-0.5 block w-full rounded-md border-gray-300 text-sm focus:border-red-400 focus:ring-red-300" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- URL pública -->
                    <div class="rounded-xl border border-red-100 bg-red-50/40 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-red-700">URL pública</p>
                        <p class="mt-1 font-mono text-sm text-gray-800">/menu/{{ tenant.slug }}</p>
                        <p class="mt-1 text-xs text-gray-500">Comparte este link en redes, letreros o en tu WhatsApp Business.</p>
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
