<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed, onMounted, ref, watch } from 'vue';
import { useTenant } from '../composables/useTenant.js';
import { useBranding } from '../composables/useBranding.js';

const route = useRoute();
const router = useRouter();
const tenantSlug = computed(() => route.params.tenantSlug);

const { tenant, branches, loading, error, fetch } = useTenant(tenantSlug.value);
const { logoUrl } = useBranding();
const userLocation = ref(null);
const askingLocation = ref(false);

function haversineKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

const sortedBranches = computed(() => {
    if (!userLocation.value) return branches.value;
    return [...branches.value]
        .map((b) => {
            const km = b.latitude != null && b.longitude != null
                ? haversineKm(userLocation.value.lat, userLocation.value.lng, b.latitude, b.longitude)
                : null;
            return { ...b, distance_km: km };
        })
        .sort((a, b) => {
            if (a.distance_km == null) return 1;
            if (b.distance_km == null) return -1;
            return a.distance_km - b.distance_km;
        });
});

const requestLocation = () => {
    if (!navigator.geolocation) return;
    askingLocation.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            userLocation.value = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            askingLocation.value = false;
        },
        () => { askingLocation.value = false; },
        { enableHighAccuracy: false, timeout: 8000, maximumAge: 3600 * 1000 },
    );
};

onMounted(() => { fetch(); });

// Auto-redirect when only 1 branch
watch(branches, (list) => {
    if (list.length === 1) {
        router.replace({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId: list[0].id } });
    }
});

const goToMenu = (branchId) => {
    router.push({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId } });
};
</script>

<template>
    <div class="min-h-screen" style="background-color: var(--brand-background); color: var(--brand-text);">
        <!-- Loading -->
        <div v-if="loading && !tenant" class="flex min-h-screen items-center justify-center">
            <div class="h-10 w-10 animate-spin rounded-full border-4" style="border-color: var(--brand-primary-soft); border-top-color: var(--brand-primary);"></div>
        </div>

        <!-- Error -->
        <div v-else-if="error === 'not_found'" class="flex min-h-screen items-center justify-center p-6">
            <div class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full" style="background-color: var(--brand-primary-soft);">
                    <svg class="h-8 w-8" style="color: var(--brand-primary);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                </div>
                <h1 class="mt-4 text-2xl font-bold">Carnicería no encontrada</h1>
                <p class="mt-1 text-base" style="color: var(--brand-text-subtle);">Verifica el link que te compartieron.</p>
            </div>
        </div>

        <!-- Content -->
        <div v-else-if="tenant" class="mx-auto max-w-xl px-5 py-10">
            <header class="text-center">
                <img v-if="logoUrl" :src="logoUrl" :alt="tenant.name" class="mx-auto mb-4 h-20 w-20 rounded-2xl object-cover shadow-sm" />
                <h1 class="text-3xl font-bold">{{ tenant.name }}</h1>
                <p class="mt-2 text-base" style="color: var(--brand-text-subtle);">Elige la sucursal donde quieres hacer tu pedido.</p>
            </header>

            <div class="mt-6 flex justify-center">
                <button v-if="!userLocation && !askingLocation" @click="requestLocation"
                    class="inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold shadow-sm ring-1 transition hover:brightness-95"
                    :style="{ backgroundColor: 'var(--brand-primary-soft)', color: 'var(--brand-primary)', '--tw-ring-color': 'var(--brand-primary-ring)' }">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                    Usar mi ubicación
                </button>
                <span v-else-if="askingLocation" class="text-sm" style="color: var(--brand-text-subtle);">Obteniendo ubicación…</span>
            </div>

            <div class="mt-8 space-y-3">
                <button v-for="b in sortedBranches" :key="b.id" @click="goToMenu(b.id)"
                    class="group flex w-full items-start gap-4 rounded-2xl p-5 text-left shadow-sm ring-1 transition hover:ring-[color:var(--brand-primary-ring)] active:scale-[0.99]"
                    style="background-color: var(--brand-card);">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" style="background-color: var(--brand-primary-soft);">
                        <svg class="h-6 w-6" style="color: var(--brand-primary);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" /></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-bold">{{ b.name }}</h2>
                            <span v-if="b.distance_km != null" class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background-color: var(--brand-card); color: var(--brand-text-subtle);">a {{ b.distance_km.toFixed(1) }} km</span>
                        </div>
                        <p v-if="b.address" class="mt-1 truncate text-sm" style="color: var(--brand-text-subtle);">{{ b.address }}</p>
                        <p v-if="b.schedule" class="mt-1 text-sm" style="color: var(--brand-text-subtle);">{{ b.schedule }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm font-medium">
                            <span v-if="b.pickup_enabled" class="text-green-700">🏪 Recolección</span>
                            <span v-if="b.delivery_enabled" class="text-blue-700">🏍️ Envío</span>
                        </div>
                    </div>
                    <svg class="h-5 w-5 shrink-0 transition group-hover:translate-x-1" style="color: var(--brand-text-subtle);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </button>

                <div v-if="branches.length === 0" class="rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center text-base text-gray-500">
                    Por el momento no hay sucursales disponibles para pedidos online.
                </div>
            </div>
        </div>
    </div>
</template>
