<script setup>
import { usePurchaseAiDraft } from '@/composables/usePurchaseAiDraft';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    purchase: { type: Object, default: null }, // null = crear; objeto = editar
    providers: { type: Array, default: () => [] },
    purchaseProducts: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] }, // vacía si es admin-sucursal
    fixedBranchId: { type: Number, default: null }, // si admin-sucursal
    // Propuesta IA opcional (sólo en modo crear). { draftId, proposal, audioTranscription }
    aiResult: { type: Object, default: null },
    // Modo caja: muestra el campo "pagado en efectivo" y postea a la ruta de caja.
    cashMode: { type: Boolean, default: false },
    routes: {
        type: Object,
        required: true,
        validator: (v) => v.store && v.update,
    },
});
const emit = defineEmits(['close']);

// Campos prerellenados por IA (para badge ✨).
const aiFilled = ref(new Set());
const isAiField = (key) => aiFilled.value.has(key);
const aiBadge = 'rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700';

const { applyProposalToForm } = usePurchaseAiDraft();

const aiConfidence = computed(() => props.aiResult?.proposal?.confianza || null);
const aiAlerts = computed(() => props.aiResult?.proposal?.alertas || []);
const aiTranscription = computed(() => props.aiResult?.audioTranscription || null);
const aiSuggestedProvider = computed(() => props.aiResult?.proposal?.sugerencia_nuevo_proveedor || null);

const confidenceBannerClass = computed(() => ({
    alta: 'bg-emerald-50 border-emerald-200 text-emerald-900',
    media: 'bg-amber-50 border-amber-200 text-amber-900',
    baja: 'bg-red-50 border-red-200 text-red-900',
})[aiConfidence.value] || 'bg-gray-50 border-gray-200 text-gray-800');

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const isEdit = computed(() => !!props.purchase?.id);

const todayIso = () => new Date().toISOString().slice(0, 10);

const emptyLine = () => ({
    purchase_product_id: null,
    concept: '',
    quantity: 1,
    unit: 'kg',
    unit_price: 0,
    notes: '',
});

const form = useForm({
    provider_id: '',
    branch_id: props.fixedBranchId || '',
    invoice_number: '',
    purchased_at: todayIso(),
    notes: '',
    items: [emptyLine()],
    attachments: [],
    ai_draft_id: null,
    paid_amount: 0,
});

watch(() => props.open, (open) => {
    if (!open) return;
    aiFilled.value = new Set();
    if (props.purchase) {
        form.provider_id = props.purchase.provider?.id ?? '';
        form.branch_id = props.fixedBranchId || props.purchase.branch?.id || '';
        form.invoice_number = props.purchase.invoice_number ?? '';
        form.purchased_at = props.purchase.purchased_at ? props.purchase.purchased_at.slice(0, 10) : todayIso();
        form.notes = props.purchase.notes ?? '';
        form.items = (props.purchase.items || []).map((i) => ({
            purchase_product_id: i.purchase_product_id ?? null,
            concept: i.concept ?? '',
            quantity: Number(i.quantity ?? 0),
            unit: i.unit ?? 'kg',
            unit_price: Number(i.unit_price ?? 0),
            notes: i.notes ?? '',
        }));
        if (form.items.length === 0) form.items = [emptyLine()];
        form.attachments = [];
        form.ai_draft_id = null;
    } else {
        form.reset();
        form.branch_id = props.fixedBranchId || '';
        form.purchased_at = todayIso();
        form.items = [emptyLine()];
        form.attachments = [];
        form.ai_draft_id = props.aiResult?.draftId || null;
        form.paid_amount = 0;
        form.clearErrors();

        // Aplica la propuesta IA si vino.
        if (props.aiResult?.proposal) {
            const filled = applyProposalToForm(form, props.aiResult.proposal);
            aiFilled.value = new Set(filled);
        }
    }
});

const addLine = () => { form.items.push(emptyLine()); };
const removeLine = (idx) => {
    if (form.items.length <= 1) return;
    form.items.splice(idx, 1);
};

const lineSubtotal = (line) => Number(line.quantity || 0) * Number(line.unit_price || 0);
const total = computed(() => form.items.reduce((s, l) => s + lineSubtotal(l), 0));

