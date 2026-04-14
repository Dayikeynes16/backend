<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MapPicker from '@/Components/MapPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import QrcodeVue from 'qrcode.vue';

const props = defineProps({ branch: Object, tenant: Object, apiKeys: Array, newKey: { type: String, default: null } });

const qrPayload = computed(() => {
    if (!props.newKey) return '';
    return JSON.stringify({
        v: 1,
        type: 'carniceria-saas.setup',
        baseUrl: window.location.origin,
        apiKey: props.newKey,
        branch: props.branch?.name,
    });
});

// --- Branch config form ---
const form = useForm({
    name: props.branch.name,
    phone: props.branch.phone || '',
    address: props.branch.address || '',
    latitude: props.branch.latitude || '',
    longitude: props.branch.longitude || '',
    schedule: props.branch.schedule || '',
    payment_methods_enabled: props.branch.payment_methods_enabled || ['cash', 'card', 'transfer'],
});

const noMethodsSelected = computed(() => form.payment_methods_enabled.length === 0);

const submitConfig = () => {
    if (noMethodsSelected.value) return;
    form.put(route('sucursal.configuracion.update', props.tenant.slug));
};

// --- API Keys ---
const showCreateKey = ref(false);
const keyForm = useForm({ name: '' });
const copied = ref(false);
const confirmRevokeId = ref(null);

const submitKey = () => {
    keyForm.post(route('sucursal.api-keys.store', props.tenant.slug), {
        onSuccess: () => { keyForm.reset(); showCreateKey.value = false; },
    });
};

const revokeKey = () => {
    if (!confirmRevokeId.value) return;
    router.delete(route('sucursal.api-keys.destroy', [props.tenant.slug, confirmRevokeId.value]), {
        onSuccess: () => { confirmRevokeId.value = null; },
    });
};

const copyKey = () => {
    navigator.clipboard.writeText(props.newKey);
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
};

const activeKeys = computed(() => props.apiKeys?.filter(k => k.status === 'active') || []);
const revokedKeys = computed(() => props.apiKeys?.filter(k => k.status !== 'active') || []);
</script>

