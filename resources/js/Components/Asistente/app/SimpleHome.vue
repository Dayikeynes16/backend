<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Modo simple (F4): punto de entrada con acciones grandes para usuarios con
// poca experiencia. No limita nada — cada acción compone una frase y la envía
// por el pipeline normal del chat (misma seguridad, mismos borradores); el
// input libre y el micrófono siguen abajo en ChatInputBar.
const props = defineProps({
    chat: { type: Object, required: true }, // instancia de useAssistantChat
});

const emit = defineEmits(['dismiss']);

// Acciones según el rol (D5): el cajero no ve resumen del negocio ni pagos a
// proveedor; los roles con turno (cajero, admin-sucursal) ven "Retirar efectivo".
const page = usePage();
const role = computed(() => page.props.auth.role);
const isCajero = computed(() => role.value === 'cajero');
const hasShiftRole = computed(() => ['cajero', 'admin-sucursal'].includes(role.value));

// Sección expandida: null | 'registrar' | 'cobrar' | 'pagar'
const expanded = ref(null);

// Mini-diálogo guiado (cobrar deuda / pagar proveedor).
const guided = ref({ name: '', amount: null, method: 'cash' });

const METHOD_LABELS = { cash: 'efectivo', card: 'tarjeta', transfer: 'transferencia' };

function toggle(section) {
    expanded.value = expanded.value === section ? null : section;
    guided.value = { name: '', amount: null, method: 'cash', reason: '' };
}

function sendPrompt(text) {
    if (props.chat.sending) return;
    props.chat.inputText = text;
    props.chat.send();
}

const guidedReady = () => guided.value.name.trim() !== '' && Number(guided.value.amount) > 0;

function sendCobro() {
    if (!guidedReady()) return;
    const { name, amount, method } = guided.value;
    sendPrompt(`El cliente ${name.trim()} pagó $${Number(amount).toFixed(2)} en ${METHOD_LABELS[method]}.`);
}

function sendPagoProveedor() {
    if (!guidedReady()) return;
    const { name, amount, method } = guided.value;
    sendPrompt(`Págale $${Number(amount).toFixed(2)} al proveedor ${name.trim()} por ${METHOD_LABELS[method]}.`);
}

const retiroReady = () => Number(guided.value.amount) > 0 && guided.value.reason.trim() !== '';

function sendRetiro() {
    if (!retiroReady()) return;
    const { amount, reason } = guided.value;
    sendPrompt(`Retira $${Number(amount).toFixed(2)} de la caja. Motivo: ${reason.trim()}.`);
}
</script>

