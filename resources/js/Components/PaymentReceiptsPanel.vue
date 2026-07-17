<script setup>
/**
 * Panel de comprobantes de un pago por transferencia (venta o cobro global
 * de fiado). Está pensado para montarse dentro de un <Modal> existente del
 * proyecto (Components/Modal.vue) — este componente sólo aporta el
 * contenido (header + AttachmentsPicker), no el overlay/backdrop.
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
import AttachmentsPicker from '@/Components/AttachmentsPicker.vue';

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

const routeName = (action) => `${props.routePrefix}.${segment.value}.receipts.${action}`;
const previewUrl = (r) => route(routeName('preview'), [props.tenantSlug, props.parentId, r.id]);
const downloadUrl = (r) => route(routeName('download'), [props.tenantSlug, props.parentId, r.id]);

const uploading = ref(false);
const uploadError = ref('');

const onFilesSelected = (files) => {
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

const onRemoveExisting = (r) => {
    router.delete(route(routeName('destroy'), [props.tenantSlug, props.parentId, r.id]), {
        preserveScroll: true,
        onSuccess: () => emit('changed'),
    });
};
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

        <div class="px-6 py-4">
            <AttachmentsPicker
                mode="immediate"
                :attachments="receipts"
                :max-count="MAX"
                :preview-url="previewUrl"
                :download-url="downloadUrl"
                :can-add="canManage"
                :can-delete="canDelete"
                :uploading="uploading"
                :upload-error="uploadError"
                empty-state-text="Sin comprobantes adjuntos."
                @files-selected="onFilesSelected"
                @remove-existing="onRemoveExisting" />
        </div>
    </div>
</template>
