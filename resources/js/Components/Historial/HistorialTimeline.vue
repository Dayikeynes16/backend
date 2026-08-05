<script setup>
import { computed } from 'vue';

const props = defineProps({
    history: { type: Array, default: () => [] },
});

const EVENT_LABEL = {
    created: 'Creó',
    updated: 'Editó',
    cancelled: 'Canceló',
    payment_added: 'Registró pago',
    payment_cancelled: 'Canceló pago',
    merged: 'Fusionó',
};
const EVENT_ICON = {
    created: '🟢', updated: '✏️', cancelled: '🔴', payment_added: '💵', payment_cancelled: '↩️', merged: '🔀',
};
const FIELD_LABEL = {
    provider: 'Proveedor', invoice_number: 'Factura', purchased_at: 'Fecha', total: 'Total', notes: 'Notas',
    concept: 'Concepto', amount: 'Monto', subcategory: 'Subcategoría', payment_method: 'Método', expense_at: 'Fecha',
    description: 'Notas', branch: 'Sucursal',
    name: 'Nombre', unit: 'Unidad', category: 'Categoría', status: 'Estado',
};
const METHOD_LABEL = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtVal = (key, v) => {
    if (v === null || v === undefined || v === '') return '∅';
    if (key === 'total' || key === 'amount') return money(v);
    if (key === 'payment_method') return METHOD_LABEL[v] || v;
    return String(v);
};
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
const userName = (h) => h.user_name ?? h.user?.name ?? 'Usuario eliminado';

const entries = computed(() => props.history || []);

// Convierte changes en líneas legibles.
const lines = (h) => {
    const out = [];
    const c = h.changes || {};
    if (h.event === 'cancelled' && c.reason) out.push(`Motivo: ${c.reason}`);
    if (h.event === 'payment_added') out.push(`${METHOD_LABEL[c.method] || c.method} +${money(c.amount)}`);
    if (h.event === 'payment_cancelled') out.push(`${METHOD_LABEL[c.method] || c.method} −${money(c.amount)}${c.reason ? ` · ${c.reason}` : ''}`);
    if (h.event === 'merged') out.push(`Absorbió: ${(c.absorbed || []).join(', ')} · ${c.items_relinked ?? 0} líneas reapuntadas`);
    if (c.fields) {
        for (const [k, pair] of Object.entries(c.fields)) {
            out.push(`${FIELD_LABEL[k] || k}: ${fmtVal(k, pair[0])} → ${fmtVal(k, pair[1])}`);
        }
    }
    if (c.items) {
        (c.items.added || []).forEach(i => out.push(`+ línea "${i.concept}" ${i.quantity} ${i.unit} × ${money(i.unit_price)}`));
        (c.items.removed || []).forEach(i => out.push(`− línea "${i.concept}"`));
        (c.items.changed || []).forEach(i => out.push(`~ línea "${i.concept}" ${i.from.quantity}×${money(i.from.unit_price)} → ${i.to.quantity}×${money(i.to.unit_price)}`));
    }
    return out;
};
</script>

<template>
    <div>
        <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-700">Historial</h3>
        <p v-if="!entries.length" class="rounded-lg bg-gray-50 px-3 py-3 text-sm italic text-gray-500">
            Sin cambios registrados.
        </p>
        <ul v-else class="space-y-2">
            <li v-for="(h, idx) in entries" :key="idx" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <div class="flex items-center gap-2 text-gray-900">
                    <span>{{ EVENT_ICON[h.event] || '•' }}</span>
                    <span class="font-semibold">{{ EVENT_LABEL[h.event] || h.event }}</span>
                    <span class="text-gray-500">· {{ userName(h) }}</span>
                    <span class="ml-auto text-xs text-gray-400">{{ fmtDate(h.created_at) }}</span>
                </div>
                <ul v-if="lines(h).length" class="mt-1 space-y-0.5 pl-6 text-xs text-gray-600">
                    <li v-for="(l, i) in lines(h)" :key="i">{{ l }}</li>
                </ul>
            </li>
        </ul>
    </div>
</template>