const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const onFiles = (e) => { form.attachments = Array.from(e.target.files || []); };

const close = () => { form.clearErrors(); emit('close'); };

const submit = () => {
    const opts = {
        preserveScroll: true,
        forceFormData: form.attachments.length > 0,
        onSuccess: () => close(),
    };
    if (isEdit.value) {
        form.put(route(props.routes.update, { tenant: slug.value, compra: props.purchase.id }), opts);
    } else {
        form.post(route(props.routes.store, slug.value), opts);
    }
};

const units = ['kg', 'g', 'l', 'ml', 'pieza', 'caja', 'bulto', 'cabeza'];

// Resuelve el texto escrito contra el catálogo: si coincide exacto, fija el id
// y hereda la unidad; si no, deja id null y el server lo crea al guardar.
const onConceptInput = (line) => {
    const match = props.purchaseProducts.find(
        (p) => p.name.toLowerCase() === (line.concept || '').trim().toLowerCase()
    );
    line.purchase_product_id = match ? match.id : null;
    if (match && match.unit) line.unit = match.unit;
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-4xl overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar compra' : 'Nueva compra' }}</h2>
                        <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="max-h-[80vh] space-y-5 overflow-y-auto px-5 py-5">
                        <!-- Banner IA -->
                        <div v-if="aiResult" :class="['rounded-xl border px-4 py-3 text-sm', confidenceBannerClass]">
                            <div class="flex items-start gap-2">
                                <span class="rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 px-2 py-0.5 text-xs font-bold text-white">IA</span>
                                <div class="flex-1 space-y-1">
                                    <p>
                                        Propuesta cargada. Confianza:
                                        <strong>{{ aiConfidence || 'baja' }}</strong>. Revisa cada campo (los marcados con ✨) antes de guardar.
                                    </p>
                                    <p v-if="aiTranscription" class="text-xs italic">
                                        Transcripción de tu nota de voz: "{{ aiTranscription }}"
                                    </p>
                                    <ul v-if="aiAlerts.length" class="ml-3 list-disc text-xs">
                                        <li v-for="(a, i) in aiAlerts" :key="i">{{ a }}</li>
                                    </ul>
                                    <p v-if="aiSuggestedProvider" class="rounded-lg bg-white/60 px-3 py-2 text-xs">
                                        💡 La IA no encontró este proveedor en el catálogo. Sugiere crear: <strong>{{ aiSuggestedProvider.nombre_propuesto }}</strong>
                                        <span v-if="aiSuggestedProvider.tipo_sugerido"> ({{ aiSuggestedProvider.tipo_sugerido }})</span>.
                                        Pídele al admin-empresa que lo cree o asigna uno existente abajo.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Cabecera -->
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 flex items-center gap-2 text-sm font-medium text-gray-700">
                                    Proveedor <span class="text-red-600">*</span>
                                    <span v-if="isAiField('provider_id')" :class="aiBadge">✨ IA</span>
                                </label>
                                <select v-model="form.provider_id" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option value="">— elige proveedor —</option>
                                    <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                                <p v-if="form.errors.provider_id" class="mt-1 text-xs text-red-600">{{ form.errors.provider_id }}</p>
                            </div>
                            <div v-if="!fixedBranchId">
                                <label class="mb-1 block text-sm font-medium text-gray-700">Sucursal <span class="text-red-600">*</span></label>
                                <select v-model="form.branch_id" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option value="">— elige sucursal —</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                                <p v-if="form.errors.branch_id" class="mt-1 text-xs text-red-600">{{ form.errors.branch_id }}</p>
                            </div>
                            <div v-else>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Sucursal</label>
                                <div class="rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-700">Tu sucursal (forzada)</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Fecha del comprobante <span class="text-red-600">*</span></label>
                                <input v-model="form.purchased_at" type="date" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                                <p v-if="form.errors.purchased_at" class="mt-1 text-xs text-red-600">{{ form.errors.purchased_at }}</p>
                            </div>
                            <div>
                                <label class="mb-1 flex items-center gap-2 text-sm font-medium text-gray-700">
                                    Núm. de factura del proveedor
                                    <span v-if="isAiField('invoice_number')" :class="aiBadge">✨ IA</span>
                                </label>
                                <input v-model="form.invoice_number" type="text" placeholder="F-4521"
                                    class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                            </div>
                        </div>

                        <!-- Líneas -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-sm font-bold uppercase tracking-wide text-gray-700">Líneas</h3>
                                <button type="button" @click="addLine" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-200">+ Agregar línea</button>
                            </div>
                            <div class="overflow-x-auto rounded-xl border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-gray-600">Concepto</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Cantidad</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-gray-600">Unidad</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Precio unit.</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-600">Subtotal</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="(line, idx) in form.items" :key="idx">
                                            <td class="px-3 py-2">
                                                <input v-model="line.concept" type="text" list="catalogo-compra" placeholder="Busca o escribe un producto"
                                                    @input="onConceptInput(line)" @change="onConceptInput(line)"
                                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
                                                <p v-if="form.errors[`items.${idx}.concept`]" class="mt-1 text-xs text-red-600">{{ form.errors[`items.${idx}.concept`] }}</p>
                                            </td>
                                            <td class="px-3 py-2">
                                                <input v-model.number="line.quantity" type="number" step="0.001" min="0" inputmode="decimal"
                                                    class="w-24 rounded-lg border-gray-300 text-right text-sm focus:border-orange-500 focus:ring-orange-500" />
                                            </td>
                                            <td class="px-3 py-2">
                                                <select v-model="line.unit" class="w-20 rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                                    <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <input v-model.number="line.unit_price" type="number" step="0.0001" min="0" inputmode="decimal"
                                                    class="w-28 rounded-lg border-gray-300 text-right text-sm focus:border-orange-500 focus:ring-orange-500" />
                                            </td>
                                            <td class="px-3 py-2 text-right text-sm font-semibold text-gray-900">{{ fmt(lineSubtotal(line)) }}</td>
                                            <td class="px-3 py-2">
                                                <button type="button" @click="removeLine(idx)" :disabled="form.items.length <= 1"
                                                    class="text-red-600 hover:text-red-800 disabled:opacity-40">✕</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="bg-gray-50">
                                        <tr>
                                            <td colspan="4" class="px-3 py-2 text-right text-sm font-semibold text-gray-700">Total</td>
                                            <td class="px-3 py-2 text-right text-base font-bold text-gray-900">{{ fmt(total) }}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p v-if="form.errors.items" class="mt-1 text-xs text-red-600">{{ form.errors.items }}</p>
                        </div>

                        <!-- Pagado en efectivo (modo caja, solo al crear) -->
                        <div v-if="cashMode && !purchase" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                            <label class="mb-1 block text-sm font-medium text-emerald-900">Pagado en efectivo ahora</label>
                            <div class="relative inline-block w-48">
                                <input v-model.number="form.paid_amount" type="number" step="0.01" min="0" :max="total"
                                    class="w-full rounded-lg border-gray-300 py-2 pl-3 pr-20 text-right text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                <button type="button" @click="form.paid_amount = Number(total.toFixed(2))"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-200 active:bg-emerald-300">
                                    Exacto
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-emerald-700">Sale del cajón y descuenta del corte. Lo no pagado queda como saldo del proveedor.</p>
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                        </div>

                        <!-- Notas -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Notas</label>
                            <textarea v-model="form.notes" rows="2"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                placeholder="Detalles adicionales (chofer, lote, etc.)"></textarea>
                        </div>

                        <!-- Adjuntos -->
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Adjuntar factura / comprobantes</label>
                            <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" @change="onFiles"
                                class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
                            <p class="mt-1 text-xs text-gray-500">Hasta 5 archivos · jpg/png/webp/pdf · 5 MB c/u</p>
                        </div>

                        <datalist id="catalogo-compra">
                            <option v-for="p in purchaseProducts" :key="p.id" :value="p.name" />
                        </datalist>
                    </form>

                    <footer class="flex items-center justify-between gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <span class="text-sm text-gray-700">Total: <span class="font-bold text-gray-900">{{ fmt(total) }}</span></span>
                        <div class="flex gap-2">
                            <button type="button" @click="close" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                            <button @click="submit" :disabled="form.processing"
                                class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700 disabled:opacity-50">
                                {{ form.processing ? 'Guardando…' : (isEdit ? 'Actualizar' : 'Registrar compra') }}
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
