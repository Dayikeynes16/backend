<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    device: { type: Object, default: null },
    routes: { type: Object, required: true }, // { update, mute, destroy } — nombres de ruta
    tenantSlug: { type: String, required: true },
});

const emit = defineEmits(['close']);

const alias = ref('');
const confirmRetire = ref(false);
const busy = ref(false);

// El alias se reinicia solo al abrir OTRO equipo. Tras silenciar o renombrar
// llega el mismo equipo con props frescas y lo escrito no debe perderse.
watch(() => props.device?.id, () => { alias.value = props.device?.display_name ?? ''; }, { immediate: true });

const url = (name) => route(name, [props.tenantSlug, props.device.id]);
const opts = (extra = {}) => ({
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => { busy.value = false; },
    ...extra,
});

const rename = () => router.patch(url(props.routes.update), { display_name: alias.value }, opts());
const toggleMute = () => router.patch(url(props.routes.mute), {}, opts());
const retire = () => {
    confirmRetire.value = false;
    router.delete(url(props.routes.destroy), opts({ onSuccess: () => emit('close') }));
};

const onKey = (e) => {
    if (e.key === 'Escape' && props.device && !confirmRetire.value) emit('close');
};
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const energy = (d) => {
    if (d.battery_level === null || d.battery_level === undefined) return 'Enchufado · sin batería';
    return `${d.battery_level} % · ${d.battery_charging ? 'cargando' : 'sin cargar'}`;
};
const against = (c) => (c === 'hub' ? 'el hub' : c === 'cloud' ? 'la nube' : '—');
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            leave-active-class="transition duration-150 ease-in"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="device" class="fixed inset-0 z-40 flex justify-end bg-black/30 backdrop-blur-[2px]" @click.self="emit('close')">
                <Transition
                    appear
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-x-full"
                    leave-active-class="transition duration-150 ease-in"
                    leave-to-class="translate-x-full"
                >
                    <aside class="flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-2xl" role="dialog" aria-modal="true">
                        <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-5">
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-bold text-gray-900">{{ device.shown_name }}</h2>
                                <p class="truncate text-xs text-gray-500">{{ device.kind_label }} · <span class="font-mono">{{ device.device_id }}</span></p>
                            </div>
                            <button type="button" class="shrink-0 rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" aria-label="Cerrar" @click="emit('close')">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                        </div>

                        <div class="flex-1 space-y-6 px-6 py-5">
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                <dt class="text-gray-400">Nombre en el equipo</dt><dd class="text-gray-800">{{ device.name }}</dd>
                                <dt class="text-gray-400">Versión</dt>
                                <dd class="text-gray-800">
                                    {{ device.app_version ?? '—' }}
                                    <span v-if="device.release_version" class="text-gray-400"> · publicada {{ device.release_version }}</span>
                                </dd>
                                <dt class="text-gray-400">Sistema</dt><dd class="text-gray-800">{{ device.os ?? '—' }}</dd>
                                <dt class="text-gray-400">Modelo</dt><dd class="text-gray-800">{{ device.model ?? '—' }}</dd>
                                <dt class="text-gray-400">Batería</dt><dd class="text-gray-800">{{ energy(device) }}</dd>
                                <dt class="text-gray-400">Vende contra</dt><dd class="text-gray-800">{{ against(device.connection) }}</dd>
                                <dt class="text-gray-400">Reporta por</dt><dd class="text-gray-800">{{ device.via === 'hub' ? 'el hub' : 'la nube' }}</dd>
                                <dt class="text-gray-400">IP local</dt><dd class="font-mono text-gray-800">{{ device.local_ip ?? '—' }}</dd>
                                <dt class="text-gray-400">Último reporte</dt><dd class="text-gray-800">{{ fmt(device.last_seen_at) }}</dd>
                                <dt class="text-gray-400">Primer reporte</dt><dd class="text-gray-800">{{ fmt(device.first_seen_at) }}</dd>
                                <dt class="text-gray-400">Última venta</dt><dd class="text-gray-800">{{ fmt(device.last_sale_at) }}</dd>
                            </dl>

                            <div class="border-t border-gray-100 pt-5">
                                <label for="device-alias" class="block text-sm font-medium text-gray-700">Nombre en la web</label>
                                <form class="mt-1.5 flex gap-2" @submit.prevent="rename">
                                    <input id="device-alias" v-model="alias" type="text" maxlength="100" :placeholder="device.name" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                                    <button type="submit" :disabled="busy" class="rounded-lg bg-red-600 px-4 text-sm font-bold text-white transition hover:bg-red-500 disabled:opacity-50">Guardar</button>
                                </form>
                                <p class="mt-1 text-xs text-gray-400">Vacío vuelve al nombre que manda el equipo.</p>
                            </div>
                        </div>

                        <div class="space-y-2 border-t border-gray-100 px-6 py-5">
                            <button type="button" :disabled="busy" class="w-full rounded-lg border-2 border-gray-200 py-2 text-sm font-bold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50" @click="toggleMute">
                                {{ device.muted ? 'Reactivar avisos' : 'Silenciar avisos' }}
                            </button>
                            <button type="button" :disabled="busy" class="w-full rounded-lg border-2 border-red-200 py-2 text-sm font-bold text-red-700 transition hover:bg-red-50 disabled:opacity-50" @click="confirmRetire = true">
                                Dar de baja
                            </button>
                            <p class="text-xs text-gray-400">Dar de baja lo oculta y deja de avisar. Si vuelve a reportar, reaparece solo.</p>
                        </div>
                    </aside>
                </Transition>
            </div>
        </Transition>

        <ConfirmDialog
            v-if="confirmRetire && device"
            title="¿Dar de baja este equipo?"
            :message="`${device.shown_name} desaparecerá del panel y dejará de generar avisos. Si vuelve a reportar, reaparece.`"
            confirm-label="Dar de baja"
            @confirm="retire"
            @cancel="confirmRetire = false"
        />
    </Teleport>
</template>
