<script setup>
import { computed } from 'vue';

const props = defineProps({
    device: { type: Object, required: true },
    // Reloj del tablero: sin él, «hace 3 min» se quedaría escrito para siempre.
    now: { type: Number, default: () => Date.now() },
});

const emit = defineEmits(['open']);

// El chip de estado es la lectura de un vistazo: verde tranquilo, ámbar para
// mirar, rojo para actuar. El anillo de la tarjeta acompaña solo cuando importa.
const STATUS = {
    online: { label: 'En línea', chip: 'bg-emerald-100 text-emerald-800', dot: 'bg-emerald-500', ring: 'ring-gray-200' },
    battery_critical: { label: 'Se va a apagar', chip: 'bg-red-100 text-red-800', dot: 'bg-red-500', ring: 'ring-red-300' },
    battery_low: { label: 'Batería baja', chip: 'bg-amber-100 text-amber-800', dot: 'bg-amber-500', ring: 'ring-amber-300' },
    stale: { label: 'Hace un rato', chip: 'bg-amber-100 text-amber-800', dot: 'bg-amber-400', ring: 'ring-gray-200' },
    silent: { label: 'Sin reportar', chip: 'bg-red-100 text-red-800', dot: 'bg-red-500', ring: 'ring-red-200' },
    retired: { label: 'De baja', chip: 'bg-gray-200 text-gray-700', dot: 'bg-gray-400', ring: 'ring-gray-200' },
};
const UNKNOWN = { label: 'Desconocido', chip: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400', ring: 'ring-gray-200' };

const status = computed(() => STATUS[props.device.status] ?? UNKNOWN);

const relative = (iso) => {
    if (!iso) return '—';
    const mins = Math.round((props.now - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'ahora mismo';
    if (mins < 60) return `hace ${mins} min`;
    const h = Math.floor(mins / 60);
    if (h < 24) return mins % 60 ? `hace ${h} h ${mins % 60} min` : `hace ${h} h`;
    const d = Math.floor(h / 24);
    return d === 1 ? 'hace 1 día' : `hace ${d} días`;
};

const hasBattery = computed(() => props.device.battery_level !== null && props.device.battery_level !== undefined);

const batteryColor = computed(() => {
    if (!hasBattery.value) return 'bg-gray-300';
    if (props.device.battery_charging) return 'bg-emerald-500';
    // La severidad la decide el servidor con los umbrales de la sucursal: aquí
    // solo se pinta. El ámbar del 40 % es un degradado visual, no una alerta.
    if (props.device.severity) return 'bg-red-500';
    return props.device.battery_level <= 40 ? 'bg-amber-500' : 'bg-emerald-500';
});

const energy = computed(() => {
    const d = props.device;
    if (!hasBattery.value) return 'Enchufado · sin batería';
    return `${d.battery_level} % · ${d.battery_charging ? 'cargando' : 'sin cargar'}`;
});

const connectionLabel = computed(() => {
    if (props.device.connection === 'hub') return 'Vende contra el hub';
    if (props.device.connection === 'cloud') return 'Vende contra la nube';
    return null;
});
</script>

<template>
    <button
        type="button"
        class="group w-full rounded-2xl bg-white p-4 text-left shadow-sm ring-1 transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
        :class="[status.ring, device.status === 'silent' ? 'opacity-80' : '']"
        @click="emit('open', device)"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="flex items-center gap-1.5 text-sm font-bold text-gray-900">
                    <span class="truncate">{{ device.shown_name }}</span>
                    <svg v-if="device.muted" class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-label="Avisos silenciados"><title>Avisos silenciados</title><path stroke-linecap="round" stroke-linejoin="round" d="M9.143 17.082a24.248 24.248 0 0 0 3.844.148m-3.844-.148a23.856 23.856 0 0 1-5.455-1.31 8.964 8.964 0 0 0 2.3-5.542m3.155 6.852a3 3 0 0 0 5.667 1.97m1.965-2.277L21 21m-4.225-4.225a23.81 23.81 0 0 0 3.536-1.003A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6.53 6.53m10.245 10.245L6.53 6.53M3 3l3.53 3.53" /></svg>
                </p>
                <p class="text-xs text-gray-500">{{ device.kind_label }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="status.chip">
                <span class="h-1.5 w-1.5 rounded-full" :class="status.dot" />
                {{ status.label }}
            </span>
        </div>

        <dl class="mt-4 grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1.5 text-xs">
            <dt class="text-gray-400">Versión</dt>
            <dd class="flex flex-wrap items-center gap-1.5 text-gray-700">
                <span class="font-medium">{{ device.app_version ?? '—' }}</span>
                <span v-if="device.outdated" class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">atrasada · hay {{ device.release_version }}</span>
                <span v-else-if="device.release_version && device.app_version" class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-800">al día</span>
            </dd>

            <dt class="text-gray-400">Energía</dt>
            <dd class="flex items-center gap-2 text-gray-700">
                <span class="relative inline-flex h-3 w-7 items-center rounded-[3px] border border-gray-400 p-px" aria-hidden="true">
                    <span class="block h-full rounded-[2px] transition-all" :class="batteryColor" :style="{ width: (hasBattery ? device.battery_level : 100) + '%' }" />
                    <span class="absolute -right-[3px] top-1/2 h-1.5 w-0.5 -translate-y-1/2 rounded-r bg-gray-400" />
                </span>
                {{ energy }}
            </dd>

            <dt class="text-gray-400">Reportó</dt>
            <dd class="text-gray-700">{{ relative(device.last_seen_at) }}</dd>

            <dt class="text-gray-400">Última venta</dt>
            <dd class="text-gray-700">{{ relative(device.last_sale_at) }}</dd>
        </dl>

        <div v-if="connectionLabel || device.local_ip" class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
            <span v-if="connectionLabel" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ connectionLabel }}</span>
            <span v-if="device.local_ip" class="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-[11px] text-gray-500">{{ device.local_ip }}</span>
        </div>
    </button>
</template>
