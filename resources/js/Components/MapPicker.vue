<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    latitude: { type: [String, Number], default: '' },
    longitude: { type: [String, Number], default: '' },
});

const emit = defineEmits(['update:latitude', 'update:longitude']);

const mapContainer = ref(null);
const mapReady = ref(false);
const error = ref('');
const locating = ref(false);

let map = null;
let ignoreNextMove = false;
const defaultCenter = { lat: 17.9891, lng: -92.9475 };

const updateFromCenter = () => {
    if (!map || ignoreNextMove) { ignoreNextMove = false; return; }
    const center = map.getCenter();
    emit('update:latitude', center.lat().toFixed(7));
    emit('update:longitude', center.lng().toFixed(7));
};

/**
 * Wait for the Google Maps JS API to be available.
 * The script is loaded via <script> tag in app.blade.php.
 */
const waitForGoogleMaps = () => {
    return new Promise((resolve, reject) => {
        if (window.google?.maps?.Map) {
            resolve();
            return;
        }
        let attempts = 0;
        const interval = setInterval(() => {
            attempts++;
            if (window.google?.maps?.Map) {
                clearInterval(interval);
                resolve();
            } else if (attempts > 50) { // ~5 seconds
                clearInterval(interval);
                reject(new Error('Google Maps no cargó a tiempo.'));
            }
        }, 100);
    });
};

onMounted(async () => {
    try {
        await waitForGoogleMaps();

        const center = props.latitude && props.longitude
            ? { lat: parseFloat(props.latitude), lng: parseFloat(props.longitude) }
            : defaultCenter;

        map = new google.maps.Map(mapContainer.value, {
            center,
            zoom: props.latitude ? 17 : 13,
            disableDefaultUI: true,
            zoomControl: true,
            fullscreenControl: true,
            gestureHandling: 'greedy',
            clickableIcons: false,
        });

        map.addListener('idle', updateFromCenter);
        mapReady.value = true;
    } catch (e) {
        error.value = 'Error al cargar Google Maps: ' + e.message;
    }
});

// Sync map when inputs change manually
watch([() => props.latitude, () => props.longitude], ([lat, lng]) => {
    if (!map || !lat || !lng) return;
    const newLat = parseFloat(lat);
    const newLng = parseFloat(lng);
    if (isNaN(newLat) || isNaN(newLng)) return;

    const center = map.getCenter();
    if (Math.abs(center.lat() - newLat) > 0.0000005 || Math.abs(center.lng() - newLng) > 0.0000005) {
        ignoreNextMove = true;
        map.panTo({ lat: newLat, lng: newLng });
    }
});

const useMyLocation = () => {
    if (!navigator.geolocation) return;
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            emit('update:latitude', lat.toFixed(7));
            emit('update:longitude', lng.toFixed(7));
            if (map) map.panTo({ lat, lng });
            if (map && map.getZoom() < 16) map.setZoom(17);
            locating.value = false;
        },
        () => { locating.value = false; },
        { enableHighAccuracy: true, timeout: 10000 }
    );
};
</script>

<template>
    <div>
        <!-- Error / no key -->
        <div v-if="error" class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div>
                    <p class="text-sm font-semibold text-amber-800">Mapa no disponible</p>
                    <p class="mt-0.5 text-xs text-amber-700">{{ error }}</p>
                </div>
            </div>
        </div>

        <!-- Map -->
        <div v-else class="relative overflow-hidden rounded-xl ring-1 ring-gray-200">
            <div ref="mapContainer" class="h-[400px] w-full bg-gray-100"></div>

                <!-- Overlays -->
                <div v-if="!mapReady && !error" class="absolute inset-0 flex items-center justify-center bg-gray-100 z-10">
                    <div class="text-center">
                        <svg class="mx-auto h-8 w-8 animate-spin text-red-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        <p class="mt-2 text-sm text-gray-400">Cargando mapa...</p>
                    </div>
                </div>
                <div v-if="error" class="absolute inset-0 flex items-center justify-center px-6 text-center bg-gray-100 z-10">
                    <p class="text-sm font-semibold text-amber-700">{{ error }}</p>
                </div>

                <!-- Fixed center pin (CSS overlay — NOT a map marker) -->
                <div v-if="mapReady" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="flex flex-col items-center -translate-y-5">
                        <svg class="h-10 w-10 drop-shadow-lg" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="#DC2626" stroke="#991B1B" stroke-width="0.5"/>
                            <circle cx="12" cy="9" r="2.5" fill="white"/>
                        </svg>
                        <div class="h-2 w-2 rounded-full bg-black/20 blur-[2px]" />
                    </div>
                </div>

                <!-- My location button -->
                <button
                    v-if="mapReady"
                    type="button"
                    @click="useMyLocation"
                    :disabled="locating"
                    class="absolute left-3 top-3 z-[5] flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-md ring-1 ring-gray-200 transition hover:bg-gray-50 disabled:opacity-50"
                >
                    <svg v-if="!locating" class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                    </svg>
                    <svg v-else class="h-4 w-4 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    {{ locating ? 'Localizando...' : 'Mi ubicacion' }}
                </button>

            <!-- Footer -->
            <div class="border-t border-gray-200 bg-gray-50 px-4 py-2.5">
                <p class="text-xs text-gray-500">Mueve el mapa para posicionar el marcador rojo en la ubicacion de la sucursal.</p>
            </div>
        </div>
    </div>
</template>