<template>
    <div class="flex-1 space-y-3 overflow-y-auto p-5">
        <p class="text-sm font-medium text-gray-700">¿Qué quieres hacer?</p>

        <!-- 1. Resumen del negocio (no aplica al cajero) -->
        <button
            v-if="!isCajero"
            type="button"
            :disabled="chat.sending"
            @click="sendPrompt('¿Cómo va el negocio hoy? Dame el resumen de ventas.')"
            class="flex w-full items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-orange-300 hover:bg-orange-50 disabled:opacity-50"
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-600 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
            </span>
            <span>
                <span class="block text-base font-bold text-gray-900">¿Cómo va el negocio?</span>
                <span class="block text-xs text-gray-500">Ventas de hoy, tickets y comparación</span>
            </span>
        </button>

        <!-- 2. Registrar algo -->
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <button
                type="button"
                :disabled="chat.sending"
                @click="toggle('registrar')"
                class="flex w-full items-center gap-3 p-4 text-left transition hover:bg-orange-50 disabled:opacity-50"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-600 text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-base font-bold text-gray-900">Registrar algo</span>
                    <span class="block text-xs text-gray-500">Un gasto o una compra, con texto, voz o foto</span>
                </span>
                <svg class="h-4 w-4 text-gray-400 transition" :class="expanded === 'registrar' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div v-if="expanded === 'registrar'" class="flex gap-2 border-t border-gray-100 p-3">
                <button type="button" :disabled="chat.sending" @click="sendPrompt('Quiero registrar un gasto.')" class="flex-1 rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-orange-800 transition hover:bg-orange-100 disabled:opacity-50">
                    Un gasto
                </button>
                <button type="button" :disabled="chat.sending" @click="sendPrompt('Quiero registrar una compra a un proveedor.')" class="flex-1 rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-orange-800 transition hover:bg-orange-100 disabled:opacity-50">
                    Una compra
                </button>
            </div>
        </div>

        <!-- 3. Cobrar una deuda -->
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <button
                type="button"
                :disabled="chat.sending"
                @click="toggle('cobrar')"
                class="flex w-full items-center gap-3 p-4 text-left transition hover:bg-orange-50 disabled:opacity-50"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-600 text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-base font-bold text-gray-900">Cobrar una deuda</span>
                    <span class="block text-xs text-gray-500">Un cliente te paga lo que debe (fiado)</span>
                </span>
                <svg class="h-4 w-4 text-gray-400 transition" :class="expanded === 'cobrar' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div v-if="expanded === 'cobrar'" class="space-y-2 border-t border-gray-100 p-3">
                <input v-model="guided.name" type="text" placeholder="Nombre del cliente" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <input v-model.number="guided.amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="¿Cuánto pagó?" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <div class="flex gap-2">
                    <button v-for="(label, value) in METHOD_LABELS" :key="value" type="button" @click="guided.method = value"
                        :class="['flex-1 rounded-xl px-2 py-2 text-xs font-semibold capitalize transition', guided.method === value ? 'bg-orange-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                        {{ label }}
                    </button>
                </div>
                <div class="flex gap-2">
                    <button type="button" :disabled="!guidedReady() || chat.sending" @click="sendCobro"
                        class="flex-1 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-3 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                        Preparar cobro
                    </button>
                    <button type="button" :disabled="chat.sending" @click="sendPrompt('¿Cuánto me deben los clientes?')"
                        class="rounded-xl border border-gray-300 bg-white px-3 py-3 text-xs text-gray-600 transition hover:bg-gray-50 disabled:opacity-50">
                        ¿Quién me debe?
                    </button>
                </div>
            </div>
        </div>

        <!-- 4. Pagar a proveedor (no aplica al cajero) -->
        <div v-if="!isCajero" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <button
                type="button"
                :disabled="chat.sending"
                @click="toggle('pagar')"
                class="flex w-full items-center gap-3 p-4 text-left transition hover:bg-orange-50 disabled:opacity-50"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-600 text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-base font-bold text-gray-900">Pagar a proveedor</span>
                    <span class="block text-xs text-gray-500">Se aplica a sus compras más antiguas</span>
                </span>
                <svg class="h-4 w-4 text-gray-400 transition" :class="expanded === 'pagar' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div v-if="expanded === 'pagar'" class="space-y-2 border-t border-gray-100 p-3">
                <input v-model="guided.name" type="text" placeholder="Nombre del proveedor" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <input v-model.number="guided.amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="¿Cuánto le pagas?" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <div class="flex gap-2">
                    <button v-for="(label, value) in METHOD_LABELS" :key="value" type="button" @click="guided.method = value"
                        :class="['flex-1 rounded-xl px-2 py-2 text-xs font-semibold capitalize transition', guided.method === value ? 'bg-orange-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
                        {{ label }}
                    </button>
                </div>
                <div class="flex gap-2">
                    <button type="button" :disabled="!guidedReady() || chat.sending" @click="sendPagoProveedor"
                        class="flex-1 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-3 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                        Preparar pago
                    </button>
                    <button type="button" :disabled="chat.sending" @click="sendPrompt('¿Cuánto debo a mis proveedores?')"
                        class="rounded-xl border border-gray-300 bg-white px-3 py-3 text-xs text-gray-600 transition hover:bg-gray-50 disabled:opacity-50">
                        ¿Cuánto debo?
                    </button>
                </div>
            </div>
        </div>

        <!-- 4b. Retirar efectivo (roles con turno) -->
        <div v-if="hasShiftRole" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <button
                type="button"
                :disabled="chat.sending"
                @click="toggle('retirar')"
                class="flex w-full items-center gap-3 p-4 text-left transition hover:bg-orange-50 disabled:opacity-50"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-600 text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-base font-bold text-gray-900">Retirar efectivo</span>
                    <span class="block text-xs text-gray-500">Sale de la caja de tu turno abierto</span>
                </span>
                <svg class="h-4 w-4 text-gray-400 transition" :class="expanded === 'retirar' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div v-if="expanded === 'retirar'" class="space-y-2 border-t border-gray-100 p-3">
                <input v-model.number="guided.amount" type="number" step="0.01" min="0.01" inputmode="decimal" placeholder="¿Cuánto retiras?" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <input v-model="guided.reason" type="text" maxlength="255" placeholder="Motivo (gasolina, cambio…)" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                <button type="button" :disabled="!retiroReady() || chat.sending" @click="sendRetiro"
                    class="w-full rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-3 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Preparar retiro
                </button>
            </div>
        </div>

        <!-- 5. Chat libre -->
        <button
            type="button"
            @click="emit('dismiss')"
            class="flex w-full items-center gap-3 rounded-2xl border border-dashed border-gray-300 bg-white p-4 text-left transition hover:border-orange-300 hover:bg-orange-50"
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
            </span>
            <span>
                <span class="block text-base font-bold text-gray-900">Hablar con el asistente</span>
                <span class="block text-xs text-gray-500">Pregunta lo que quieras con texto o voz</span>
            </span>
        </button>

        <p class="pt-1 text-center text-[11px] text-gray-400">
            También puedes escribir o dictar directamente en el cuadro de abajo.
        </p>
    </div>
</template>
