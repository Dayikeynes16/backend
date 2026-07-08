<script setup>
import { computed, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ExpenseDraftCardBody from './ExpenseDraftCardBody.vue';
import ProviderDraftCardBody from './ProviderDraftCardBody.vue';
import PurchaseDraftCardBody from './PurchaseDraftCardBody.vue';
import PayablePaymentDraftCardBody from './PayablePaymentDraftCardBody.vue';
import CustomerPaymentDraftCardBody from './CustomerPaymentDraftCardBody.vue';
import ProviderAccountPaymentDraftCardBody from './ProviderAccountPaymentDraftCardBody.vue';
import CashWithdrawalDraftCardBody from './CashWithdrawalDraftCardBody.vue';
import PriceChangeDraftCardBody from './PriceChangeDraftCardBody.vue';
import ExpenseCategoryDraftCardBody from './ExpenseCategoryDraftCardBody.vue';
import ExpenseCategoryEditDraftCardBody from './ExpenseCategoryEditDraftCardBody.vue';

// Tarjeta de un borrador preparado por el asistente. La IA sólo lo preparó; la
// confirmación es una acción explícita del usuario que dispara una 2ª petición
// HTTP (nunca la ejecuta el modelo). Anti-doble-clic vía `processing`.
const props = defineProps({
    data: { type: Object, required: true },
    routes: { type: Object, required: true },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const draftType = computed(() => props.data.draft_type);
const preview = props.data.preview || {};
const options = props.data.options || {};

// El form usa las claves del backend; el payload de confirmación es {...form}.
const form = reactive(buildForm());

function buildForm() {
    if (draftType.value === 'provider') {
        return {
            name: preview.name ?? '',
            type: preview.type ?? null,
            phone: preview.phone ?? '',
            email: preview.email ?? '',
            rfc: preview.rfc ?? '',
            address: preview.address ?? '',
            notes: preview.notes ?? '',
        };
    }

    if (draftType.value === 'purchase') {
        const f = {
            provider_id: preview.provider_id ?? null,
            branch_id: preview.branch_id ?? null,
            purchased_at: preview.purchased_at ?? '',
            invoice_number: preview.invoice_number ?? '',
            notes: preview.notes ?? '',
            items: (preview.items || []).map((l) => ({
                concept: l.concept ?? '',
                quantity: l.quantity ?? 1,
                unit: l.unit ?? 'kg',
                unit_price: l.unit_price ?? 0,
                purchase_product_id: l.purchase_product_id ?? null,
            })),
        };
        if (!f.branch_id && (options.branches || []).length === 1) {
            f.branch_id = options.branches[0].id;
        }
        return f;
    }

    if (draftType.value === 'payable_payment') {
        return {
            purchase_id: preview.purchase_id ?? null,
            amount: preview.amount ?? null,
            payment_method: preview.payment_method ?? null,
            reference: preview.reference ?? '',
            notes: preview.notes ?? '',
            paid_at: preview.paid_at ?? '',
        };
    }

    if (draftType.value === 'customer_global_payment') {
        return {
            customer_id: preview.customer_id ?? null,
            amount_received: preview.amount_received ?? null,
            method: preview.method ?? null,
            notes: preview.notes ?? '',
        };
    }

    if (draftType.value === 'provider_account_payment') {
        return {
            provider_id: preview.provider_id ?? null,
            amount: preview.amount ?? null,
            payment_method: preview.payment_method ?? null,
            reference: preview.reference ?? '',
            notes: preview.notes ?? '',
            paid_at: preview.paid_at ?? '',
        };
    }

    if (draftType.value === 'cash_withdrawal') {
        return {
            amount: preview.amount ?? null,
            reason: preview.reason ?? '',
        };
    }

    if (draftType.value === 'price_change') {
        return {
            product_id: preview.product_id ?? null,
            new_price: preview.new_price ?? null,
        };
    }

    if (draftType.value === 'expense_category') {
        return {
            tipo: preview.tipo ?? 'categoria',
            nombre: preview.nombre ?? '',
            descripcion: preview.descripcion ?? '',
            existing_category_id: preview.existing_category_id ?? null,
        };
    }

    if (draftType.value === 'expense_category_edit') {
        return {
            target_type: preview.target_type ?? 'categoria',
            target_id: preview.target_id ?? null,
            current_name: preview.current_name ?? '',
            current_status: preview.current_status ?? 'active',
            name: preview.name ?? '',
            description: preview.description ?? '',
            status: preview.status ?? 'active',
        };
    }

    // expense
    const f = {
        concept: preview.concepto ?? '',
        amount: preview.monto ?? null,
        expense_subcategory_id: preview.expense_subcategory_id ?? null,
        expense_date: preview.fecha ?? '',
        payment_method: preview.metodo_pago ?? null,
        description: preview.descripcion ?? '',
        branch_id: preview.branch_id ?? null,
    };
    // Si sólo hay una sucursal disponible (admin-sucursal), queda fijada.
    if (!f.branch_id && (options.branches || []).length === 1) {
        f.branch_id = options.branches[0].id;
    }
    return f;
}

const status = ref(props.data.status === 'consumed' ? 'confirmed' : 'ready');
const processing = ref(false);
const errorMsg = ref(null);
const fieldErrors = ref({});
const resultId = ref(props.data.result_id ?? null);
// Mensaje real del backend al confirmar (p.ej. montos aplicados y cambio);
// si no viene, se usa el texto genérico del tipo.
const confirmedMessage = ref(null);

const meta = computed(() => ({
    provider: { title: 'Borrador de proveedor', done: 'Proveedor creado correctamente.', confirm: 'Crear proveedor' },
    purchase: { title: 'Borrador de compra', done: 'Compra registrada correctamente.', confirm: 'Registrar compra' },
    payable_payment: { title: 'Borrador de abono', done: 'Abono registrado correctamente.', confirm: 'Registrar abono' },
    customer_global_payment: { title: 'Borrador de cobro a cliente', done: 'Cobro registrado correctamente.', confirm: 'Registrar cobro' },
    provider_account_payment: { title: 'Borrador de pago a proveedor', done: 'Pago registrado correctamente.', confirm: 'Registrar pago' },
    cash_withdrawal: { title: 'Borrador de retiro de caja', done: 'Retiro registrado correctamente.', confirm: 'Registrar retiro' },
    price_change: { title: 'Borrador de cambio de precio', done: 'Precio actualizado correctamente.', confirm: 'Aplicar precio' },
    expense_category: { title: 'Borrador de categoría', done: 'Categoría creada correctamente.', confirm: 'Crear categoría' },
    expense_category_edit: { title: 'Editar categoría', done: 'Cambios guardados correctamente.', confirm: 'Guardar cambios' },
    expense: { title: 'Borrador de gasto', done: 'Gasto registrado correctamente.', confirm: 'Confirmar gasto' },
})[draftType.value] || { title: 'Borrador', done: 'Registrado correctamente.', confirm: 'Confirmar' });

const bodyComponent = computed(() => ({
    provider: ProviderDraftCardBody,
    purchase: PurchaseDraftCardBody,
    payable_payment: PayablePaymentDraftCardBody,
    customer_global_payment: CustomerPaymentDraftCardBody,
    provider_account_payment: ProviderAccountPaymentDraftCardBody,
    cash_withdrawal: CashWithdrawalDraftCardBody,
    price_change: PriceChangeDraftCardBody,
    expense_category: ExpenseCategoryDraftCardBody,
    expense_category_edit: ExpenseCategoryEditDraftCardBody,
})[draftType.value] || ExpenseDraftCardBody);

const canConfirm = computed(() => {
    if (draftType.value === 'provider') {
        return !!form.name && !!form.type;
    }
    if (draftType.value === 'purchase') {
        return (
            !!form.provider_id &&
            !!form.branch_id &&
            (form.items || []).length > 0 &&
            form.items.every((l) => !!l.concept && Number(l.quantity) > 0)
        );
    }
    if (draftType.value === 'payable_payment') {
        return !!form.purchase_id && Number(form.amount) > 0 && !!form.payment_method;
    }
    if (draftType.value === 'customer_global_payment') {
        return !!form.customer_id && Number(form.amount_received) > 0 && !!form.method;
    }
    if (draftType.value === 'provider_account_payment') {
        return !!form.provider_id && Number(form.amount) > 0 && !!form.payment_method;
    }
    if (draftType.value === 'cash_withdrawal') {
        return Number(form.amount) > 0 && !!form.reason?.trim();
    }
    if (draftType.value === 'price_change') {
        return !!form.product_id && Number(form.new_price) > 0;
    }
    if (draftType.value === 'expense_category') {
        return !!form.nombre && !!form.tipo && (form.tipo === 'categoria' || !!form.existing_category_id);
    }
    if (draftType.value === 'expense_category_edit') {
        return !!form.target_id && !!form.name && !!form.status;
    }
    return (
        !!form.concept &&
        Number(form.amount) > 0 &&
        !!form.expense_subcategory_id &&
        !!form.expense_date &&
        !!form.branch_id
    );
});

const confirmRouteAvailable = computed(() => !!props.routes.draftConfirm && !!props.routes.draftCancel);

// ── Modo resumen-primero: si el borrador viene completo, se muestra un
// resumen limpio (como una confirmación de caja) con "Confirmar" grande y
// "Corregir" para abrir el formulario. Con campos faltantes, directo a editar.
const money = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const methodLabel = (v) => (options.payment_methods || []).find((m) => m.value === v)?.label || v || '—';

const summaryRows = computed(() => {
    const t = draftType.value;
    if (t === 'customer_global_payment') {
        const c = (options.customers || []).find((x) => x.id === form.customer_id);
        const d = preview.distribution;
        const rows = [
            ['Cliente', c?.name || '—'],
            ['Monto recibido', money(form.amount_received)],
            ['Método', methodLabel(form.method)],
        ];
        if (d && form.customer_id === preview.customer_id) {
            rows.push(['Se aplica a', `${d.sales.length} venta(s) · ${money(d.amount_to_apply)}`]);
            if (d.change_given > 0) rows.push(['Cambio', money(d.change_given)]);
        }
        return rows;
    }
    if (t === 'provider_account_payment') {
        const p = (options.providers || []).find((x) => x.id === form.provider_id);
        return [
            ['Proveedor', p?.name || '—'],
            ['Monto', money(form.amount)],
            ['Método', methodLabel(form.payment_method)],
        ];
    }
    if (t === 'payable_payment') {
        const p = (options.purchases || []).find((x) => x.id === form.purchase_id);
        return [
            ['Compra', p ? `${p.folio}` : '—'],
            ['Monto', money(form.amount)],
            ['Método', methodLabel(form.payment_method)],
        ];
    }
    if (t === 'cash_withdrawal') {
        return [['Monto a retirar', money(form.amount)], ['Motivo', form.reason || '—']];
    }
    if (t === 'price_change') {
        const p = (options.products || []).find((x) => x.id === form.product_id);
        return [
            ['Producto', p?.name || '—'],
            ['Precio actual', p ? money(p.current_price) : '—'],
            ['Nuevo precio', money(form.new_price)],
        ];
    }
    if (t === 'expense') {
        return [['Concepto', form.concept || '—'], ['Monto', money(form.amount)], ['Fecha', form.expense_date || '—']];
    }
    if (t === 'purchase') {
        const total = (form.items || []).reduce((sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_price || 0), 0);
        return [['Conceptos', String((form.items || []).length)], ['Total', money(total)]];
    }
    return null; // proveedores/categorías: siempre formulario
});

const editing = ref(!summaryRows.value || (props.data.missing_fields || []).length > 0);

async function confirm() {
    if (processing.value || status.value !== 'ready' || !canConfirm.value) return;
    processing.value = true;
    errorMsg.value = null;
    fieldErrors.value = {};
    try {
        const url = route(props.routes.draftConfirm, { tenant: slug.value, draft: props.data.draft_id });
        const { data } = await axios.post(url, { ...form });
        status.value = 'confirmed';
        resultId.value = data.result_id ?? null;
        confirmedMessage.value = data.message ?? null;
    } catch (err) {
        const s = err.response?.status;
        if (s === 422) {
            fieldErrors.value = err.response.data.errors || {};
            errorMsg.value = 'Revisa los campos marcados.';
        } else if (s === 409) {
            errorMsg.value = err.response.data.message;
            status.value = 'unavailable';
        } else {
            errorMsg.value = err.response?.data?.message || 'No pude confirmar el borrador.';
        }
    } finally {
        processing.value = false;
    }
}

async function cancelDraft() {
    if (processing.value || status.value !== 'ready') return;
    processing.value = true;
    errorMsg.value = null;
    try {
        const url = route(props.routes.draftCancel, { tenant: slug.value, draft: props.data.draft_id });
        await axios.post(url);
        status.value = 'cancelled';
    } catch (err) {
        errorMsg.value = err.response?.data?.message || 'No pude cancelar el borrador.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border border-orange-200/70 bg-white p-4 text-sm">
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2 font-semibold text-orange-900">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
                </svg>
                {{ meta.title }}
            </div>
            <span
                v-if="status !== 'ready'"
                :class="[
                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                    status === 'confirmed' ? 'bg-emerald-100 text-emerald-800'
                        : status === 'cancelled' ? 'bg-gray-200 text-gray-600'
                        : 'bg-red-100 text-red-800',
                ]"
            >
                {{ status === 'confirmed' ? 'Confirmado' : status === 'cancelled' ? 'Cancelado' : 'No disponible' }}
            </span>
        </div>

        <!-- Confirmado -->
        <div v-if="status === 'confirmed'" class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M20.7 6.3a1 1 0 0 1 0 1.4l-10 10a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4l3.3 3.29 9.3-9.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
            {{ confirmedMessage || meta.done }}
        </div>

        <!-- Cancelado / no disponible -->
        <div v-else-if="status === 'cancelled' || status === 'unavailable'" class="rounded-lg bg-gray-50 px-3 py-2 text-gray-600">
            {{ status === 'cancelled' ? 'Descartaste este borrador.' : (errorMsg || 'Este borrador ya no está disponible.') }}
        </div>

        <!-- Editable + confirmación -->
        <template v-else>
            <div v-if="!editing && summaryRows" class="divide-y divide-gray-100 rounded-xl bg-gray-50/70 px-3.5">
                <div v-for="[label, value] in summaryRows" :key="label" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                    <span class="shrink-0 text-gray-500">{{ label }}</span>
                    <span class="min-w-0 truncate text-right font-semibold text-gray-900">{{ value }}</span>
                </div>
            </div>
            <component v-else :is="bodyComponent" :form="form" :options="options" :errors="fieldErrors" :disabled="processing" :preview="preview" />

            <!-- Posibles duplicados (proveedor) -->
            <div v-if="data.duplicates && data.duplicates.length" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <p class="mb-1 font-semibold">Posibles proveedores existentes:</p>
                <ul class="space-y-0.5">
                    <li v-for="d in data.duplicates" :key="d.id">
                        • {{ d.name }}<span v-if="d.phone"> · {{ d.phone }}</span><span v-if="d.rfc"> · {{ d.rfc }}</span>
                    </li>
                </ul>
                <p class="mt-1 italic">Revisa si ya existe antes de crear uno nuevo.</p>
            </div>

            <div v-if="data.missing_fields && data.missing_fields.length" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Completa antes de confirmar: {{ data.missing_fields.join(', ') }}.
            </div>
            <ul v-if="data.warnings && data.warnings.length" class="mt-2 space-y-1">
                <li v-for="(w, i) in data.warnings" :key="i" class="text-xs text-amber-700">⚠ {{ w }}</li>
            </ul>
            <div v-if="data.attachments && data.attachments.length" class="mt-2 text-xs text-gray-500">
                📎 {{ data.attachments.length }} archivo(s) adjunto(s).
            </div>

            <div v-if="errorMsg" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{{ errorMsg }}</div>

            <div v-if="!confirmRouteAvailable" class="mt-3 text-xs italic text-gray-500">
                La confirmación no está disponible en este contexto.
            </div>
            <div v-else class="mt-4 space-y-1">
                <button
                    type="button"
                    :disabled="processing || !canConfirm"
                    @click="confirm"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-3 text-sm font-bold uppercase tracking-wide text-white shadow-sm transition-all duration-150 hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:saturate-50"
                >
                    <svg v-if="processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.3" /><path d="M22 12a10 10 0 0 1-10 10" />
                    </svg>
                    {{ processing ? 'Guardando…' : meta.confirm }}
                </button>
                <div class="flex items-center justify-center gap-8 pt-1.5">
                    <button
                        v-if="summaryRows"
                        type="button"
                        :disabled="processing"
                        @click="editing = !editing"
                        class="text-sm font-semibold uppercase tracking-wide text-orange-700 transition-colors duration-150 hover:text-orange-900 disabled:opacity-50"
                    >
                        {{ editing ? 'Ver resumen' : 'Corregir' }}
                    </button>
                    <button
                        type="button"
                        :disabled="processing"
                        @click="cancelDraft"
                        class="text-sm uppercase tracking-wide text-gray-400 transition-colors duration-150 hover:text-gray-600 disabled:opacity-50"
                    >
                        Cancelar
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
