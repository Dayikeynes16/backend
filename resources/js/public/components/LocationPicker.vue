<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    latitude: { type: [String, Number, null], default: null },
    longitude: { type: [String, Number, null], default: null },
    branchLat: { type: Number, default: null },
    branchLng: { type: Number, default: null },
});

const emit = defineEmits(['update:latitude', 'update:longitude', 'geocoded']);

const mapContainer = ref(null);
const mapReady = ref(false);
const error = ref('');
const locating = ref(false);
const askingPermission = ref(true);
const permissionDenied = ref(false);

let map = null;
let branchMarker = null;
let geocoder = null;
let ignoreNextMove = false;

const emitCenter = () => {
    if (!map || ignoreNextMove) { ignoreNextMove = false; return; }
    const center = map.getCenter();
    const lat = center.lat();
    const lng = center.lng();
    emit('update:latitude', lat);
    emit('update:longitude', lng);
    reverseGeocode(lat, lng);
};

let geocodeDebounce = null;
const reverseGeocode = (lat, lng) => {
    if (!geocoder) return;
    clearTimeout(geocodeDebounce);
    geocodeDebounce = setTimeout(() => {
        geocoder.geocode({ location: { lat, lng } }, (results, status) => {
            if (status === 'OK' && results && results[0]) {
                emit('geocoded', results[0].formatted_address);
            }
        });
    }, 600);
};

/**
 * Wait for the Google Maps JS API to be available.
 * The script is loaded via <script> tag in public-spa.blade.php.
 * We poll briefly in case the async script hasn't finished loading yet.
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

const initMap = async (centerLat, centerLng, zoom = 17) => {
    try {
        await waitForGoogleMaps();

        geocoder = new google.maps.Geocoder();

        map = new google.maps.Map(mapContainer.value, {
            center: { lat: centerLat, lng: centerLng },
            zoom,
            disableDefaultUI: true,
            zoomControl: true,
            gestureHandling: 'greedy',
            clickableIcons: false,
        });

        // Branch marker (gray, non-interactive, visual reference)
        if (props.branchLat && props.branchLng) {
            branchMarker = new google.maps.Marker({
                map,
                position: { lat: props.branchLat, lng: props.branchLng },
                title: 'Sucursal',
                icon: {
                    path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z',
                    fillColor: '#6b7280',
                    fillOpacity: 1,
                    strokeColor: '#374151',
                    strokeWeight: 1,
                    scale: 1.2,
                    anchor: new google.maps.Point(12, 22),
                },
            });
        }

        map.addListener('idle', emitCenter);
        mapReady.value = true;

        // Initial emit to notify parent of starting coords
        emit('update:latitude', centerLat);
        emit('update:longitude', centerLng);
        reverseGeocode(centerLat, centerLng);
    } catch (e) {
        error.value = 'Error al cargar el mapa.';
    }
};

const requestLocation = () => {
    if (!navigator.geolocation) {
        // Fallback to branch location or Tabasco default
        askingPermission.value = false;
        initMap(props.branchLat ?? 17.9891, props.branchLng ?? -92.9475, 14);
        return;
    }

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            askingPermission.value = false;
            await initMap(pos.coords.latitude, pos.coords.longitude, 18);
        },
        () => {
            askingPermission.value = false;
            permissionDenied.value = true;
            initMap(props.branchLat ?? 17.9891, props.branchLng ?? -92.9475, 14);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60_000 },
    );
};

const recenterToMyLocation = () => {
    if (!navigator.geolocation || !map) return;
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            map.panTo({ lat, lng });
            if (map.getZoom() < 17) map.setZoom(18);
            locating.value = false;
        },
        () => { locating.value = false; },
        { enableHighAccuracy: true, timeout: 8000 },
    );
};

onMounted(() => {
    // If we already have stored coordinates, use those; otherwise ask for geolocation.
    if (props.latitude && props.longitude) {
        askingPermission.value = false;
        initMap(Number(props.latitude), Number(props.longitude), 17);
    } else {
        requestLocation();
    }
});

watch([() => props.latitude, () => props.longitude], ([lat, lng]) => {
    if (!map || !lat || !lng) return;
    const newLat = Number(lat);
    const newLng = Number(lng);
    if (isNaN(newLat) || isNaN(newLng)) return;

    const center = map.getCenter();
    if (Math.abs(center.lat() - newLat) > 0.0000005 || Math.abs(center.lng() - newLng) > 0.0000005) {
        ignoreNextMove = true;
        map.panTo({ lat: newLat, lng: newLng });
    }
});
</script>

<template>
    <div>
        <!-- Permission prompt (before map loads) -->
        <div v-if="askingPermission" class="flex h-[350px] flex-col items-center justify-center rounded-2xl bg-gradient-to-br from-red-50 to-orange-50 px-6 text-center ring-1 ring-red-100">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-red-100">
                <svg class="h-7 w-7 animate-pulse text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
            </div>
            <p class="mt-4 text-base font-bold text-gray-900">Obteniendo tu ubicación...</p>
            <p class="mt-1 text-sm text-gray-500">Acepta el permiso en tu navegador para ubicar la entrega automáticamente.</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="flex h-[200px] items-center justify-center rounded-2xl bg-amber-50 px-6 text-center ring-1 ring-amber-200">
            <div>
                <p class="text-sm font-semibold text-amber-800">{{ error }}</p>
            </div>
        </div>

        <!-- Map -->
        <div v-else class="overflow-hidden rounded-2xl ring-1 ring-gray-200">
            <div class="relative">
                <div ref="mapContainer" class="h-[350px] w-full bg-gray-100"></div>

                <!-- Overlays -->
                <div v-if="!mapReady" class="absolute inset-0 flex items-center justify-center bg-gray-100 z-10">
                    <div class="text-center">
                        <svg class="mx-auto h-8 w-8 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                        <p class="mt-2 text-xs text-gray-400">Cargando mapa...</p>
                    </div>
                </div>

                <!-- Fixed center pin (CSS overlay) -->
                <div v-if="mapReady" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="flex flex-col items-center -translate-y-5">
                        <svg class="h-12 w-12 drop-shadow-lg" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="#DC2626" stroke="#991B1B" stroke-width="0.5"/>
                            <circle cx="12" cy="9" r="2.8" fill="white"/>
                        </svg>
                        <div class="h-2 w-2 rounded-full bg-black/25 blur-[2px]" />
                    </div>
                </div>

                <!-- Instruction overlay -->
                <div v-if="mapReady" class="pointer-events-none absolute left-0 right-0 top-3 flex justify-center">
                    <div class="rounded-full bg-black/70 px-4 py-1.5 text-xs font-medium text-white shadow-lg backdrop-blur">
                        📍 Mueve el mapa para ubicar exactamente dónde entregar
                    </div>
                </div>

                <!-- My location button -->
                <button v-if="mapReady" type="button" @click="recenterToMyLocation" :disabled="locating"
                    class="absolute bottom-3 right-3 z-[5] flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-lg ring-1 ring-gray-200 transition hover:bg-gray-50 active:scale-95 disabled:opacity-50">
                    <svg v-if="!locating" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                    </svg>
                    <svg v-else class="h-5 w-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                </button>

                <!-- Permission denied banner -->
                <div v-if="permissionDenied && mapReady" class="pointer-events-none absolute bottom-3 left-3 right-16 z-[5]">
                    <div class="rounded-lg bg-amber-50/95 px-3 py-2 text-xs font-medium text-amber-800 shadow ring-1 ring-amber-200 backdrop-blur">
                        Arrastra el mapa para indicar tu dirección. Toca el ícono ⊕ para usar tu ubicación.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
