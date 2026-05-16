<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useCart } from '../composables/useCart.js';
import { useMenu } from '../composables/useMenu.js';
import { useContact } from '../composables/useContact.js';
import { createApi } from '../api.js';
import LocationPicker from '../components/LocationPicker.vue';

const route = useRoute();
const router = useRouter();
const tenantSlug = computed(() => route.params.tenantSlug);
const branchId = computed(() => Number(route.params.branchId));

const cart = useCart(branchId.value);
const contact = useContact();
const { branch, fetch: fetchMenu } = useMenu(tenantSlug.value, branchId.value);

const deliveryType = ref('pickup');
const address = ref(contact.last_address || '');
const lat = ref(contact.last_lat);
const lng = ref(contact.last_lng);
const addressAutoFilled = ref(false);

const onAutoAddress = (formatted) => {
    // Only auto-fill if user hasn't typed anything yet, or address was auto-filled previously
    if (!address.value || addressAutoFilled.value) {
        address.value = formatted;
        addressAutoFilled.value = true;
    }
};

// Restore raw digits from previously saved formatted string (or whatever) to
// hydrate the input. Phone is stored locally in formatted form (`+52 XXX XXX XXXX`)
// because that's what we send to the backend; the display format matches storage.
const contactName = ref(contact.contact_name || '');
const contactPhone = ref(contact.contact_phone || '');
const paymentMethod = ref('cash');
const cartNote = ref('');
const honeypot = ref('');

// === Delivery quote state ===
// Status machine: idle | calculating | ok | out_of_range | unavailable
// Auto-fetch en cada cambio de pin (debounce 1500ms, skip si delta < 100m).
const deliveryQuote = ref(null);
const deliveryStatus = ref('idle');
const deliveryError = ref(null); // shape: { distance_km, max_km } cuando out_of_range
let deliveryDebounceTimer = null;
let lastQuotedCoords = null; // { lat, lng } o null

const submitting = ref(false);
const submitError = ref(null);

// Refs para scroll-to-section desde el ticket de resumen
const sectionDeliveryRef = ref(null);
const sectionAddressRef = ref(null);
const sectionContactRef = ref(null);
const sectionPaymentRef = ref(null);

