<script setup>
import SalesSummaryCard from './SalesSummaryCard.vue';
import ExpenseSummaryCard from './ExpenseSummaryCard.vue';
import TopProductsCard from './TopProductsCard.vue';
import ShiftStatusCard from './ShiftStatusCard.vue';
import CustomerStatsCard from './CustomerStatsCard.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    activeSessionId: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    budget: { type: Object, default: () => ({ remaining_cents: 0, cap_cents: 0 }) },
    // Route names for the current role. The page passes them in.
    routes: {
        type: Object,
        required: true,
        validator: (v) => v.index && v.createSession && v.sendMessage,
    },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);

const cardComponents = {
    sales_summary: SalesSummaryCard,
    expense_summary: ExpenseSummaryCard,
    top_products: TopProductsCard,
    shift_status: ShiftStatusCard,
    customer_debt: CustomerStatsCard,
    customer_top_buyers: CustomerStatsCard,
};

const messages = ref([...props.messages]);
const inputText = ref('');
const sending = ref(false);
const errorBanner = ref(null);
const threadRef = ref(null);

const sortedMessages = computed(() =>
    [...messages.value]
        .filter((m) => m.role !== 'tool' || (m.tool_status && m.tool_status !== 'success'))
        .sort((a, b) => a.id - b.id),
);

const cards = computed(() => {
    const out = [];
    for (const m of messages.value) {
        if (m.role === 'tool' && m.tool_status === 'success' && m.tool_result) {
            out.push({
                id: m.id,
                kind: m.tool_result.kind || guessKindFromToolName(m.tool_name),
                data: m.tool_result,
                tool_name: m.tool_name,
            });
        }
    }
    return out;
});

function guessKindFromToolName(name) {
    return ({
        consultar_ventas: 'sales_summary',
        consultar_gastos: 'expense_summary',
        consultar_productos_top: 'top_products',
        consultar_turnos: 'shift_status',
        consultar_clientes: 'customer_debt',
    })[name] || 'unknown';
}

watch(messages, async () => {
    await nextTick();
    threadRef.value?.scrollTo({ top: threadRef.value.scrollHeight, behavior: 'smooth' });
}, { deep: true });

const budgetText = computed(() => {
    const remaining = (props.budget?.remaining_cents ?? 0) / 100;
    const cap = (props.budget?.cap_cents ?? 0) / 100;
    return `Presupuesto IA del mes: $${remaining.toFixed(2)} / $${cap.toFixed(2)} USD`;
});

async function send() {
    const text = inputText.value.trim();
    if (!text || sending.value) return;
    if (!props.activeSessionId) {
        errorBanner.value = 'Crea una sesión primero.';
        return;
    }

    sending.value = true;
    errorBanner.value = null;

    const tempId = -Date.now();
    messages.value.push({
        id: tempId,
        role: 'user',
        content: text,
        created_at: new Date().toISOString(),
    });
    inputText.value = '';

    try {
        const url = route(props.routes.sendMessage, {
            tenant: slug.value,
            session: props.activeSessionId,
        });
        const { data } = await axios.post(url, { content: text });

        messages.value = messages.value.filter((m) => m.id !== tempId);
        for (const m of data.messages) {
            if (!messages.value.find((x) => x.id === m.id)) {
                messages.value.push(m);
            }
        }
    } catch (err) {
        messages.value = messages.value.filter((m) => m.id !== tempId);
        errorBanner.value = err.response?.data?.message || 'No pude enviar tu mensaje.';
    } finally {
        sending.value = false;
    }
}

function newSession() {
    router.post(route(props.routes.createSession, slug.value));
}

function switchSession(id) {
    router.get(route(props.routes.index, slug.value), { session: id }, { preserveScroll: true });
}

const examplePrompts = [
    '¿Cuánto vendí hoy?',
    'Top 5 productos de esta semana',
    'Gastos más fuertes del mes',
    '¿Qué turnos están abiertos?',
    '¿Cuánto me deben los clientes?',
];
</script>

