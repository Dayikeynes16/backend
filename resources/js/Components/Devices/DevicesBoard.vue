<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import DeviceCard from '@/Components/Devices/DeviceCard.vue';
import DeviceDetailPanel from '@/Components/Devices/DeviceDetailPanel.vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    unregistered: { type: Array, default: () => [] },
    // Nombres de ruta de las acciones; sin ellas (o con `readonly`) el panel solo muestra.
    routes: { type: Object, default: null },
    tenantSlug: { type: String, required: true },
    readonly: { type: Boolean, default: false },
});

const open = ref(null);

// Un solo reloj para todas las tarjetas: los «hace N min» avanzan aunque el
// servidor no haya mandado nada nuevo.
const now = ref(Date.now());
let clock = null;
onMounted(() => { clock = setInterval(() => { now.value = Date.now(); }, 30000); });
onBeforeUnmount(() => clearInterval(clock));

// Tras renombrar o silenciar, Inertia trae props nuevas: el panel debe mostrar
// el equipo actualizado, no la copia con la que se abrió.
watch(() => props.devices, (list) => {
    if (!open.value) return;
    open.value = list.find((d) => d.id === open.value.id) ?? null;
});

const alertText = (d) => {
    if (d.status === 'battery_low') return `${d.shown_name} está al ${d.battery_level} % y no está cargando.`;
    if (d.status === 'silent') return `${d.shown_name} no reporta desde hace rato.`;
    if (d.outdated) return `${d.shown_name} sigue en ${d.app_version}; hay ${d.release_version}.`;
    return d.shown_name;
};

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <div class="space-y-5">
        <div v-if="alerts.length" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
            <p class="text-sm font-bold text-amber-800">Requieren atención</p>
            <ul class="mt-1.5 space-y-1 text-sm text-amber-800">
                <li v-for="d in alerts" :key="d.id" class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <button type="button" class="text-left hover:underline" @click="open = d">{{ alertText(d) }}</button>
                </li>
            </ul>
        </div>

        <div v-if="devices.length === 0 && unregistered.length === 0" class="rounded-2xl bg-white px-6 py-12 text-center ring-1 ring-gray-200">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-700">Todavía no hay equipos reportando</p>
            <p class="mt-1 text-xs text-gray-500">Las básculas y hubs actualizados aparecen aquí solos en cuanto se conectan.</p>
        </div>

        <div v-else-if="devices.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <DeviceCard v-for="d in devices" :key="d.id" :device="d" :now="now" @open="open = $event" />
        </div>

        <div v-if="unregistered.length" class="rounded-2xl bg-white p-5 ring-1 ring-gray-200">
            <p class="text-sm font-bold text-gray-800">Sin registro</p>
            <p class="mt-0.5 text-xs text-gray-500">Básculas que solo mandan ventas y no reportan su estado (versiones antiguas). Se deducen del nombre que escriben en cada venta.</p>
            <ul class="mt-3 divide-y divide-gray-100 text-sm">
                <li v-for="u in unregistered" :key="u.name" class="flex items-center justify-between gap-3 py-2">
                    <span class="truncate font-medium text-gray-700">{{ u.name }}</span>
                    <span class="shrink-0 text-xs text-gray-400">última venta {{ fmt(u.last_sale_at) }}</span>
                </li>
            </ul>
        </div>

        <DeviceDetailPanel :device="open" :routes="routes" :tenant-slug="tenantSlug" :readonly="readonly || !routes" @close="open = null" />
    </div>
</template>
