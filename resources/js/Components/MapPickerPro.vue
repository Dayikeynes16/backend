<script setup>
// MapPickerPro — selector de ubicación con confirmación explícita.
//
// El pin se renderiza con google.maps.Marker (no con overlay HTML/CSS) para
// evitar problemas de stacking context con Google Maps. El marker siempre
// está al centro: cuando el usuario arrastra el mapa, lo movemos vía
// center_changed para que el efecto sea "el mapa se desliza bajo el pin".
//
// Diseño reactivo: minimizamos la superficie reactiva. Las coordenadas
// pendientes son variables ordinarias; sólo `liveState` (un único ref) se
// muta atómicamente cuando hay cambios visibles. Esto evita que múltiples
// updates encolados crashen Vue durante el unmount.
//
// API:
//   <MapPickerPro
//     :latitude="form.latitude"
//     :longitude="form.longitude"
//     @confirmed="(lat, lng) => { form.latitude = lat; form.longitude = lng }"
//     @address-suggested="(addr) => { form.address = addr }" />

import { ref, watch, onMounted, onBeforeUnmount, computed, getCurrentInstance } from 'vue';

const props = defineProps({
    latitude: { type: [String, Number], default: null },
    longitude: { type: [String, Number], default: null },
    fallbackLat: { type: Number, default: 17.9891 },
    fallbackLng: { type: Number, default: -92.9475 },
});

const emit = defineEmits(['confirmed', 'address-suggested']);

// ─── Detección robusta de unmount ──────────────────────────────────
// `getCurrentInstance().isUnmounted` es el flag interno que Vue actualiza
// sincrónicamente cuando empieza a desmontar el componente. Es más
// confiable que un flag manual porque no depende de cuándo corre nuestro
// onBeforeUnmount relativo a las microtasks pendientes.
const _instance = getCurrentInstance();
const dead = () => !_instance || _instance.isUnmounted === true;

// ─── Estado de UI (refs mínimos) ────────────────────────────────────
const mapContainer = ref(null);
const mapReady = ref(false);
const error = ref('');
const locating = ref(false);
const suggestedAddress = ref('');

// liveState: única fuente reactiva para coords pendientes + estado del pin.
// Se actualiza atómicamente con un solo set => Vue programa un solo update.
const liveState = ref({
    pendingLat: null,
    pendingLng: null,
    coordsText: '—',
    hasPendingChange: false,
    pinState: 'pending', // 'pending' | 'moving' | 'confirmed'
});

// ─── Variables NO reactivas (Maps + control) ───────────────────────
let map = null;
let geocoder = null;
let centerMarker = null;
let mapListeners = [];
let geocodeDebounce = null;
let ignoreNextMove = false;
// pendingLat/Lng se manejan como variables ordinarias para que cambiar
// el centro del mapa no dispare la cadena reactiva en cada frame de drag.
let pendingLat = null;
let pendingLng = null;

// ─── Computed sobre props (única fuente: padre) ────────────────────
const confirmedLat = computed(() => {
    const v = props.latitude;
    if (v === null || v === '' || v === undefined) return null;
    const n = Number(v);
    return isNaN(n) ? null : n;
});
const confirmedLng = computed(() => {
    const v = props.longitude;
    if (v === null || v === '' || v === undefined) return null;
    const n = Number(v);
    return isNaN(n) ? null : n;
});

// Recalcula liveState a partir de pendingLat/Lng + confirmed*.
// Atómico: un solo set al ref. Sin lectura del ref interno.
const refreshLiveState = () => {
    if (dead()) return;
    const cLat = confirmedLat.value;
    const cLng = confirmedLng.value;

    let hasChange = false;
    if (pendingLat !== null && pendingLng !== null) {
        if (cLat === null || cLng === null) {
            hasChange = true;
        } else {
            hasChange = Math.abs(pendingLat - cLat) > 0.000005
                || Math.abs(pendingLng - cLng) > 0.000005;
        }
    }
    let pinState = 'pending';
    if (cLat !== null) pinState = hasChange ? 'moving' : 'confirmed';

    const coordsText = (pendingLat !== null && pendingLng !== null)
        ? `${pendingLat.toFixed(6)}, ${pendingLng.toFixed(6)}`
        : '—';

    liveState.value = {
        pendingLat,
        pendingLng,
        coordsText,
        hasPendingChange: hasChange,
        pinState,
    };
};

