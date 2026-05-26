<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const page = usePage();
// Negocio y sucursal vienen de los props compartidos de Inertia (HandleInertiaRequests).
const businessName = computed(() => page.props.auth?.tenant?.name || 'Negocio');
const branchName = computed(() => page.props.auth?.branch?.name || '');
// Ancho de papel térmico configurado por sucursal (58mm / 80mm); 80mm por defecto.
const paperWidth = computed(() => page.props.auth?.branch?.ticket_width || '80mm');
const previewWidth = computed(() => (paperWidth.value === '58mm' ? '260px' : '340px'));

const text = ref('');
const now = ref('');

const stamp = () =>
    new Date().toLocaleString('es-MX', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true,
    });

// Refresca la fecha/hora cada vez que se abre el modal.
watch(
    () => props.open,
    (v) => {
        if (v) {
            now.value = stamp();
        }
    },
    { immediate: true }
);

const hasText = computed(() => text.value.trim().length > 0);

const print = () => {
    now.value = stamp();
    window.print();
};
const clear = () => {
    text.value = '';
};
const close = () => emit('close');
</script>

<template>
    <Teleport to="body">
        <template v-if="open">
            <!-- Overlay en pantalla (se oculta al imprimir) -->
            <div class="fixed inset-0 z-[200] flex items-end justify-center bg-black/50 p-0 backdrop-blur-sm print:hidden sm:items-center sm:p-4"
                @click.self="close">
                <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">Nota rápida</h2>
                        <button type="button" @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <div class="grid flex-1 gap-5 overflow-y-auto p-5 md:grid-cols-2">
                        <!-- Editor -->
                        <div class="flex flex-col">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Texto</label>
                            <textarea v-model="text" rows="12" placeholder="Escribe o pega aquí el texto de la nota…"
                                class="min-h-[220px] flex-1 w-full resize-none rounded-xl border-gray-300 font-mono text-sm focus:border-red-500 focus:ring-red-500"></textarea>
                        </div>

                        <!-- Vista previa 80 mm -->
                        <div>
                            <p class="mb-1 text-sm font-medium text-gray-700">Vista previa ({{ paperWidth }})</p>
                            <div class="mx-auto rounded-lg border border-dashed border-gray-300 bg-white p-4" :style="{ width: previewWidth, maxWidth: '100%' }">
                                <div class="text-center">
                                    <p class="text-sm font-bold">{{ businessName }}</p>
                                    <p v-if="branchName" class="text-xs text-gray-500">{{ branchName }}</p>
                                    <p class="text-xs text-gray-400">{{ now }}</p>
                                </div>
                                <div class="my-2 border-t border-dashed border-gray-300" />
                                <p class="whitespace-pre-wrap break-words font-mono text-xs text-gray-800">{{ text || 'El texto de la nota aparecerá aquí.' }}</p>
                            </div>
                        </div>
                    </div>

                    <footer class="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="clear" :disabled="!hasText"
                            class="mr-auto rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 disabled:opacity-50">Limpiar</button>
                        <button type="button" @click="close"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100">Cerrar</button>
                        <button type="button" @click="print" :disabled="!hasText"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">Imprimir</button>
                    </footer>
                </div>
            </div>

            <!-- Contenido solo-impresión (hijo directo de body vía Teleport).
                 El ancho sigue el papel configurado (58/80 mm) y el padding en mm
                 mantiene el texto fuera de la zona no imprimible de la térmica. -->
            <div class="hidden print:block" :style="{
                width: paperWidth,
                boxSizing: 'border-box',
                padding: '4mm 5mm',
                fontFamily: 'monospace',
                fontSize: '12px',
                lineHeight: '1.45',
            }">
                <div style="text-align: center;">
                    <div style="font-size: 14px; font-weight: bold; overflow-wrap: break-word; word-break: break-word;">{{ businessName }}</div>
                    <div v-if="branchName" style="font-size: 11px; overflow-wrap: break-word; word-break: break-word;">{{ branchName }}</div>
                    <div style="font-size: 10px; color: #666;">{{ now }}</div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="white-space: pre-wrap; overflow-wrap: break-word; word-break: break-word;">{{ text }}</div>
                <div style="margin-top: 12px;" />
            </div>
        </template>
    </Teleport>
</template>
