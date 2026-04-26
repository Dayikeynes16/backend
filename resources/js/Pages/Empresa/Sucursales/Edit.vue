<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPickerPro from '@/Components/MapPickerPro.vue';
import HoursEditor from '@/Components/HoursEditor.vue';
import PhoneFields from '@/Components/PhoneFields.vue';
import DeliveryTiersEditor from '@/Components/DeliveryTiersEditor.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ sucursal: Object, tenant: Object });

// Hours: el modelo en BD admite null por día. El componente HoursEditor
// también, así que pasamos el objeto tal cual viene (o {} si null).
const initialHours = props.sucursal.hours || {};

const form = useForm({
    name: props.sucursal.name,
    phone: props.sucursal.phone || '',
    address: props.sucursal.address || '',
    latitude: props.sucursal.latitude || '',
    longitude: props.sucursal.longitude || '',
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

const submit = () => form.put(route('empresa.sucursales.update', [props.tenant.slug, props.sucursal.id]));

// Banner de errores cruzados de validateOnlineOrderingConfig (backend).
const crossErrors = computed(() => {
    const errs = [];
    if (form.errors.public_phone) errs.push(form.errors.public_phone);
    if (form.errors.delivery_enabled) errs.push(form.errors.delivery_enabled);
    if (form.errors.latitude) errs.push(form.errors.latitude);
    if (form.errors.delivery_tiers && typeof form.errors.delivery_tiers === 'string') errs.push(form.errors.delivery_tiers);
    return errs;
});

const destroy = () => {
    if (confirm('¿Eliminar esta sucursal? Se borraran todos sus productos, ventas y usuarios.')) {
        router.delete(route('empresa.sucursales.destroy', [props.tenant.slug, props.sucursal.id]));
    }
};

const roleBadge = (name) => ({
    'admin-sucursal': { label: 'Admin Sucursal', cls: 'bg-orange-50 text-orange-700 ring-orange-600/20' },
    'cajero': { label: 'Cajero', cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' },
}[name] || { label: name, cls: 'bg-gray-100 text-gray-600 ring-gray-300/50' });

// URL pública del menú: se construye en el cliente para que funcione tanto
// en desarrollo (localhost) como en producción (dominio del tenant).
const menuUrl = computed(() => `${window.location.origin}/menu/${props.tenant.slug}/s/${props.sucursal.id}`);

const copyMenuUrl = async () => {
    try {
        await navigator.clipboard.writeText(menuUrl.value);
    } catch (e) {
        // Silencioso: si el navegador bloquea clipboard (p.ej. http en producción),
        // el usuario puede copiar manualmente del campo visible.
    }
};
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

        <form @submit.prevent="submit" class="mx-auto max-w-3xl space-y-6 pb-12">
            <!-- Banner de errores cruzados -->
            <div v-if="crossErrors.length > 0" class="rounded-xl bg-red-50 px-4 py-3 ring-1 ring-red-200">
                <p class="text-sm font-bold text-red-900">Hay datos faltantes para guardar:</p>
                <ul class="mt-1.5 list-disc pl-5 text-xs text-red-700">
                    <li v-for="(err, i) in crossErrors" :key="i">{{ err }}</li>
                </ul>
            </div>

            <!-- Información general -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Información general</h2>
                    <p class="mt-1 text-sm text-gray-500">Identidad y dirección física de la sucursal.</p>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-[2fr_1fr]">
                        <div>
                            <InputLabel for="name" value="Nombre de la sucursal" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1.5 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
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
                    <div>
                        <InputLabel for="address" value="Dirección" />
                        <TextInput id="address" v-model="form.address" type="text" class="mt-1.5 block w-full" placeholder="Av. Juárez 123, Centro, Villahermosa" />
                        <InputError :message="form.errors.address" class="mt-1" />
                    </div>
                </div>
            </section>

            <!-- Teléfonos (componente diferenciado) -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Teléfonos</h2>
                    <p class="mt-1 text-sm text-gray-500">Distintos números para distintos usos.</p>
                </div>
                <div class="p-6">
                    <PhoneFields
                        v-model:phone="form.phone"
                        v-model:public-phone="form.public_phone"
                        :phone-error="form.errors.phone"
                        :public-phone-error="form.errors.public_phone"
                        :online-enabled="form.online_ordering_enabled" />
                </div>
            </section>

            <!-- Ubicación -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Ubicación</h2>
                    <p class="mt-1 text-sm text-gray-500">Coloca el pin exactamente en la entrada de la sucursal. Esto determina las distancias de envío en pedidos a domicilio.</p>
                </div>
                <div class="p-6">
                    <MapPickerPro
                        :latitude="form.latitude"
                        :longitude="form.longitude"
                        @confirmed="(lat, lng) => { form.latitude = lat; form.longitude = lng; }"
                        @address-suggested="(addr) => { if (!form.address) form.address = addr; }" />
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel for="latitude" value="Latitud" />
                            <TextInput id="latitude" v-model="form.latitude" type="text" class="mt-1 block w-full font-mono text-sm tabular-nums" placeholder="17.9891" />
                            <InputError :message="form.errors.latitude" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="longitude" value="Longitud" />
                            <TextInput id="longitude" v-model="form.longitude" type="text" class="mt-1 block w-full font-mono text-sm tabular-nums" placeholder="-92.9475" />
                            <InputError :message="form.errors.longitude" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Horarios -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <h2 class="text-base font-bold text-gray-900">Horarios de atención</h2>
                    <p class="mt-1 text-sm text-gray-500">Define los días y horas en que la sucursal opera.</p>
                </div>
                <div class="p-6">
                    <HoursEditor
                        v-model="form.hours"
                        :show-online-impact="form.online_ordering_enabled" />
                </div>
            </section>

            <!-- Pedidos online -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Pedidos en línea</h2>
                        <p class="mt-1 text-sm text-gray-500">Permite que tus clientes hagan pedidos desde el menú web público y los reciban por WhatsApp.</p>
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
                                    <p class="text-sm font-semibold text-gray-900">🏪 Recolección en sucursal</p>
                                    <p class="text-xs text-gray-500">El cliente pasa por su pedido.</p>
                                </div>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:bg-gray-50">
                                <input type="checkbox" v-model="form.delivery_enabled" class="mt-0.5 rounded text-red-600" />
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">🚚 Envío a domicilio</p>
                                    <p class="text-xs text-gray-500">Requiere ubicación y tarifas.</p>
                                </div>
                            </label>
                        </div>
                        <InputError :message="form.errors.delivery_enabled" class="mt-1" />
                    </div>

                    <!-- Pedido mínimo -->
                    <div>
                        <InputLabel for="min_order_amount" value="Pedido mínimo (opcional)" />
                        <div class="mt-1.5 flex items-center gap-2 sm:w-1/3">
                            <span class="text-sm text-gray-500">$</span>
                            <TextInput id="min_order_amount" v-model="form.min_order_amount" type="number" step="0.01" min="0" placeholder="300" class="block w-full" />
                        </div>
                        <p class="mt-1 text-xs text-gray-400">Si el pedido del cliente es menor a este monto, no podrá completarlo.</p>
                        <InputError :message="form.errors.min_order_amount" class="mt-1" />
                    </div>

                    <!-- Tarifas de envío (componente reutilizable) -->
                    <div v-if="form.delivery_enabled">
                        <InputLabel value="Tarifas de envío por distancia" />
                        <p class="mt-1 mb-2 text-xs text-gray-400">Define cuánto cobrar según la distancia desde la sucursal. Fuera del último rango no se entrega.</p>
                        <DeliveryTiersEditor v-model="form.delivery_tiers" />
                        <InputError :message="form.errors.delivery_tiers" class="mt-1" />
                    </div>

                    <!-- URL pública -->
                    <div class="rounded-xl border border-red-100 bg-gradient-to-r from-red-50/60 to-orange-50/60 p-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-red-700/70">URL pública del menú</p>
                        <div class="mt-1 flex items-center gap-2">
                            <p class="flex-1 truncate font-mono text-sm text-gray-800">{{ menuUrl }}</p>
                            <button type="button" @click="copyMenuUrl"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-red-200 transition hover:bg-red-50 active:scale-95">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                                Copiar
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Comparte este link en redes, letreros o WhatsApp Business.</p>
                    </div>
                </div>
                <div v-else class="p-6">
                    <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">
                        Los clientes <strong>no podrán hacer pedidos en línea</strong> en esta sucursal hasta que actives el toggle.
                    </p>
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