// Helpers de lectura para el template.
const pinState = computed(() => liveState.value.pinState);
const hasPendingChange = computed(() => liveState.value.hasPendingChange);
const coordsText = computed(() => liveState.value.coordsText);

// Texto de ayuda según estado.
const helperText = computed(() => {
    const s = liveState.value.pinState;
    if (s === 'confirmed') {
        return 'La ubicación está confirmada. Si necesitas ajustarla, mueve el mapa y vuelve a confirmar.';
    }
    if (s === 'moving') {
        return 'Has movido la ubicación. Confirma para guardar las nuevas coordenadas.';
    }
    return 'Mueve el mapa hasta que la punta del pin caiga sobre la entrada de la sucursal y confirma la ubicación.';
});

// ─── Icono del Marker nativo ───────────────────────────────────────
const PIN_PATH = 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z';
const buildIcon = (state) => {
    const colors = {
        pending: { fill: '#DC2626', stroke: '#7F1D1D' },
        moving: { fill: '#EA580C', stroke: '#7C2D12' },
        confirmed: { fill: '#059669', stroke: '#064E3B' },
    }[state];
    return {
        path: PIN_PATH,
        fillColor: colors.fill,
        fillOpacity: 1,
        strokeColor: colors.stroke,
        strokeWeight: 1.5,
        scale: 2.4,
        anchor: new google.maps.Point(12, 22),
        labelOrigin: new google.maps.Point(12, 9),
    };
};

// ─── Carga de Google Maps ──────────────────────────────────────────
const waitForGoogleMaps = () => new Promise((resolve, reject) => {
    if (window.google?.maps?.Map) return resolve();
    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (window.google?.maps?.Map) {
            clearInterval(interval);
            resolve();
        } else if (attempts > 50) {
            clearInterval(interval);
            reject(new Error('Google Maps no cargó.'));
        }
    }, 100);
});

const updateFromCenter = () => {
    if (dead() || !map || ignoreNextMove) { ignoreNextMove = false; return; }
    const center = map.getCenter();
    if (!center) return;
    pendingLat = center.lat();
    pendingLng = center.lng();
    refreshLiveState();
    debouncedReverseGeocode();
};

const debouncedReverseGeocode = () => {
    clearTimeout(geocodeDebounce);
    if (dead()) return;
    geocodeDebounce = setTimeout(() => {
        if (dead() || !geocoder || pendingLat === null || pendingLng === null) return;
        geocoder.geocode(
            { location: { lat: pendingLat, lng: pendingLng } },
            (results, status) => {
                if (dead()) return;
                if (status === 'OK' && results && results[0]) {
                    suggestedAddress.value = results[0].formatted_address;
                } else {
                    suggestedAddress.value = '';
                }
            },
        );
    }, 700);
};

