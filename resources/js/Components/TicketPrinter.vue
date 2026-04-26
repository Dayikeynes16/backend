<script setup>
import { compactLine } from '@/composables/useSaleItemDisplay';
import { ref, computed } from 'vue';

const props = defineProps({
    sale: { type: Object, required: true },
    businessName: { type: String, default: 'Carniceria' },
    branchName: { type: String, default: '' },
    branchAddress: { type: String, default: '' },
    branchPhone: { type: String, default: '' },
    cashierName: { type: String, default: '' },
    ticketConfig: { type: Object, default: null },
});

const emit = defineEmits(['close']);

// Merge defaults with stored config
const c = computed(() => ({
    header_business_name: true,
    header_branch_name: true,
    header_address: true,
    header_phone: true,
    header_custom: '',
    show_date: true,
    show_folio: true,
    show_cashier: true,
    show_payment_method: true,
    footer_message: 'Gracias por su compra',
    footer_custom: '',
    width: '80mm',
    ...(props.ticketConfig || {}),
}));

const printTicket = () => window.print();
const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);
const isPaid = computed(() => parseFloat(props.sale.amount_pending) <= 0);
const now = new Date().toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
const previewWidth = computed(() => c.value.width === '58mm' ? '260px' : '340px');
</script>

<template>
    <Teleport to="body">
        <!-- Screen overlay -->
        <div class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 backdrop-blur-sm print:hidden" @click.self="emit('close')">
            <div class="flex flex-col items-center gap-4">
                <div class="rounded-2xl bg-white p-6 shadow-2xl" :style="{ width: previewWidth }">
                    <p class="mb-3 text-center text-xs text-gray-400">Vista previa ({{ c.width }})</p>
                    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-4">
                        <div class="text-center">
                            <p v-if="c.header_business_name" class="text-sm font-bold">{{ businessName }}</p>
                            <p v-if="c.header_branch_name && branchName" class="text-xs text-gray-500">{{ branchName }}</p>
                            <p v-if="c.header_address && branchAddress" class="text-xs text-gray-500">{{ branchAddress }}</p>
                            <p v-if="c.header_phone && branchPhone" class="text-xs text-gray-500">Tel: {{ branchPhone }}</p>
                            <p v-if="c.header_custom" class="text-xs text-gray-500">{{ c.header_custom }}</p>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <div class="space-y-0.5">
                            <p v-if="c.show_folio" class="text-xs font-bold">{{ sale.folio }}</p>
                            <p v-if="c.show_date" class="text-xs text-gray-400">{{ now }}</p>
                            <p v-if="c.show_cashier && cashierName" class="text-xs text-gray-400">Cajero: {{ cashierName }}</p>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <div class="space-y-1">
                            <div v-for="item in sale.items" :key="item.id" class="flex justify-between text-xs">
                                <span class="flex-1 truncate">{{ compactLine(item) }}</span>
                                <span class="ml-2 font-medium">${{ parseFloat(item.subtotal).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <div class="flex justify-between text-sm font-bold">
                            <span>TOTAL</span>
                            <span>${{ parseFloat(sale.total).toFixed(2) }}</span>
                        </div>
                        <div v-if="c.show_payment_method && sale.payments?.length > 0" class="mt-2 space-y-0.5">
                            <div v-for="p in sale.payments" :key="p.id" class="flex justify-between text-xs text-gray-600">
                                <span>{{ methodLabel(p.method) }}</span>
                                <span>${{ parseFloat(p.amount).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <p class="text-center text-xs font-bold" :class="isPaid ? 'text-green-700' : 'text-amber-700'">
                            {{ isPaid ? 'PAGADO' : `PENDIENTE: $${parseFloat(sale.amount_pending).toFixed(2)}` }}
                        </p>
                        <p v-if="c.footer_message" class="mt-2 text-center text-xs text-gray-400">{{ c.footer_message }}</p>
                        <p v-if="c.footer_custom" class="mt-1 text-center text-xs text-gray-400" style="white-space: pre-line;">{{ c.footer_custom }}</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button @click="printTicket" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">Imprimir</button>
                    <button @click="emit('close')" class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-600 shadow-sm ring-1 ring-gray-200 transition hover:bg-gray-50">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- Print-only content -->
        <div class="hidden print:block" :style="{ width: c.width }">
            <div style="font-family: monospace; font-size: 12px; line-height: 1.4; padding: 4px;">
                <div style="text-align: center;">
                    <div v-if="c.header_business_name" style="font-size: 14px; font-weight: bold;">{{ businessName }}</div>
                    <div v-if="c.header_branch_name && branchName" style="font-size: 11px;">{{ branchName }}</div>
                    <div v-if="c.header_address && branchAddress" style="font-size: 10px; color: #666;">{{ branchAddress }}</div>
                    <div v-if="c.header_phone && branchPhone" style="font-size: 10px; color: #666;">Tel: {{ branchPhone }}</div>
                    <div v-if="c.header_custom" style="font-size: 10px; color: #666;">{{ c.header_custom }}</div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div v-if="c.show_folio" style="font-weight: bold;">{{ sale.folio }}</div>
                <div v-if="c.show_date" style="font-size: 10px; color: #666;">{{ now }}</div>
                <div v-if="c.show_cashier && cashierName" style="font-size: 10px; color: #666;">Cajero: {{ cashierName }}</div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="margin-top: 6px;">
                    <div v-for="item in sale.items" :key="item.id" style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <span>{{ compactLine(item) }}</span>
                        <span>${{ parseFloat(item.subtotal).toFixed(2) }}</span>
                    </div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: bold;">
                    <span>TOTAL</span>
                    <span>${{ parseFloat(sale.total).toFixed(2) }}</span>
                </div>
                <div v-if="c.show_payment_method && sale.payments?.length > 0" style="margin-top: 4px;">
                    <div v-for="p in sale.payments" :key="p.id" style="display: flex; justify-content: space-between; font-size: 11px;">
                        <span>{{ methodLabel(p.method) }}</span>
                        <span>${{ parseFloat(p.amount).toFixed(2) }}</span>
                    </div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="text-align: center; font-weight: bold;">
                    {{ isPaid ? 'PAGADO' : `PENDIENTE: $${parseFloat(sale.amount_pending).toFixed(2)}` }}
                </div>
                <div v-if="c.footer_message" style="text-align: center; font-size: 10px; margin-top: 6px; color: #666;">{{ c.footer_message }}</div>
                <div v-if="c.footer_custom" style="text-align: center; font-size: 10px; margin-top: 2px; color: #666; white-space: pre-line;">{{ c.footer_custom }}</div>
                <div style="margin-top: 12px;" />
            </div>
        </div>
    </Teleport>
</template>