<template>
    <Head title="Configuracion" />
    <SucursalLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900">Configuracion</h1>
        </template>

        <div class="mx-auto max-w-3xl space-y-8 pb-12">
            <!-- New key alert (prominent, top of page) -->
            <div v-if="newKey" class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100">
                        <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-emerald-900">API Key generada exitosamente</p>
                        <p class="mt-0.5 text-xs text-emerald-700">Copia esta key ahora. No se mostrara de nuevo.</p>
                        <div class="mt-3 flex items-center gap-2">
                            <code class="flex-1 rounded-lg bg-emerald-100 px-3 py-2 font-mono text-sm text-emerald-900 select-all">{{ newKey }}</code>
                            <button @click="copyKey" class="shrink-0 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-700">
                                {{ copied ? 'Copiada' : 'Copiar' }}
                            </button>
                        </div>

                        <div class="mt-5 flex flex-col items-center gap-3 rounded-xl border border-emerald-200 bg-white p-4 sm:flex-row sm:items-start sm:gap-5">
                            <div class="rounded-lg border border-emerald-100 bg-white p-2">
                                <QrcodeVue :value="qrPayload" :size="180" level="M" render-as="svg" />
                            </div>
                            <div class="flex-1 text-center sm:text-left">
                                <p class="text-sm font-bold text-emerald-900">Vincular báscula por QR</p>
                                <p class="mt-1 text-xs text-emerald-700">Abre la báscula, toca <strong>Escanear QR</strong> y apunta la cámara a este código. La conexión será automática.</p>
                                <p class="mt-2 text-[11px] text-emerald-600">Incluye URL y API Key. No compartas esta pantalla.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submitConfig" class="space-y-8">
                <!-- 1. Datos de la Sucursal -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Datos de la Sucursal</h2>
                        <p class="mt-1 text-sm text-gray-500">Informacion de contacto y horario.</p>
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

                <!-- 2. Ubicacion -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Ubicacion</h2>
                        <p class="mt-1 text-sm text-gray-500">Posicion de la sucursal en el mapa.</p>
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

                <!-- 3. Metodos de Pago -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 px-6 py-5">
                        <h2 class="text-base font-bold text-gray-900">Metodos de Pago</h2>
                        <p class="mt-1 text-sm text-gray-500">Habilita los metodos de pago disponibles.</p>
                    </div>
                    <div class="space-y-3 p-6">
                        <label v-for="method in [{id:'cash',label:'Efectivo',icon:'text-green-600'},{id:'card',label:'Tarjeta',icon:'text-blue-600'},{id:'transfer',label:'Transferencia',icon:'text-purple-600'}]"
                            :key="method.id" class="flex items-center justify-between rounded-xl p-4 ring-1 transition cursor-pointer"
                            :class="noMethodsSelected ? 'ring-red-300 bg-red-50/30' : 'ring-gray-100 hover:bg-gray-50'">
                            <span class="text-sm font-semibold" :class="method.icon">{{ method.label }}</span>
                            <input type="checkbox" :value="method.id" v-model="form.payment_methods_enabled"
                                class="h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                        </label>
                        <p v-if="noMethodsSelected" class="text-sm text-red-600 font-medium">Debes habilitar al menos un metodo de pago.</p>
                        <InputError :message="form.errors.payment_methods_enabled" class="mt-1" />
                    </div>
                </section>

                <!-- Save button -->
                <div class="flex items-center justify-end">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Guardar Cambios</button>
                </div>
            </form>

            <!-- 4. API Keys -->
            <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-gray-900">API Keys</h2>
                            <p class="mt-1 text-sm text-gray-500">Llaves para conectar dispositivos externos como la bascula.</p>
                        </div>
                        <button v-if="!showCreateKey" @click="showCreateKey = true" class="flex items-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-gray-800">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nueva Key
                        </button>
                    </div>
                </div>

                <div class="p-6 space-y-4">
                    <!-- Create form -->
                    <div v-if="showCreateKey" class="rounded-xl bg-gray-50 p-4">
                        <form @submit.prevent="submitKey" class="flex items-end gap-3">
                            <div class="flex-1">
                                <InputLabel for="keyName" value="Nombre (ej: Bascula principal)" />
                                <TextInput id="keyName" v-model="keyForm.name" type="text" class="mt-1.5 block w-full" required placeholder="Bascula, Kiosco, etc." />
                                <InputError :message="keyForm.errors.name" class="mt-1" />
                            </div>
                            <button type="submit" :disabled="keyForm.processing" class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-gray-800 disabled:opacity-50">Generar</button>
                            <button type="button" @click="showCreateKey = false" class="rounded-lg px-3 py-2.5 text-sm text-gray-500 transition hover:bg-gray-200">Cancelar</button>
                        </form>
                    </div>

                    <!-- Active keys -->
                    <div v-if="activeKeys.length > 0" class="space-y-3">
                        <div v-for="key in activeKeys" :key="key.id" class="flex items-center justify-between rounded-xl p-4 ring-1 ring-gray-100">
                            <div class="flex items-center gap-4">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50">
                                    <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-gray-900">{{ key.name }}</p>
                                    <div class="mt-0.5 flex items-center gap-3">
                                        <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-500">{{ key.prefix }}...</code>
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Activa</span>
                                        <span class="text-xs text-gray-400">Ultimo uso: {{ key.last_used_at }}</span>
                                    </div>
                                </div>
                            </div>
                            <button @click="confirmRevokeId = key.id" class="rounded-lg px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50">
                                Revocar
                            </button>
                        </div>
                    </div>

                    <!-- Revoked keys -->
                    <div v-if="revokedKeys.length > 0">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Revocadas</p>
                        <div class="space-y-2">
                            <div v-for="key in revokedKeys" :key="key.id" class="flex items-center justify-between rounded-xl p-3 opacity-50 ring-1 ring-gray-100">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-500">{{ key.name }}</p>
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-600">Revocada</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!apiKeys || apiKeys.length === 0" class="py-8 text-center">
                        <svg class="mx-auto h-10 w-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>
                        <p class="mt-3 text-sm text-gray-400">No hay API Keys. Genera una para conectar dispositivos.</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Confirm revoke dialog -->
        <ConfirmDialog v-if="confirmRevokeId"
            title="Revocar API Key"
            message="Las integraciones que usen esta key dejaran de funcionar inmediatamente. Esta accion no se puede deshacer."
            confirm-label="Revocar"
            variant="danger"
            @confirm="revokeKey"
            @cancel="confirmRevokeId = null"
        />

        <FlashToast />
    </SucursalLayout>
</template>