onMounted(async () => {
    try {
        await waitForGoogleMaps();
        if (dead()) return;

        const startLat = confirmedLat.value ?? props.fallbackLat;
        const startLng = confirmedLng.value ?? props.fallbackLng;
        const startZoom = confirmedLat.value ? 18 : 13;

        pendingLat = startLat;
        pendingLng = startLng;

        map = new google.maps.Map(mapContainer.value, {
            center: { lat: startLat, lng: startLng },
            zoom: startZoom,
            disableDefaultUI: true,
            zoomControl: true,
            fullscreenControl: true,
            gestureHandling: 'greedy',
            clickableIcons: false,
        });
        geocoder = new google.maps.Geocoder();

        centerMarker = new google.maps.Marker({
            position: { lat: startLat, lng: startLng },
            map,
            icon: buildIcon(confirmedLat.value !== null ? 'confirmed' : 'pending'),
            clickable: false,
            zIndex: 9999,
            animation: confirmedLat.value === null ? google.maps.Animation.DROP : null,
        });

        const lCenter = map.addListener('center_changed', () => {
            if (dead() || !centerMarker || !map) return;
            const c = map.getCenter();
            if (c) centerMarker.setPosition(c);
        });
        const lIdle = map.addListener('idle', updateFromCenter);
        mapListeners.push(lCenter, lIdle);

        mapReady.value = true;
        refreshLiveState();

        if (confirmedLat.value !== null) debouncedReverseGeocode();
    } catch (e) {
        if (!dead()) error.value = 'No se pudo cargar el mapa. Revisa la conexión.';
    }
});

// Watch sync: actualiza el icono del marker inmediatamente cuando pinState
// cambia. Sync evita el scheduler de Vue (sin microtasks que puedan correr
// después del unmount).
watch(pinState, (state) => {
    if (dead() || !centerMarker) return;
    try { centerMarker.setIcon(buildIcon(state)); } catch (e) { /* ignore */ }
}, { flush: 'sync' });

// Si props.lat/lng cambian, refrescamos liveState y paneamos el mapa al
// nuevo punto. Side-effect puro sobre Maps, sin tocar refs externos.
watch([confirmedLat, confirmedLng], ([newLat, newLng]) => {
    if (dead() || !map) return;
    refreshLiveState();
    if (newLat === null || newLng === null) return;
    const center = map.getCenter();
    if (!center) return;
    if (Math.abs(center.lat() - newLat) > 0.000005 || Math.abs(center.lng() - newLng) > 0.000005) {
        ignoreNextMove = true;
        try { map.panTo({ lat: newLat, lng: newLng }); } catch (e) { /* ignore */ }
    }
}, { flush: 'sync' });

// ─── Cleanup en unmount ────────────────────────────────────────────
onBeforeUnmount(() => {
    clearTimeout(geocodeDebounce);
    geocodeDebounce = null;

    if (window.google?.maps?.event) {
        for (const l of mapListeners) {
            try { window.google.maps.event.removeListener(l); } catch (e) { /* ignore */ }
        }
        if (map) {
            try { window.google.maps.event.clearInstanceListeners(map); } catch (e) { /* ignore */ }
        }
    }
    mapListeners = [];

    if (centerMarker) {
        try { centerMarker.setMap(null); } catch (e) { /* ignore */ }
        centerMarker = null;
    }
    map = null;
    geocoder = null;
});

// ─── Acciones ──────────────────────────────────────────────────────
const confirmLocation = () => {
    if (pendingLat === null || pendingLng === null) return;
    emit('confirmed', pendingLat, pendingLng);
};

const useMyLocation = () => {
    if (!navigator.geolocation || !map) return;
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            if (dead() || !map) return;
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            map.panTo({ lat, lng });
            if (map.getZoom() < 17) map.setZoom(18);
            locating.value = false;
        },
        () => { if (!dead()) locating.value = false; },
        { enableHighAccuracy: true, timeout: 10000 },
    );
};

const useSuggestedAddress = () => {
    if (suggestedAddress.value) emit('address-suggested', suggestedAddress.value);
};
</script>

