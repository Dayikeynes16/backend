<script setup>
import { useRoute, useRouter } from 'vue-router';
import { computed, onMounted, ref } from 'vue';

const route = useRoute();
const router = useRouter();
const saleId = computed(() => route.params.saleId);
const tenantSlug = computed(() => route.params.tenantSlug);
const branchId = computed(() => Number(route.params.branchId));

const orderData = ref(null);

onMounted(() => {
    const raw = sessionStorage.getItem(`order_${saleId.value}`);
    if (raw) {
        try {
            orderData.value = JSON.parse(raw);
        } catch {}
    }
});

const openWhatsapp = () => {
    if (orderData.value?.whatsapp_url) {
        window.location.href = orderData.value.whatsapp_url;
    }
};

const goBackToMenu = () => {
    router.push({ name: 'menu', params: { tenantSlug: tenantSlug.value, branchId: branchId.value } });
};
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-green-50 via-white to-emerald-50">
        <div class="mx-auto max-w-lg px-5 py-10">
            <div class="text-center">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-100 shadow-sm">
                    <svg class="h-10 w-10 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </div>
                <h1 class="mt-5 text-3xl font-bold text-gray-900">¡Pedido recibido!</h1>
                <p class="mt-2 text-sm text-gray-600">Tu pedido <span class="font-bold text-gray-900">{{ orderData?.folio || '#' + saleId }}</span> se registró correctamente.</p>
                <p class="mt-1 text-sm text-gray-600">Total: <span class="font-bold text-gray-900">${{ (orderData?.total || 0).toFixed(2) }}</span></p>
            </div>

            <!-- WhatsApp auto-open -->
            <div class="mt-8 rounded-3xl bg-white p-6 shadow-lg ring-1 ring-gray-100">
                <div class="text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-500 text-white">
                        <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" /></svg>
                    </div>
                    <h2 class="mt-4 text-lg font-bold text-gray-900">
                        <template v-if="!orderData?.whatsapp_url">Tu pedido quedó registrado</template>
                        <template v-else>¿Volviste? Abre WhatsApp de nuevo</template>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <template v-if="!orderData?.whatsapp_url">La sucursal se pondrá en contacto.</template>
                        <template v-else>Si cerraste WhatsApp sin enviar el mensaje, toca el botón para abrirlo otra vez con tu pedido pre-cargado.</template>
                    </p>
                </div>

                <div v-if="orderData?.whatsapp_url" class="mt-5">
                    <button @click="openWhatsapp"
                        class="flex w-full items-center justify-center gap-3 rounded-2xl bg-green-500 py-4 text-sm font-bold text-white shadow-lg transition hover:bg-green-600 active:scale-[0.98]">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" /></svg>
                        Abrir WhatsApp
                    </button>
                </div>
            </div>

            <div class="mt-8 text-center">
                <button @click="goBackToMenu" class="text-sm font-semibold text-gray-500 underline hover:text-gray-700">Volver al menú</button>
            </div>
        </div>
    </div>
</template>
