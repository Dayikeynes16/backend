<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import CajeroLayout from '@/Layouts/CajeroLayout.vue';
import DevicesBoard from '@/Components/Devices/DevicesBoard.vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    unregistered: { type: Array, default: () => [] },
    tenant: { type: Object, required: true },
    branchName: { type: String, default: '' },
});

const countLabel = computed(() => (props.devices.length === 1 ? '1 registrado' : `${props.devices.length} registrados`));

// Igual que en Sucursal: sin evento de tiempo real, el tablero se refresca
// solo cada 30 s mientras la pestaña está visible.
let poll = null;
const refresh = () => {
    if (document.visibilityState !== 'visible') return;
    router.reload({ only: ['devices', 'alerts', 'unregistered'], preserveScroll: true, preserveState: true });
};
onMounted(() => { poll = setInterval(refresh, 30000); });
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head title="Equipos" />
    <CajeroLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Equipos</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Básculas y hubs de {{ branchName || 'esta sucursal' }}: versión, batería y última conexión.</p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ countLabel }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-6xl">
            <DevicesBoard :devices="devices" :alerts="alerts" :unregistered="unregistered" :tenant-slug="tenant.slug" readonly />
        </div>
    </CajeroLayout>
</template>
