<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDeviceAlerts } from '@/composables/useDeviceAlerts';

/**
 * Un equipo se está quedando sin pila y alguien tiene que enchufarlo.
 *
 * No se puede cerrar: se va cuando lo enchufan, no cuando alguien la descarta.
 * Un aviso que se puede quitar de en medio se quita de en medio, y el equipo se
 * apaga igual a media venta.
 */
const { alerts, worst } = useDeviceAlerts();
const page = usePage();

const critical = computed(() => worst.value === 'critical');

const message = computed(() => {
    if (alerts.value.length === 0) return '';
    if (alerts.value.length > 1) {
        return `${alerts.value.length} equipos con poca batería`;
    }

    const a = alerts.value[0];

    return a.severity === 'critical'
        ? `${a.name} está al ${a.battery_level} % y se va a apagar`
        : `${a.name} va al ${a.battery_level} % y no está cargando`;
});

// El cajero tiene su propio panel de equipos, en solo lectura.
const target = computed(() => {
    const slug = page.props.auth?.tenant_slug;
    if (!slug) return null;

    return page.props.auth?.role === 'cajero'
        ? route('caja.devices.index', slug)
        : route('sucursal.devices.index', slug);
});
</script>

<template>
    <div v-if="alerts.length" role="status"
        class="flex items-center gap-2.5 border-b px-5 py-2.5 text-sm font-semibold lg:px-8"
        :class="critical ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
        </svg>
        <span class="min-w-0 flex-1 truncate">{{ message }}</span>
        <Link v-if="target" :href="target"
            class="shrink-0 rounded-full bg-white/70 px-3 py-1 text-xs font-bold ring-1 transition hover:bg-white"
            :class="critical ? 'ring-red-200' : 'ring-amber-200'">
            Ver equipos
        </Link>
    </div>
</template>