<template>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[260px_1fr]">
        <aside class="flex flex-col gap-3 lg:max-h-[calc(100vh-9rem)] lg:overflow-y-auto">
            <button
                @click="newSession"
                class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nueva conversación
            </button>

            <div class="space-y-1">
                <button
                    v-for="s in sessions"
                    :key="s.id"
                    @click="switchSession(s.id)"
                    :class="[
                        'w-full truncate rounded-lg px-3 py-2 text-left text-sm transition',
                        s.id === activeSessionId
                            ? 'bg-orange-50 font-semibold text-orange-900 ring-1 ring-orange-200'
                            : 'text-gray-700 hover:bg-gray-100',
                    ]"
                >
                    {{ s.title || 'Sin título' }}
                </button>
                <p v-if="!sessions.length" class="px-3 py-2 text-sm italic text-gray-500">
                    Sin conversaciones aún.
                </p>
            </div>

            <div class="mt-auto rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
                {{ budgetText }}
            </div>
        </aside>

        <section class="flex min-h-[600px] flex-col rounded-2xl border border-gray-200 bg-white shadow-sm lg:max-h-[calc(100vh-9rem)]">
            <div ref="threadRef" class="flex-1 space-y-4 overflow-y-auto p-5">
                <div v-if="!activeSessionId" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
                    <p class="mb-2">No hay conversación activa.</p>
                    <button @click="newSession" class="text-orange-700 underline hover:text-orange-900">
                        Crear una nueva
                    </button>
                </div>

                <div v-else-if="!messages.length" class="space-y-3">
                    <p class="text-sm font-medium text-gray-700">Pregúntame algo. Por ejemplo:</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(p, i) in examplePrompts"
                            :key="i"
                            @click="inputText = p"
                            class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 transition hover:border-orange-400 hover:bg-orange-50 hover:text-orange-900"
                        >
                            {{ p }}
                        </button>
                    </div>
                </div>

                <template v-for="m in sortedMessages" :key="m.id">
                    <div v-if="m.role === 'user'" class="flex justify-end">
                        <div class="max-w-[78%] rounded-2xl rounded-tr-md bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm text-white shadow-sm">
                            {{ m.content }}
                        </div>
                    </div>

                    <div v-else-if="m.role === 'assistant'" class="space-y-3">
                        <div v-if="m.content" class="flex justify-start">
                            <div class="max-w-[78%] rounded-2xl rounded-tl-md bg-gray-100 px-4 py-2.5 text-sm text-gray-900">
                                {{ m.content }}
                            </div>
                        </div>
                        <template v-for="c in cards.filter(x => x.id > m.id - 5 && x.id < m.id)" :key="c.id">
                            <component :is="cardComponents[c.kind]" v-if="cardComponents[c.kind]" :data="c.data" />
                            <div v-else class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
                                <div class="mb-1 font-semibold">Resultado de {{ c.tool_name }}</div>
                                <pre class="overflow-x-auto whitespace-pre-wrap font-mono">{{ JSON.stringify(c.data, null, 2) }}</pre>
                            </div>
                        </template>
                    </div>

                    <div v-else-if="m.role === 'tool' && m.tool_status !== 'success'" class="flex justify-start">
                        <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                            Acción {{ m.tool_name }} rechazada: {{ m.tool_result?.message || m.tool_status }}
                        </div>
                    </div>
                </template>

                <div v-if="sending" class="flex justify-start">
                    <div class="flex items-center gap-2 rounded-2xl bg-gray-100 px-4 py-2.5 text-sm text-gray-600">
                        <span class="inline-flex space-x-1">
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0s"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0.15s"></span>
                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400" style="animation-delay: 0.3s"></span>
                        </span>
                        Pensando…
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-200 p-4">
                <div v-if="errorBanner" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">{{ errorBanner }}</div>
                <form @submit.prevent="send" class="flex items-end gap-2">
                    <textarea
                        v-model="inputText"
                        :disabled="!activeSessionId || sending"
                        rows="1"
                        placeholder="Escribe tu pregunta…"
                        class="flex-1 resize-none rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 disabled:bg-gray-50"
                        @keydown.enter.exact.prevent="send"
                    />
                    <button
                        type="submit"
                        :disabled="!activeSessionId || sending || !inputText.trim()"
                        class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-orange-600 hover:to-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Enviar
                    </button>
                </form>
            </div>
        </section>
    </div>
</template>
