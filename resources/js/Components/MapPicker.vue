<script setup>
import { ref, watch, onMounted } from 'vue';
import { Loader } from '@googlemaps/js-api-loader';

const props = defineProps({
    latitude: { type: [String, Number], default: '' },
    longitude: { type: [String, Number], default: '' },
});

const emit = defineEmits(['update:latitude', 'update:longitude']);

const mapContainer = ref(null);
const mapReady = ref(false);
const error = ref('');

let map = null;
let marker = null;

const defaultCenter = { lat: 17.9891, lng: -92.9475 };

const updateCoords = (lat, lng) => {
    emit('update:latitude', parseFloat(lat).toFixed(7));
    emit('update:longitude', parseFloat(lng).toFixed(7));
};

onMounted(async () => {
    const apiKey = import.meta.env.VITE_GOOGLE_MAPS_KEY;

    if (!apiKey) {
        error.value = 'Google Maps API Key no configurada. Agrega VITE_GOOGLE_MAPS_KEY en el archivo .env';
        return;
    }

    try {
        const loader = new Loader({ apiKey, version: 'weekly' });
        const google = await loader.load();
        const { Map } = await google.maps.importLibrary('maps');
        const { AdvancedMarkerElement } = await google.maps.importLibrary('marker');

        const center = props.latitude && props.longitude
            ? { lat: parseFloat(props.latitude), lng: parseFloat(props.longitude) }
            : defaultCenter;

        map = new Map(mapContainer.value, {
            center,
            zoom: props.latitude ? 16 : 13,
            mapId: 'carniceria_saas',
            disableDefaultUI: false,
            zoomControl: true,
            streetViewControl: false,
            mapTypeControl: false,
            fullscreenControl: true,
            gestureHandling: 'greedy',
        });

        marker = new AdvancedMarkerElement({
            map,
            position: center,
            gmpDraggable: true,
            title: 'Ubicacion de la sucursal',
        });

        marker.addListener('dragend', () => {
            const pos = marker.position;
            updateCoords(pos.lat, pos.lng);
        });

        map.addListener('click', (e) => {
            const pos = e.latLng;
            marker.position = pos;
            updateCoords(pos.lat(), pos.lng());
        });

        mapReady.value = true;
    } catch (e) {
        error.value = 'Error al cargar Google Maps: ' + e.message;
    }
});

watch([() => props.latitude, () => props.longitude], ([lat, lng]) => {
    if (map && marker && lat && lng) {
        const pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
        if (!isNaN(pos.lat) && !isNaN(pos.lng)) {
            marker.position = pos;
        }
    }
});
</script>

<template>
    <div>
        <!-- Error state -->
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
        <div v-else class="overflow-hidden rounded-xl ring-1 ring-gray-200">
            <div ref="mapContainer" class="h-[300px] w-full bg-gray-100">
                <div v-if="!mapReady" class="flex h-full items-center justify-center">
                    <div class="text-center">
                        <svg class="mx-auto h-8 w-8 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                        <p class="mt-2 text-sm text-gray-400">Cargando mapa...</p>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-200 bg-gray-50 px-4 py-2.5">
                <p class="text-xs text-gray-500">Haz click en el mapa o arrastra el marcador para seleccionar la ubicacion.</p>
            </div>
        </div>
    </div>
</template>
