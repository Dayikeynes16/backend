<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed, onMounted, ref, watch } from 'vue';
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

const contactName = ref(contact.contact_name || '');
const contactPhone = ref(contact.contact_phone || '');
const paymentMethod = ref('cash');
const cartNote = ref('');
const honeypot = ref('');

const deliveryQuote = ref(null);
const deliveryLoading = ref(false);
const deliveryError = ref(null);

const submitting = ref(false);
const submitError = ref(null);

onMounted(() => {
    fetchMenu();
    if (cart.count.value === 0) {
        router.replace({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
    }
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

// Delivery fee is calculated ONLY when the user presses "Confirmar pedido".
// We don't hit Google Matrix on every pin drag — that would be slow and expensive.
const fetchDeliveryQuote = async () => {
    if (!lat.value || !lng.value) return false;
    deliveryLoading.value = true;
    deliveryError.value = null;
    deliveryQuote.value = null;

    try {
        const { data } = await createApi(tenantSlug.value).post(
            `/branches/${branchId.value}/delivery/quote`,
            { lat: lat.value, lng: lng.value },
        );
        deliveryQuote.value = data;
        return true;
    } catch (e) {
        const err = e.response?.data?.error;
        if (err === 'out_of_range') {
            deliveryError.value = 'Esta dirección está fuera del rango de entrega. Intenta otra o elige recolección.';
        } else if (err === 'quote_unavailable') {
            deliveryError.value = 'No se pudo calcular el envío en este momento. Intenta de nuevo.';
        } else {
            deliveryError.value = 'Error al calcular el envío.';
        }
        return false;
    } finally {
        deliveryLoading.value = false;
    }
};

// Reset quote when the user moves the pin (fee might be stale now) but DO NOT auto-refetch.
watch([lat, lng], () => {
    deliveryQuote.value = null;
    deliveryError.value = null;
});

watch(deliveryType, () => {
    deliveryQuote.value = null;
    deliveryError.value = null;
});

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
    if (!/^\+?[0-9\s\-\(\)]{10,20}$/.test(contactPhone.value)) return false;
    if (belowMin.value) return false;
    if (deliveryType.value === 'delivery') {
        if (!lat.value || !lng.value) return false;
        if (!address.value.trim()) return false;
    }
    return true;
});

const submitOrder = async () => {
    if (!canSubmit.value) return;

    // Pre-open a blank tab SYNCHRONOUSLY — this preserves the iOS Safari
    // user-gesture context so the WhatsApp Universal Link fires later.
    // Without this, iOS may fall back to web.whatsapp.com instead of opening
    // the native app. Desktop just gets a new tab.
    let waWindow = null;
    try {
        waWindow = window.open('about:blank', '_blank');
    } catch {
        waWindow = null;
    }

    submitting.value = true;
    submitError.value = null;

    // Persist contact for next time
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

    // Pre-flight: if delivery, verify coverage first so we can surface the
    // specific reason (out of range / quote unavailable) before hitting /orders.
    if (deliveryType.value === 'delivery') {
        const ok = await fetchDeliveryQuote();
        if (!ok) {
            abort(deliveryError.value);
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

        // Direct the pre-opened tab (or fall back to same-tab nav) to WhatsApp.
        // Current tab goes to the Confirmed page so back-button has a home.
        if (data.whatsapp_url) {
            if (waWindow && !waWindow.closed) {
                waWindow.location.href = data.whatsapp_url;
            } else {
                // Popup was blocked — fall back to same-tab nav. User stays on
                // this flow; the Confirmed page can be reached via back-button
                // with the fallback "Abrir WhatsApp" button.
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
                <button @click="router.back()" class="rounded-full p-2 text-gray-400 transition hover:bg-gray-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                </button>
                <h1 class="text-lg font-bold text-gray-900">Confirmar pedido</h1>
            </div>
        </header>

        <main class="mx-auto max-w-lg space-y-5 px-4 py-5">
            <!-- Delivery type -->
            <section v-if="branch" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">¿Cómo lo quieres?</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" v-if="branch.pickup_enabled" @click="deliveryType = 'pickup'"
                        :class="['rounded-xl p-4 text-left transition ring-1',
                            deliveryType === 'pickup' ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50']">
                        <div class="text-xl">🏪</div>
                        <p class="mt-1 text-sm font-bold">Recoger</p>
                        <p class="text-xs text-gray-500">En sucursal · Gratis</p>
                    </button>
                    <button type="button" v-if="branch.delivery_enabled" @click="deliveryType = 'delivery'"
                        :class="['rounded-xl p-4 text-left transition ring-1',
                            deliveryType === 'delivery' ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50']">
                        <div class="text-xl">🏍️</div>
                        <p class="mt-1 text-sm font-bold">Envío a domicilio</p>
                        <p class="text-xs text-gray-500">Se calcula al ingresar dirección</p>
                    </button>
                </div>
            </section>

            <!-- Delivery address -->
            <section v-if="deliveryType === 'delivery'" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="border-b border-gray-100 px-4 py-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-500">¿Dónde entregamos?</p>
                </div>

                <LocationPicker
                    v-model:latitude="lat"
                    v-model:longitude="lng"
                    :branch-lat="branch?.latitude ? Number(branch.latitude) : null"
                    :branch-lng="branch?.longitude ? Number(branch.longitude) : null"
                    @geocoded="onAutoAddress"
                />

                <div class="space-y-3 p-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Calle, número y referencias</label>
                        <input v-model="address" type="text" placeholder="Ej: Calle Juárez 123, casa blanca de 2 pisos"
                            class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />
                        <p class="mt-1 text-[11px] text-gray-400">Auto-llenamos con la ubicación. Completa con número, color de casa o referencias.</p>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-3 text-xs text-gray-500 ring-1 ring-gray-100">
                        ℹ️ El costo de envío se calcula al confirmar el pedido.
                    </div>
                </div>
            </section>

            <!-- Contact -->
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Tus datos</p>

                <label class="mt-3 block text-xs font-medium text-gray-600">Nombre</label>
                <input v-model="contactName" type="text" placeholder="Tu nombre"
                    class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />

                <label class="mt-3 block text-xs font-medium text-gray-600">WhatsApp / Teléfono</label>
                <input v-model="contactPhone" type="tel" placeholder="+52 993 123 4567"
                    class="mt-1 block w-full rounded-xl border-gray-200 text-sm focus:border-red-400 focus:ring-red-300" />

                <!-- Honeypot: hidden field that should remain empty -->
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label>No llenar este campo</label>
                    <input v-model="honeypot" type="text" tabindex="-1" autocomplete="off" />
                </div>
            </section>

            <!-- Payment method -->
            <section v-if="branch" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Cómo vas a pagar</p>
                <div class="mt-3 grid gap-2">
                    <button v-for="m in branch.payment_methods" :key="m" type="button" @click="paymentMethod = m"
                        :class="['rounded-xl px-4 py-3 text-left text-sm font-semibold transition ring-1',
                            paymentMethod === m ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50']">
                        {{ paymentLabel(m) }}
                    </button>
                </div>
            </section>

            <!-- Cart note -->
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Notas para la sucursal (opcional)</label>
                <textarea v-model="cartNote" rows="2" maxlength="500" placeholder="Ej: tocar timbre, casa color verde..."
                    class="mt-2 block w-full rounded-xl border-gray-200 text-sm focus:border-red-400 focus:ring-red-300"></textarea>
            </section>

            <!-- Summary -->
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Resumen</p>
                <div class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between text-gray-700">
                        <span>Subtotal ({{ cart.count.value }} productos)</span>
                        <span>${{ cart.subtotal.value.toFixed(2) }}</span>
                    </div>
                    <div v-if="deliveryType === 'delivery'" class="flex justify-between text-gray-700">
                        <span>Envío</span>
                        <span v-if="deliveryQuote" class="font-semibold">${{ deliveryFee.toFixed(2) }}</span>
                        <span v-else class="text-xs italic text-gray-400">Se calcula al confirmar</span>
                    </div>
                    <div class="mt-2 flex justify-between border-t border-gray-100 pt-2 text-base font-bold text-gray-900">
                        <span>Total</span>
                        <span v-if="deliveryType === 'pickup' || deliveryQuote">${{ total.toFixed(2) }}</span>
                        <span v-else>${{ cart.subtotal.value.toFixed(2) }} <span class="text-xs font-normal text-gray-400">+ envío</span></span>
                    </div>
                </div>
                <p v-if="belowMin" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                    ⚠️ Pedido mínimo ${{ branch.min_order_amount.toFixed(2) }}. Agrega más productos.
                </p>
            </section>
        </main>

        <!-- Sticky footer -->
        <div class="fixed bottom-0 left-0 right-0 border-t border-gray-100 bg-white p-4 shadow-lg">
            <div v-if="submitError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 ring-1 ring-red-200">
                {{ submitError }}
            </div>
            <div class="mx-auto max-w-lg">
                <button @click="submitOrder" :disabled="!canSubmit"
                    class="flex w-full items-center justify-between gap-3 rounded-2xl bg-red-600 px-5 py-4 text-sm font-bold text-white shadow-lg transition hover:bg-red-700 disabled:opacity-50">
                    <span class="flex items-center gap-2">
                        <svg v-if="submitting || deliveryLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                        {{ deliveryLoading ? 'Verificando cobertura...' : submitting ? 'Procesando pedido...' : 'Confirmar pedido' }}
                    </span>
                    <span v-if="deliveryType === 'pickup' || deliveryQuote" class="rounded-full bg-white/20 px-3 py-1">${{ total.toFixed(2) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