<template>
    <div class="space-y-3">
        <!-- Mapa con marker nativo (rojo/naranja/verde según estado) -->
        <div class="relative overflow-hidden rounded-2xl ring-1 ring-gray-200">
            <div ref="mapContainer" class="h-[420px] w-full bg-gray-100"></div>

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
            <div v-if="error" class="absolute inset-0 flex items-center justify-center bg-gray-100 px-6 text-center z-10">
                <p class="text-sm font-semibold text-amber-700">{{ error }}</p>
            </div>

            <!-- Floating "Mi ubicación" -->
            <button v-if="mapReady" type="button" @click="useMyLocation" :disabled="locating"
                class="absolute left-3 top-3 z-[5] inline-flex items-center gap-2 rounded-xl bg-white/95 px-3 py-2 text-sm font-semibold text-gray-700 shadow-md backdrop-blur ring-1 ring-gray-200 transition hover:bg-white disabled:opacity-50">
                <svg v-if="!locating" class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                <svg v-else class="h-4 w-4 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                {{ locating ? 'Buscando...' : 'Mi ubicación' }}
            </button>

            <!-- Status badge -->
            <div v-if="mapReady" class="absolute right-3 top-3 z-[5]">
                <span :class="['inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold shadow-md backdrop-blur ring-1',
                    pinState === 'confirmed' ? 'bg-emerald-50/95 text-emerald-700 ring-emerald-200' :
                    pinState === 'moving' ? 'bg-orange-50/95 text-orange-700 ring-orange-200' :
                    'bg-red-50/95 text-red-700 ring-red-200']">
                    <span :class="['h-1.5 w-1.5 rounded-full',
                        pinState === 'confirmed' ? 'bg-emerald-500' :
                        pinState === 'moving' ? 'bg-orange-500 animate-pulse' :
                        'bg-red-500 animate-pulse']" />
                    <template v-if="pinState === 'confirmed'">Ubicación confirmada</template>
                    <template v-else-if="pinState === 'moving'">Sin confirmar — mueve y confirma</template>
                    <template v-else>Coloca el pin en la entrada</template>
                </span>
            </div>
        </div>

        <!-- Mensaje de ayuda contextual + coords -->
        <div class="flex flex-col gap-1.5 rounded-xl px-4 py-3 ring-1 sm:flex-row sm:items-center sm:justify-between"
            :class="pinState === 'confirmed' ? 'bg-emerald-50/60 ring-emerald-100' : 'bg-amber-50/60 ring-amber-100'">
            <p class="flex items-start gap-2 text-xs sm:text-sm"
                :class="pinState === 'confirmed' ? 'text-emerald-800' : 'text-amber-900'">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path v-if="pinState === 'confirmed'" stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span>{{ helperText }}</span>
            </p>
            <span class="font-mono text-[11px] tabular-nums text-gray-500 sm:shrink-0">{{ coordsText }}</span>
        </div>

        <!-- Reverse geocoding suggestion (sin Transition: el leave-animation
             durante un unmount estaba crasheando Vue). -->
        <div v-if="suggestedAddress" class="flex items-start gap-3 rounded-xl bg-blue-50 px-4 py-3 ring-1 ring-blue-100">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-blue-700/70">Dirección detectada</p>
                <p class="mt-0.5 text-sm font-medium text-blue-900">{{ suggestedAddress }}</p>
            </div>
            <button type="button" @click="useSuggestedAddress"
                class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 ring-1 ring-blue-200 transition hover:bg-blue-50">
                Usar
            </button>
        </div>

        <!-- Botón Confirmar -->
        <div class="flex items-center gap-2">
            <button type="button" @click="confirmLocation"
                :disabled="!hasPendingChange && pinState === 'confirmed'"
                :class="['inline-flex flex-1 items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold shadow-sm transition active:scale-[0.98]',
                    hasPendingChange
                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                        : pinState === 'confirmed'
                            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 cursor-default'
                            : 'bg-gray-200 text-gray-500 cursor-not-allowed']">
                <svg v-if="pinState === 'confirmed' && !hasPendingChange" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                <template v-if="hasPendingChange">Confirmar esta ubicación</template>
                <template v-else-if="pinState === 'confirmed'">Ubicación confirmada</template>
                <template v-else>Mueve el mapa para elegir</template>
            </button>
        </div>
    </div>
</template>
