<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed } from 'vue';
import { useCart } from '../composables/useCart.js';

const route = useRoute();
const router = useRouter();
const tenantSlug = computed(() => route.params.tenantSlug);
const branchId = computed(() => Number(route.params.branchId));

const cart = useCart(branchId.value);

const unitLabel = (type) => ({ kg: 'kg', piece: 'pz', cut: 'pz' }[type] || '');

const updateQty = (item, delta) => {
    const step = item.unit_type === 'kg' ? 0.25 : 1;
    const min = step;
    const next = Number((Number(item.quantity) + delta * step).toFixed(3));
    if (next < min) {
        if (confirm('¿Quitar este producto del carrito?')) cart.removeItem(item.line_id);
        return;
    }
    cart.updateItem(item.line_id, { quantity: next });
};

const goToMenu = () => router.push({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
const goToCheckout = () => router.push({ name: 'checkout', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="sticky top-0 z-20 border-b border-gray-100 bg-white px-4 py-3.5 shadow-sm">
            <div class="flex items-center gap-3">
                <button @click="goToMenu" class="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                </button>
                <h1 class="text-lg font-bold text-gray-900">Tu carrito</h1>
            </div>
        </header>

        <main class="mx-auto max-w-lg px-4 py-5">
            <div v-if="cart.count.value === 0" class="rounded-3xl border-2 border-dashed border-gray-200 px-6 py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                <p class="mt-3 text-sm text-gray-400">Tu carrito está vacío.</p>
                <button @click="goToMenu" class="mt-5 rounded-full bg-red-600 px-5 py-2 text-sm font-semibold text-white">Ver menú</button>
            </div>

            <div v-else class="space-y-3">
                <div v-for="item in cart.state.items" :key="item.line_id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold text-gray-900">{{ item.product_name }}</p>
                            <p class="mt-0.5 text-xs text-gray-400">${{ Number(item.unit_price).toFixed(2) }} / {{ unitLabel(item.unit_type) }}</p>
                            <p v-if="item.notes" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-xs italic text-amber-800 ring-1 ring-amber-100">💬 {{ item.notes }}</p>
                        </div>
                        <button @click="cart.removeItem(item.line_id)" class="rounded-full p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-500">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <button @click="updateQty(item, -1)" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                            </button>
                            <span class="min-w-[3rem] text-center text-sm font-semibold text-gray-900">{{ Number(item.quantity) }} {{ unitLabel(item.unit_type) }}</span>
                            <button @click="updateQty(item, 1)" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            </button>
                        </div>
                        <p class="text-base font-bold text-gray-900">${{ (Number(item.quantity) * Number(item.unit_price)).toFixed(2) }}</p>
                    </div>
                </div>

                <!-- Total summary -->
                <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100">
                    <div class="flex justify-between text-base font-bold text-gray-900">
                        <span>Subtotal</span>
                        <span>${{ cart.subtotal.value.toFixed(2) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Costo de envío se calcula en el siguiente paso.</p>
                </div>

                <button @click="goToCheckout"
                    class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 py-4 text-sm font-bold text-white shadow-lg transition hover:bg-red-700">
                    Continuar al pago
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </button>
            </div>
        </main>
    </div>
</template>
