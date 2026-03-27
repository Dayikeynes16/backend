<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    sale: { type: Object, required: true },
    businessName: { type: String, default: 'Carniceria' },
    branchName: { type: String, default: '' },
    width: { type: String, default: '80mm' }, // 58mm or 80mm
});

const emit = defineEmits(['close']);
const show = ref(true);

const printTicket = () => {
    window.print();
};

const methodLabel = (m) => ({ cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' }[m] || m);

const isPaid = computed(() => parseFloat(props.sale.amount_pending) <= 0);
const now = new Date().toLocaleString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Teleport to="body">
        <!-- Screen overlay (hidden when printing) -->
        <div v-if="show" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 backdrop-blur-sm print:hidden" @click.self="emit('close')">
            <div class="flex flex-col items-center gap-4">
                <div class="rounded-2xl bg-white p-6 shadow-2xl" :style="{ width: width === '58mm' ? '260px' : '340px' }">
                    <p class="mb-3 text-center text-xs text-gray-400">Vista previa del ticket ({{ width }})</p>
                    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-4">
                        <!-- Ticket preview mirrors print content -->
                        <div class="text-center">
                            <p class="text-sm font-bold">{{ businessName }}</p>
                            <p v-if="branchName" class="text-xs text-gray-500">{{ branchName }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ now }}</p>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <p class="text-xs font-bold">{{ sale.folio }}</p>
                        <div class="mt-2 space-y-1">
                            <div v-for="item in sale.items" :key="item.id" class="flex justify-between text-xs">
                                <span class="flex-1 truncate">{{ item.product_name }} x{{ parseFloat(item.quantity) }}</span>
                                <span class="ml-2 font-medium">${{ parseFloat(item.subtotal).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <div class="flex justify-between text-sm font-bold">
                            <span>TOTAL</span>
                            <span>${{ parseFloat(sale.total).toFixed(2) }}</span>
                        </div>
                        <div v-if="sale.payments && sale.payments.length > 0" class="mt-2 space-y-0.5">
                            <div v-for="p in sale.payments" :key="p.id" class="flex justify-between text-xs text-gray-600">
                                <span>{{ methodLabel(p.method) }}</span>
                                <span>${{ parseFloat(p.amount).toFixed(2) }}</span>
                            </div>
                        </div>
                        <div class="my-2 border-t border-dashed border-gray-300" />
                        <p class="text-center text-xs font-bold" :class="isPaid ? 'text-green-700' : 'text-amber-700'">
                            {{ isPaid ? 'PAGADO' : `PENDIENTE: $${parseFloat(sale.amount_pending).toFixed(2)}` }}
                        </p>
                        <p class="mt-2 text-center text-xs text-gray-400">Gracias por su compra</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button @click="printTicket" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700">
                        Imprimir
                    </button>
                    <button @click="emit('close')" class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-600 shadow-sm ring-1 ring-gray-200 transition hover:bg-gray-50">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>

        <!-- Print-only content (invisible on screen) -->
        <div class="hidden print:block" :style="{ width }">
            <div style="font-family: monospace; font-size: 12px; line-height: 1.4; padding: 4px;">
                <div style="text-align: center;">
                    <div style="font-size: 14px; font-weight: bold;">{{ businessName }}</div>
                    <div v-if="branchName" style="font-size: 11px;">{{ branchName }}</div>
                    <div style="font-size: 10px; color: #666;">{{ now }}</div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="font-weight: bold;">{{ sale.folio }}</div>
                <div style="margin-top: 6px;">
                    <div v-for="item in sale.items" :key="item.id" style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <span>{{ item.product_name }} x{{ parseFloat(item.quantity) }}</span>
                        <span>${{ parseFloat(item.subtotal).toFixed(2) }}</span>
                    </div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: bold;">
                    <span>TOTAL</span>
                    <span>${{ parseFloat(sale.total).toFixed(2) }}</span>
                </div>
                <div v-if="sale.payments && sale.payments.length > 0" style="margin-top: 4px;">
                    <div v-for="p in sale.payments" :key="p.id" style="display: flex; justify-content: space-between; font-size: 11px;">
                        <span>{{ methodLabel(p.method) }}</span>
                        <span>${{ parseFloat(p.amount).toFixed(2) }}</span>
                    </div>
                </div>
                <div style="border-top: 1px dashed #000; margin: 6px 0;" />
                <div style="text-align: center; font-weight: bold;">
                    {{ isPaid ? 'PAGADO' : `PENDIENTE: $${parseFloat(sale.amount_pending).toFixed(2)}` }}
                </div>
                <div style="text-align: center; font-size: 10px; margin-top: 6px; color: #666;">
                    Gracias por su compra
                </div>
                <div style="margin-top: 12px;" />
            </div>
        </div>
    </Teleport>
</template>
