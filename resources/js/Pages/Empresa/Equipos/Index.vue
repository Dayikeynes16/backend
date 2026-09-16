<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import FlashToast from '@/Components/FlashToast.vue';
import DevicesBoard from '@/Components/Devices/DevicesBoard.vue';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    tenant: { type: Object, required: true },
});

const routes = { update: 'empresa.devices.update', mute: 'empresa.devices.mute', destroy: 'empresa.devices.destroy' };

const total = computed(() => props.branches.reduce((n, b) => n + b.devices.length, 0));
const countLabel = computed(() => (total.value === 1 ? '1 registrado' : `${total.value} registrados`));
const branchLabel = (b) => (b.devices.length === 1 ? '1 registrado' : `${b.devices.length} registrados`);
</script>

<template>
    <Head title="Equipos" />
    <EmpresaLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Equipos</h1>
                    <p class="mt-0.5 text-xs text-gray-500">Básculas y hubs de todas las sucursales: versión, batería y última conexión.</p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">{{ countLabel }}</span>
            </div>
        </template>

        <FlashToast />
        <div class="mx-auto max-w-6xl space-y-10">
            <section v-for="b in branches" :key="b.id" class="space-y-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-gray-500">{{ b.name }}</h2>
                    <span class="text-xs text-gray-400">{{ branchLabel(b) }}</span>
                </div>
                <DevicesBoard :devices="b.devices" :alerts="b.alerts" :unregistered="b.unregistered" :routes="routes" :tenant-slug="tenant.slug" />
            </section>
            <div v-if="branches.length === 0" class="rounded-2xl bg-white px-6 py-12 text-center text-sm text-gray-500 ring-1 ring-gray-200">
                Todavía no hay sucursales.
            </div>
        </div>
    </EmpresaLayout>
</template>
