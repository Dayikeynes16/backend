<script setup>
// Chips de acción sugerida bajo la última card de resultados: cada uno envía
// un prompt predefinido por el pipeline normal del chat (misma seguridad).
const props = defineProps({
    kind: { type: String, required: true },
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const ACTIONS = {
    sales_summary: [
        { label: 'Productos más vendidos', prompt: '¿Cuáles fueron los productos más vendidos hoy?' },
        { label: '¿Y los gastos?', prompt: '¿Cuánto he gastado hoy?' },
        { label: 'Ventas de la semana', prompt: '¿Cuánto vendí esta semana?' },
    ],
    expense_summary: [
        { label: '¿En qué gasté más?', prompt: '¿Cuáles son mis gastos más fuertes del mes?' },
        { label: 'Registrar un gasto', prompt: 'Quiero registrar un gasto.' },
    ],
    top_products: [
        { label: '¿Cuánto vendí hoy?', prompt: '¿Cuánto vendí hoy?' },
        { label: 'Top del mes', prompt: '¿Cuáles fueron los productos más vendidos este mes?' },
    ],
    customer_debt: [
        { label: 'Cobrar una deuda', prompt: 'Prepara el cobro de la deuda de un cliente.' },
    ],
    customer_top_buyers: [
        { label: '¿Cuánto me deben?', prompt: '¿Cuánto me deben los clientes?' },
    ],
    accounts_payable: [
        { label: 'Pagar a un proveedor', prompt: 'Prepara un pago a cuenta a un proveedor.' },
        { label: 'Compras del mes', prompt: '¿Cuánto he comprado este mes?' },
    ],
    purchase_summary: [
        { label: '¿Cuánto debo a proveedores?', prompt: '¿Cuánto debo a mis proveedores?' },
    ],
    shift_status: [
        { label: '¿Cuánto vendí hoy?', prompt: '¿Cuánto vendí hoy?' },
    ],
};

const actions = ACTIONS[props.kind] || [];

function run(action) {
    if (props.chat.sending) return;
    props.chat.inputText = action.prompt;
    props.chat.send();
}
</script>

<template>
    <div v-if="actions.length" class="flex flex-wrap gap-2">
        <button
            v-for="a in actions"
            :key="a.label"
            type="button"
            :disabled="chat.sending"
            @click="run(a)"
            class="rounded-full border border-orange-200 bg-orange-50 px-3.5 py-2 text-xs font-semibold text-orange-800 transition-[transform,background-color,border-color] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] hover:border-orange-400 hover:bg-orange-100 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50"
        >
            {{ a.label }}
        </button>
    </div>
</template>
