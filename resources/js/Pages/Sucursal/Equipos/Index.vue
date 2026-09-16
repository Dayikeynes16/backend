<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import DevicesBoard from '@/Components/Devices/DevicesBoard.vue';

const props = defineProps({
    devices: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    unregistered: { type: Array, default: () => [] },
    tenant: { type: Object, required: true },
    branchName: { type: String, default: '' },
});

const routes = { update: 'sucursal.devices.update', mute: 'sucursal.devices.mute', destroy: 'sucursal.devices.destroy' };

const countLabel = computed(() => (props.devices.length === 1 ? '1 registrado' : `${props.devices.length} registrados`));
</script>

<template>
    <Head title="Equipos" />
    <SucursalLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Equipos</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Básculas y hubs de {{ branchName || 'esta sucursal' }}: versión, batería y última conexión.</p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ countLabel }}</span>
            </div>
        </template>

        <FlashToast />
        <div class="mx-auto max-w-6xl">
            <DevicesBoard :devices="devices" :alerts="alerts" :unregistered="unregistered" :routes="routes" :tenant-slug="tenant.slug" />
        </div>
    </SucursalLayout>
</template>
