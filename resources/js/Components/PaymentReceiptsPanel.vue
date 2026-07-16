<script setup>
/**
 * Panel de comprobantes de un pago por transferencia (venta o cobro global
 * de fiado). Está pensado para montarse dentro de un <Modal> existente del
 * proyecto (Components/Modal.vue) — este componente sólo aporta el
 * contenido (header + lista + uploader), no el overlay/backdrop.
 *
 * Consume los endpoints de Tasks 5/6 (`*.pagos.receipts.*` /
 * `*.cobros.receipts.*`) tal cual quedaron: responden redirect-back con
 * flash de éxito / errores de validación (`back()->with('success', ...)`,
 * `back()->withErrors(...)`) — cero cambios a esos controladores. Por eso
 * usamos Inertia `router.post`/`router.delete` (NO axios): los errores de
 * validación (422: método no es transferencia, límite de 3, tipo/tamaño de
 * archivo) SÍ llegan al callback `onError` porque ese flujo es el estándar
 * de Inertia (el redirect se sigue y la página anfitriona se re-renderiza
 * con `errors` compartido).
 *
 * `canManage` se recibe tal cual desde el padre (normalmente `true`): la
 * autorización real vive en el backend (rol, turno abierto del cajero,
 * dueño del pago, flag de sucursal). Si el backend rechaza con 403/404
 * (p.ej. un cajero fuera de su turno), la respuesta no tiene el header
 * `X-Inertia` (es una página de error normal de Laravel), así que Inertia
 * no la puede mapear a `onError` — se ve su modal de error por defecto.
 * Mismo comportamiento que ya tiene el resto de la app para este tipo de
 * rechazo (p.ej. `removeExistingAttachment` en GastoFormModal.vue tampoco
 * maneja el 403 de forma especial). Aceptado a propósito para no tocar los
 * controladores de T5/T6.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const MAX = 3;

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    parentType: {
        type: String,
        required: true,
        validator: (v) => ['payment', 'customer-payment'].includes(v),
    },
    parentId: { type: Number, required: true },
    canManage: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    routePrefix: { type: String, default: 'sucursal' },
});

const emit = defineEmits(['changed', 'close']);

const segment = computed(() => (props.parentType === 'payment' ? 'pagos' : 'cobros'));

// El grupo de rutas `caja` no expone destroy para comprobantes de cobro
// global (T6: el cajero adjunta/descarga los suyos pero no los elimina).
const canDelete = computed(() => props.canManage && !(props.routePrefix === 'caja' && props.parentType === 'customer-payment'));
const canAdd = computed(() => props.canManage && props.receipts.length < MAX);

const routeName = (action) => `${props.routePrefix}.${segment.value}.receipts.${action}`;
const downloadUrl = (r) => route(routeName('download'), [props.tenantSlug, props.parentId, r.id]);

const fileInput = ref(null);
const uploading = ref(false);
const uploadError = ref('');

const onFilesSelected = (e) => {
    const slots = Math.max(MAX - props.receipts.length, 0);
    const files = Array.from(e.target.files ?? []).slice(0, slots);
    if (fileInput.value) {
        fileInput.value.value = '';
    }
    if (!files.length) {
        return;
    }

    uploadError.value = '';
    uploading.value = true;
    router.post(route(routeName('store'), [props.tenantSlug, props.parentId]), { receipts: files }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => { uploadError.value = errors.receipts || 'No se pudo subir el comprobante.'; },
        onSuccess: () => emit('changed'),
        onFinish: () => { uploading.value = false; },
    });
};

const deletingId = ref(null);
const destroy = (r) => {
    if (deletingId.value) {
        return;
    }
    if (!window.confirm(`¿Eliminar "${r.original_name}"?`)) {
        return;
    }

    deletingId.value = r.id;
    router.delete(route(routeName('destroy'), [props.tenantSlug, props.parentId, r.id]), {
        preserveScroll: true,
        onSuccess: () => emit('changed'),
        onFinish: () => { deletingId.value = null; },
    });
};

const fmtSize = (b) => {
    if (b == null) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
    return `${(b / (1024 * 1024)).toFixed(1)} MB`;
};
const isImage = (r) => !!r?.mime_type?.startsWith('image/');
const isPdf = (r) => r?.mime_type === 'application/pdf';
</script>

<template>
    <div>
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-bold text-gray-900">Comprobantes</h3>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-500">{{ receipts.length }}/{{ MAX }}</span>
            </div>
            <button type="button" @click="emit('close')" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="max-h-[60vh] overflow-y-auto px-6 py-4 space-y-2">
            <div v-if="!receipts.length" class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-xs text-gray-400">
                Sin comprobantes adjuntos.
            </div>

            <div v-for="r in receipts" :key="r.id" class="flex items-center gap-3 rounded-lg bg-gray-50 px-3.5 py-2.5 ring-1 ring-gray-100">
                <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                    isPdf(r) ? 'bg-red-100 text-red-600' : isImage(r) ? 'bg-blue-100 text-blue-600' : 'bg-gray-200 text-gray-500']">
                    <svg v-if="isPdf(r)" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <svg v-else class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3 4.5h18v15H3v-15Z" /></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-gray-800">{{ r.original_name }}</p>
                    <p class="text-xs text-gray-400">{{ fmtSize(r.size_bytes) }}</p>
                </div>
                <a :href="downloadUrl(r)" target="_blank" rel="noopener"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-white hover:text-red-600" title="Descargar">
                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                </a>
                <button v-if="canDelete" type="button" @click="destroy(r)" :disabled="deletingId === r.id"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-white hover:text-red-600 disabled:opacity-50" title="Eliminar">
                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                </button>
            </div>
        </div>

        <div v-if="canAdd" class="border-t border-gray-100 px-6 py-4">
            <label :class="['flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 px-4 py-3 text-sm font-semibold text-gray-500 transition hover:border-red-300 hover:bg-red-50/40 hover:text-red-600',
                uploading ? 'pointer-events-none opacity-60' : '']">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 5v14a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 19V8.25a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                <span v-if="uploading">Subiendo…</span>
                <span v-else>Agregar comprobante</span>
                <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="hidden" :disabled="uploading" @change="onFilesSelected" />
            </label>
            <p v-if="uploadError" class="mt-2 text-xs font-semibold text-red-600">{{ uploadError }}</p>
        </div>
        <div v-else-if="canManage" class="border-t border-gray-100 px-6 py-3 text-center text-xs text-gray-400">
            Máximo {{ MAX }} comprobantes por pago.
        </div>
    </div>
</template>
