<script setup>
import SucursalLayout from '@/Layouts/SucursalLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import QrcodeVue from 'qrcode.vue';

const props = defineProps({
    tenant: Object,
    branch: Object,
    menu_url: String,
    menu_path: String,
});

const qrContainer = ref(null);
const copied = ref(false);
const qrSize = ref(320);

const online = computed(() => props.branch?.online_ordering_enabled);

const modesLabel = computed(() => {
    const modes = [];
    if (props.branch?.delivery_enabled) modes.push('Domicilio');
    if (props.branch?.pickup_enabled) modes.push('Recolección en sucursal');
    return modes.length ? modes.join(' + ') : 'Sin modalidades habilitadas';
});

const copyUrl = async () => {
    await navigator.clipboard.writeText(props.menu_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1800);
};

const downloadQr = () => {
    const canvas = qrContainer.value?.querySelector('canvas');
    if (!canvas) return;
    const url = canvas.toDataURL('image/png');
    const a = document.createElement('a');
    a.href = url;
    a.download = `menu-${props.tenant.slug}-sucursal-${props.branch.id}.png`;
    document.body.appendChild(a);
    a.click();
    a.remove();
};

const printPoster = () => {
    const canvas = qrContainer.value?.querySelector('canvas');
    const dataUrl = canvas ? canvas.toDataURL('image/png') : '';
    const win = window.open('', '_blank', 'width=900,height=1200');
    if (!win) return;
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>Menú online · ${props.branch.name}</title>
          <style>
            @page { size: letter; margin: 24px; }
            body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; text-align: center; padding: 40px; color: #111; }
            h1 { font-size: 42px; margin: 0 0 8px; letter-spacing: -1px; }
            .sub { font-size: 18px; color: #666; margin-bottom: 40px; }
            .qr { display: inline-block; padding: 24px; border: 2px solid #111; border-radius: 20px; background: #fff; }
            .qr img { display: block; width: 480px; height: 480px; image-rendering: pixelated; }
            .url { margin-top: 36px; font-size: 16px; color: #444; word-break: break-all; }
            .cta { margin-top: 24px; font-size: 24px; font-weight: 800; }
            .footer { margin-top: 40px; font-size: 14px; color: #888; }
          </style>
        </head>
        <body>
          <h1>${props.branch.name}</h1>
          <p class="sub">Escanea para pedir en línea</p>
          <div class="qr"><img src="${dataUrl}" /></div>
          <p class="cta">Menú · Pedidos · ${props.branch.pickup_enabled ? 'Recoger' : ''}${props.branch.pickup_enabled && props.branch.delivery_enabled ? ' o ' : ''}${props.branch.delivery_enabled ? 'Domicilio' : ''}</p>
          <p class="url">${props.menu_url}</p>
          <p class="footer">Abre la cámara de tu celular y apunta al código</p>
          <script>window.addEventListener('load', () => { setTimeout(() => window.print(), 300); });<\/script>
        </body>
        </html>
    `);
    win.document.close();
};

watch(qrSize, () => {});
</script>

<template>
    <Head title="Menú Online · QR" />
    <SucursalLayout>
        <template #header><h1 class="text-xl font-bold text-gray-900">Menú Online</h1></template>

        <div class="mx-auto max-w-4xl space-y-6 pb-12">
            <!-- Status banner -->
            <div v-if="!online" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <div class="text-sm text-amber-800">
                    <p class="font-bold">Pedidos en línea desactivados para esta sucursal</p>
                    <p class="mt-1 text-xs">El QR funcionará cuando el admin de empresa habilite "Pedidos en línea" para esta sucursal.</p>
                </div>
            </div>

            <div v-else class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-600 text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </span>
                <div>
                    <p class="text-sm font-bold text-emerald-900">Pedidos en línea activos</p>
                    <p class="mt-0.5 text-xs text-emerald-700">{{ modesLabel }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[auto,1fr]">
                <!-- QR Card -->
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div ref="qrContainer" class="rounded-xl border border-gray-100 bg-white p-4">
                        <QrcodeVue :value="menu_url" :size="qrSize" level="M" render-as="canvas" :margin="2" />
                    </div>
                    <div class="mt-4 flex items-center justify-center gap-3">
                        <label class="flex items-center gap-2 text-xs font-medium text-gray-500">
                            Tamaño
                            <input type="range" min="200" max="480" step="20" v-model.number="qrSize" class="w-28 accent-red-600" />
                            <span class="w-10 tabular-nums text-gray-900">{{ qrSize }}</span>
                        </label>
                    </div>
                </div>

                <!-- Actions + info -->
                <div class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-gray-500">Link del menú</h3>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="flex-1 overflow-x-auto rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-900 ring-1 ring-inset ring-gray-200">{{ menu_url }}</code>
                            <button @click="copyUrl" type="button" class="shrink-0 rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-gray-800">
                                {{ copied ? 'Copiado' : 'Copiar' }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Se abre en el navegador del cliente. No requiere instalar nada.</p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-gray-500">Compartir el QR</h3>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <button @click="downloadQr" type="button" class="flex items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 shadow-sm">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                Descargar PNG
                            </button>
                            <button @click="printPoster" type="button" class="flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-bold text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m11.318 11.318.229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0H18.75c1.243 0 2.25-1.007 2.25-2.25V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M18 10.5h.008v.008H18V10.5Z" /></svg>
                                Imprimir póster
                            </button>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5">
                        <h3 class="text-sm font-bold text-blue-900">💡 Sugerencias</h3>
                        <ul class="mt-2 space-y-1.5 text-xs text-blue-800">
                            <li>• Coloca el póster impreso en la entrada, mostrador y mesas.</li>
                            <li>• Comparte la imagen PNG por WhatsApp o redes sociales.</li>
                            <li>• Pega la URL en tu perfil de Google My Business para aparecer en búsquedas locales.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </SucursalLayout>
</template>