onMounted(() => {
    fetchMenu();
    if (cart.count.value === 0) {
        router.replace({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
    }
});

onBeforeUnmount(() => {
    clearTimeout(deliveryDebounceTimer);
});

watch(branch, (b) => {
    if (!b) return;
    // Default payment method to first enabled
    if (b.payment_methods?.length && !b.payment_methods.includes(paymentMethod.value)) {
        paymentMethod.value = b.payment_methods[0];
    }
    // If branch doesn't support pickup, force delivery (and vice versa)
    if (!b.pickup_enabled && b.delivery_enabled) deliveryType.value = 'delivery';
    if (!b.delivery_enabled && b.pickup_enabled) deliveryType.value = 'pickup';
});

const paymentLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const paymentIcon = (m) => ({ cash: '💵', card: '💳', transfer: '🏦' }[m] || '💳');

// === Haversine para skip <100m ===
const haversineKm = (a, b) => {
    const R = 6371;
    const toRad = (deg) => (deg * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const lat1 = toRad(a.lat);
    const lat2 = toRad(b.lat);
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(x));
};

const fetchDeliveryQuote = async () => {
    if (!lat.value || !lng.value || deliveryType.value !== 'delivery') return false;
    deliveryStatus.value = 'calculating';
    try {
        const { data } = await createApi(tenantSlug.value).post(
            `/branches/${branchId.value}/delivery/quote`,
            { lat: lat.value, lng: lng.value },
        );
        deliveryQuote.value = data;
        deliveryStatus.value = 'ok';
        deliveryError.value = null;
        lastQuotedCoords = { lat: lat.value, lng: lng.value };
        return true;
    } catch (e) {
        const errCode = e.response?.data?.error;
        deliveryQuote.value = null;
        if (errCode === 'out_of_range') {
            deliveryStatus.value = 'out_of_range';
            deliveryError.value = {
                distance_km: e.response?.data?.distance_km,
                max_km: branch.value?.max_delivery_km,
            };
        } else {
            deliveryStatus.value = 'unavailable';
            deliveryError.value = null;
        }
        return false;
    }
};

const scheduleAutoQuote = () => {
    clearTimeout(deliveryDebounceTimer);
    if (!lat.value || !lng.value || deliveryType.value !== 'delivery') return;
    // Skip si el pin se movió < 100m respecto al último quote válido
    if (lastQuotedCoords && deliveryQuote.value) {
        const km = haversineKm(lastQuotedCoords, { lat: lat.value, lng: lng.value });
        if (km < 0.1) return; // mantenemos el quote actual
    }
    // Feedback visual inmediato mientras corre el debounce + fetch
    deliveryStatus.value = 'calculating';
    deliveryQuote.value = null;
    deliveryError.value = null;
    deliveryDebounceTimer = setTimeout(fetchDeliveryQuote, 1500);
};

watch([lat, lng], scheduleAutoQuote);

watch(deliveryType, (newType) => {
    clearTimeout(deliveryDebounceTimer);
    deliveryQuote.value = null;
    deliveryError.value = null;
    lastQuotedCoords = null;
    if (newType === 'delivery' && lat.value && lng.value) {
        scheduleAutoQuote();
    } else {
        deliveryStatus.value = 'idle';
    }
});

// === Phone mask: +52 XXX XXX XXXX ===
const phoneDigits = computed(() => contactPhone.value.replace(/\D/g, '').slice(-10));
const phoneIsValid = computed(() => phoneDigits.value.length === 10);

const onPhoneInput = (e) => {
    const d = e.target.value.replace(/\D/g, '').slice(-10);
    if (d.length === 0) contactPhone.value = '';
    else if (d.length <= 3) contactPhone.value = `+52 ${d}`;
    else if (d.length <= 6) contactPhone.value = `+52 ${d.slice(0, 3)} ${d.slice(3)}`;
    else contactPhone.value = `+52 ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`;
};

// === Totales y validaciones ===
const deliveryFee = computed(() => (deliveryType.value === 'delivery' ? deliveryQuote.value?.fee || 0 : 0));
const total = computed(() => Number(cart.subtotal.value) + Number(deliveryFee.value));

const belowMin = computed(() => {
    if (!branch.value?.min_order_amount) return false;
    return cart.subtotal.value < branch.value.min_order_amount;
});

const canSubmit = computed(() => {
    if (submitting.value) return false;
    if (cart.count.value === 0) return false;
    if (!contactName.value.trim()) return false;
    if (!phoneIsValid.value) return false;
    if (belowMin.value) return false;
    if (deliveryType.value === 'delivery') {
        if (!lat.value || !lng.value) return false;
        if (!address.value.trim()) return false;
        if (deliveryStatus.value === 'out_of_range') return false;
        // 'calculating' y 'unavailable' son permitidos: el pre-flight reintentará.
    }
    return true;
});

// === Switch helpers para mini-✏️ del ticket ===
const switchToPickup = () => {
    if (branch.value?.pickup_enabled) deliveryType.value = 'pickup';
};
const scrollToSection = (sectionRef) => {
    sectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

// === Submit ===
const submitOrder = async () => {
    if (!canSubmit.value) return;

    // Pre-open a blank tab SYNCHRONOUSLY — esto preserva el user-gesture en
    // iOS Safari para que el universal link de WhatsApp abra la app nativa.
    let waWindow = null;
    try {
        waWindow = window.open('about:blank', '_blank');
    } catch {
        waWindow = null;
    }

    submitting.value = true;
    submitError.value = null;

    // Persist contact para próximas visitas
    contact.contact_name = contactName.value;
    contact.contact_phone = contactPhone.value;
    if (deliveryType.value === 'delivery') {
        contact.last_address = address.value;
        contact.last_lat = lat.value;
        contact.last_lng = lng.value;
    }

    const abort = (message) => {
        submitError.value = message;
        submitting.value = false;
        if (waWindow && !waWindow.closed) {
            try { waWindow.close(); } catch {}
        }
    };

    // Pre-flight: si no tenemos un quote OK fresco, lo recalculamos antes de
    // crear la orden. Si ya tenemos status=ok, ahorramos la llamada (la auto-
    // quote ya corrió mientras el usuario terminaba de llenar el formulario).
    if (deliveryType.value === 'delivery' && deliveryStatus.value !== 'ok') {
        clearTimeout(deliveryDebounceTimer);
        const ok = await fetchDeliveryQuote();
        if (!ok) {
            const msg = deliveryStatus.value === 'out_of_range'
                ? `Fuera del rango de entrega (${deliveryError.value?.distance_km?.toFixed(1) ?? '—'} km).`
                : 'No se pudo calcular el envío. Intenta de nuevo.';
            abort(msg);
            return;
        }
    }

    try {
        const payload = {
            items: cart.state.items.map((i) => ({
                product_id: i.product_id,
                quantity: i.quantity,
                presentation_id: i.presentation_id,
                notes: i.notes,
            })),
            delivery_type: deliveryType.value,
            delivery_address: deliveryType.value === 'delivery' ? address.value : null,
            delivery_lat: deliveryType.value === 'delivery' ? lat.value : null,
            delivery_lng: deliveryType.value === 'delivery' ? lng.value : null,
            contact_name: contactName.value,
            contact_phone: contactPhone.value,
            payment_method: paymentMethod.value,
            cart_note: cartNote.value || null,
            honeypot: honeypot.value,
        };

        const { data } = await createApi(tenantSlug.value).post(
            `/branches/${branchId.value}/orders`,
            payload,
        );

        sessionStorage.setItem(`order_${data.sale_id}`, JSON.stringify(data));
        cart.clear();

        if (data.whatsapp_url) {
            if (waWindow && !waWindow.closed) {
                waWindow.location.href = data.whatsapp_url;
            } else {
                window.location.href = data.whatsapp_url;
                return;
            }
        }

        router.replace({
            name: 'confirmed',
            params: { tenantSlug: tenantSlug.value, branchId: branchId.value, saleId: data.sale_id },
        });
    } catch (e) {
        if (waWindow && !waWindow.closed) {
            try { waWindow.close(); } catch {}
        }
        const err = e.response?.data?.error;
        submitError.value = {
            closed: 'La sucursal está cerrada en este momento.',
            below_minimum: `Pedido mínimo $${e.response?.data?.min_order_amount?.toFixed(2) || '—'}.`,
            please_contact_branch: 'Por favor contacta directamente a la sucursal.',
            out_of_range: 'La dirección está fuera del rango.',
            invalid_products: 'Algún producto ya no está disponible. Actualiza tu carrito.',
            quote_unavailable: 'No se pudo calcular el envío.',
        }[err] || 'No se pudo procesar el pedido. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="min-h-screen bg-gray-50 pb-40">
        <header class="sticky top-0 z-20 border-b border-gray-100 bg-white px-4 py-3.5 shadow-sm">
            <div class="flex items-center gap-3">
                <button @click="router.back()" aria-label="Volver"
                    class="flex h-11 w-11 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-800">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Confirmar pedido</h1>
            </div>
        </header>

        <main class="mx-auto max-w-lg space-y-5 px-4 py-5">
            <!-- 1. Delivery type -->
            <section ref="sectionDeliveryRef" v-if="branch" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                <p class="text-base font-bold text-gray-900">¿Cómo lo quieres?</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" v-if="branch.pickup_enabled" @click="deliveryType = 'pickup'"
                        :class="['rounded-xl p-4 text-left transition ring-1',
                            deliveryType === 'pickup' ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-800 ring-gray-200 hover:bg-gray-50']">
                        <div class="text-2xl">🏪</div>
                        <p class="mt-1 text-base font-bold">Recoger</p>
                        <p class="text-sm text-gray-500">En sucursal · Gratis</p>
                    </button>
                    <button type="button" v-if="branch.delivery_enabled" @click="deliveryType = 'delivery'"
                        :class="['rounded-xl p-4 text-left transition ring-1',
                            deliveryType === 'delivery' ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-800 ring-gray-200 hover:bg-gray-50']">
                        <div class="text-2xl">🏍️</div>
                        <p class="mt-1 text-base font-bold">Envío a domicilio</p>
                        <p class="text-sm text-gray-500">Calculamos al ubicar tu dirección</p>
                    </button>
                </div>
            </section>

            <!-- 2. Delivery address + map -->
            <section ref="sectionAddressRef" v-if="deliveryType === 'delivery'" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-5 py-4">
                    <p class="text-base font-bold text-gray-900">¿Dónde entregamos?</p>
                </div>

                <LocationPicker
                    v-model:latitude="lat"
                    v-model:longitude="lng"
                    :branch-lat="branch?.latitude ? Number(branch.latitude) : null"
                    :branch-lng="branch?.longitude ? Number(branch.longitude) : null"
                    @geocoded="onAutoAddress"
                />

                <!-- Out-of-range banner: visible y bloqueante con CTA a pickup -->
                <div v-if="deliveryStatus === 'out_of_range'" class="border-y border-red-200 bg-red-50 px-4 py-3">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-xl">⚠️</div>
                        <div class="min-w-0 flex-1">
                            <p class="text-base font-bold text-red-800">Fuera del rango de entrega</p>
                            <p class="mt-1 text-sm text-red-700">
                                Esta dirección está a {{ deliveryError?.distance_km?.toFixed(1) ?? '—' }} km de la sucursal<span v-if="deliveryError?.max_km"> — entregamos hasta {{ Number(deliveryError.max_km).toFixed(1) }} km.</span>
                            </p>
                            <button v-if="branch?.pickup_enabled" type="button" @click="switchToPickup"
                                class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-bold text-red-700 ring-1 ring-red-200 transition hover:bg-red-100">
                                🏪 Cambiar a recoger en sucursal
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Quote unavailable: warning suave, NO bloquea -->
                <div v-else-if="deliveryStatus === 'unavailable'" class="border-y border-amber-200 bg-amber-50 px-4 py-3">
                    <p class="flex items-start gap-2 text-sm font-medium text-amber-800">
                        <span>⚠️</span>
                        <span>No pudimos calcular el envío en este momento. Lo intentaremos al confirmar el pedido.</span>
                    </p>
                </div>

                <div class="space-y-4 p-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800">Calle, número y referencias</label>
                        <input v-model="address" type="text" placeholder="Ej: Calle Juárez 123, casa blanca de 2 pisos"
                            class="mt-1.5 block w-full rounded-xl border-gray-200 text-base focus:border-red-400 focus:ring-red-300" />
                        <p class="mt-1.5 text-sm text-gray-500">Auto-llenamos con la ubicación. Completa con número, color de casa o referencias.</p>
                    </div>

                    <!-- Live quote card: distance + duration + fee -->
                    <div class="rounded-xl ring-1 ring-gray-200 overflow-hidden">
                        <!-- Calculating state -->
                        <div v-if="deliveryStatus === 'calculating'" class="flex items-center gap-3 bg-gray-50 px-4 py-3.5">
                            <svg class="h-5 w-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            <p class="text-base font-medium text-gray-700">Calculando envío…</p>
                        </div>
                        <!-- OK state: muestra distance + duration + fee -->
                        <div v-else-if="deliveryStatus === 'ok' && deliveryQuote" class="bg-emerald-50 px-4 py-3.5">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-emerald-700">ENVÍO CALCULADO</p>
                                    <p class="mt-1 text-base text-emerald-900">
                                        🚗 <span class="font-bold tabular-nums">{{ deliveryQuote.distance_km.toFixed(1) }} km</span>
                                        <span v-if="deliveryQuote.duration_min" class="text-emerald-700"> · ~{{ Math.round(deliveryQuote.duration_min) }} min</span>
                                    </p>
                                </div>
                                <p class="font-mono text-2xl font-extrabold tabular-nums text-emerald-700">${{ Number(deliveryQuote.fee).toFixed(2) }}</p>
                            </div>
                        </div>
                        <!-- Idle state (sin coords aún) -->
                        <div v-else class="bg-gray-50 px-4 py-3.5 text-sm text-gray-600">
                            ℹ️ El costo se calcula automáticamente al ubicar tu dirección en el mapa.
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. Contact -->
            <section ref="sectionContactRef" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                <p class="text-base font-bold text-gray-900">Tus datos</p>

                <label class="mt-4 block text-sm font-semibold text-gray-800">Nombre</label>
                <input v-model="contactName" type="text" placeholder="Tu nombre"
                    class="mt-1.5 block w-full rounded-xl border-gray-200 text-base focus:border-red-400 focus:ring-red-300" />

                <label class="mt-4 block text-sm font-semibold text-gray-800">WhatsApp / Teléfono</label>
                <input :value="contactPhone" @input="onPhoneInput" type="tel" inputmode="numeric" autocomplete="tel" placeholder="+52 993 123 4567"
                    class="mt-1.5 block w-full rounded-xl border-gray-200 font-mono text-base tabular-nums focus:border-red-400 focus:ring-red-300" />
                <p v-if="contactPhone && !phoneIsValid" class="mt-1.5 text-sm font-medium text-amber-700">
                    Ingresa los 10 dígitos de tu WhatsApp.
                </p>

                <!-- Honeypot: hidden field that should remain empty -->
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label>No llenar este campo</label>
                    <input v-model="honeypot" type="text" tabindex="-1" autocomplete="off" />
                </div>
            </section>

            <!-- 4. Payment method -->
            <section ref="sectionPaymentRef" v-if="branch" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                <p class="text-base font-bold text-gray-900">Cómo vas a pagar</p>
                <div class="mt-3 grid gap-2">
                    <button v-for="m in branch.payment_methods" :key="m" type="button" @click="paymentMethod = m"
                        :class="['flex items-center gap-3 rounded-xl px-4 py-3.5 text-left text-base font-semibold transition ring-1',
                            paymentMethod === m ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-800 ring-gray-200 hover:bg-gray-50']">
                        <span class="text-xl">{{ paymentIcon(m) }}</span>
                        <span>{{ paymentLabel(m) }}</span>
                    </button>
                </div>
            </section>

            <!-- 5. Cart note -->
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                <label class="block text-base font-bold text-gray-900">Notas para la sucursal <span class="text-sm font-normal text-gray-500">(opcional)</span></label>
                <textarea v-model="cartNote" rows="2" maxlength="500" placeholder="Ej: tocar timbre, casa color verde…"
                    class="mt-2 block w-full rounded-xl border-gray-200 text-base focus:border-red-400 focus:ring-red-300"></textarea>
            </section>

            <!-- 6. Ticket-style summary: visible y completo antes de confirmar -->
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 bg-gray-50/60 px-5 py-3.5">
                    <p class="text-base font-bold text-gray-900">🧾 Tu pedido</p>
                </div>

                <div class="divide-y divide-gray-100 px-5">
                    <!-- Productos -->
                    <div class="flex items-center justify-between py-3.5">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-gray-900">Productos</p>
                            <p class="text-sm text-gray-500">{{ cart.count.value }} {{ cart.count.value === 1 ? 'artículo' : 'artículos' }}</p>
                        </div>
                        <p class="font-mono text-base font-bold tabular-nums text-gray-900">${{ cart.subtotal.value.toFixed(2) }}</p>
                    </div>

                    <!-- Modo: pickup -->
                    <div v-if="deliveryType === 'pickup'" class="flex items-center justify-between py-3.5">
                        <div class="min-w-0 flex-1 pr-3">
                            <div class="flex items-center gap-2">
                                <p class="text-base font-semibold text-gray-900">🏪 Recoger en sucursal</p>
                                <button type="button" @click="scrollToSection(sectionDeliveryRef)" aria-label="Cambiar"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-red-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                </button>
                            </div>
                            <p class="text-sm text-gray-500">{{ branch?.name || 'En sucursal' }}</p>
                        </div>
                        <p class="text-base font-semibold text-emerald-600">Gratis</p>
                    </div>

                    <!-- Modo: delivery -->
                    <div v-else class="py-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-base font-semibold text-gray-900">🛵 Envío a domicilio</p>
                                    <button type="button" @click="scrollToSection(sectionAddressRef)" aria-label="Editar dirección"
                                        class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-red-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                    </button>
                                </div>
                                <p v-if="address" class="mt-1 truncate text-sm text-gray-600">📍 {{ address }}</p>
                                <p v-else class="mt-1 text-sm italic text-gray-400">Aún sin dirección.</p>

                                <p v-if="deliveryStatus === 'ok' && deliveryQuote" class="mt-1 text-sm text-gray-600">
                                    🚗 <span class="font-mono tabular-nums">{{ deliveryQuote.distance_km.toFixed(1) }} km</span>
                                    <span v-if="deliveryQuote.duration_min"> · ~{{ Math.round(deliveryQuote.duration_min) }} min</span>
                                </p>
                                <p v-else-if="deliveryStatus === 'calculating'" class="mt-1 text-sm italic text-gray-500">Calculando…</p>
                                <p v-else-if="deliveryStatus === 'out_of_range'" class="mt-1 text-sm font-semibold text-red-600">Fuera de rango</p>
                                <p v-else-if="deliveryStatus === 'unavailable'" class="mt-1 text-sm italic text-amber-700">Se recalculará al confirmar</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p v-if="deliveryStatus === 'ok' && deliveryQuote" class="font-mono text-base font-bold tabular-nums text-gray-900">${{ Number(deliveryQuote.fee).toFixed(2) }}</p>
                                <p v-else-if="deliveryStatus === 'calculating'"><svg class="h-5 w-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg></p>
                                <p v-else class="text-sm italic text-gray-400">—</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total -->
                    <div class="flex items-center justify-between py-4">
                        <p class="text-lg font-bold text-gray-900">Total</p>
                        <p v-if="deliveryType === 'pickup' || (deliveryStatus === 'ok' && deliveryQuote)" class="font-mono text-2xl font-extrabold tabular-nums text-gray-900">
                            ${{ total.toFixed(2) }}
                        </p>
                        <p v-else class="text-right">
                            <span class="font-mono text-lg font-bold tabular-nums text-gray-700">${{ cart.subtotal.value.toFixed(2) }}</span>
                            <span class="block text-xs font-normal text-gray-500">+ envío por calcular</span>
                        </p>
                    </div>

                    <!-- Contacto + método de pago -->
                    <div class="space-y-1 py-3">
                        <button type="button" @click="scrollToSection(sectionContactRef)"
                            class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50">
                            <span class="flex min-w-0 items-center gap-2 text-sm text-gray-700">
                                <span class="text-base">👤</span>
                                <span class="truncate">{{ contactName || 'Sin nombre' }}<span v-if="phoneIsValid"> · {{ contactPhone }}</span></span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                        </button>
                        <button type="button" @click="scrollToSection(sectionPaymentRef)"
                            class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-gray-50">
                            <span class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="text-base">{{ paymentIcon(paymentMethod) }}</span>
                                <span>{{ paymentLabel(paymentMethod) }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                        </button>
                    </div>
                </div>

                <p v-if="belowMin" class="m-4 mt-0 rounded-lg bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-800">
                    ⚠️ Pedido mínimo ${{ branch.min_order_amount.toFixed(2) }}. Agrega más productos.
                </p>
            </section>
        </main>

        <!-- Sticky footer -->
        <div class="fixed bottom-0 left-0 right-0 border-t border-gray-100 bg-white p-4 shadow-lg">
            <div v-if="submitError" class="mb-3 rounded-lg bg-red-50 px-3 py-2.5 text-sm font-semibold text-red-700 ring-1 ring-red-200">
                {{ submitError }}
            </div>
            <div class="mx-auto max-w-lg">
                <button @click="submitOrder" :disabled="!canSubmit"
                    class="flex w-full items-center justify-between gap-3 rounded-2xl bg-red-600 px-5 py-4 text-base font-bold text-white shadow-lg transition hover:bg-red-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50">
                    <span class="flex items-center gap-2">
                        <svg v-if="submitting" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                        {{ submitting ? 'Procesando pedido…' : 'Confirmar pedido' }}
                    </span>
                    <span v-if="deliveryType === 'pickup' || (deliveryStatus === 'ok' && deliveryQuote)" class="rounded-full bg-white/20 px-3 py-1 font-mono tabular-nums">${{ total.toFixed(2) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
