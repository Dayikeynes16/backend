<script setup>
import { computed, ref, watch } from 'vue';
import { useCategoryAiDraft } from '@/composables/useCategoryAiDraft';

const props = defineProps({
    show: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    routePrefix: { type: String, default: 'empresa' },
    /** Resultado de useCategoryAiDraft.submitDraft() */
    draftResult: { type: Object, default: null },
    /** Para volver al modal de captura si el usuario quiere reintentar */
});

const emit = defineEmits(['close', 'saved', 'back-to-capture']);

const { submitDraft, applyDraft, applying, submitting } = useCategoryAiDraft();

// viewMode controla qué vista del template se renderiza. Diferente al "mode"
// del payload backend (que sólo es create_new|use_existing). Cuatro vistas:
//   'clarification'      → preguntas + textarea para reanalizar
//   'create_subcategory' → parent + subcategoría propuesta (mapea a backend use_existing)
//   'use_existing'       → categoría existente + mejoras + subcategorías nuevas
//   'create_new'         → form completo de categoría nueva
const viewMode = ref('create_new');
const category = ref({ name: '', description: '', aliases_text: '', includes_text: '', excludes_text: '' });
const existing = ref(null); // { id, name, reason } — parent (subcategory view) o categoría a reutilizar
const improvements = ref({ description: '', aliases_text: '', includes_text: '', excludes_text: '' });
const subcategories = ref([]);
const errorMessage = ref('');
const similarSubcategory = ref(null); // { id, name, reason } cuando aplica

// Estado iterativo cuando la IA pide aclaración. Mantenemos un draft local que
// se REEMPLAZA con cada reanálisis sin cerrar el modal, así el usuario puede
// ir refinando su pregunta hasta que la IA esté satisfecha.
const currentDraft = ref(null);
const clarificationText = ref('');

// Las acciones que envía el backend están en español (parser:
// 'crear_categoria' | 'usar_existente' | 'crear_subcategoria' | 'necesita_aclaracion').
const proposal = computed(() => currentDraft.value?.proposal || {});
const transcription = computed(() => currentDraft.value?.audioTranscription || null);

// Cuando el usuario hace switch a "crear nueva categoría" desde una sugerencia
// IA distinta (usar_existente o crear_subcategoria), guardamos el contexto para
// (1) mostrar banner amber + (2) pedir confirmación al guardar.
const aiSuggestionOverride = ref(null); // null | { type: 'reuse'|'subcategory', categoryName }

// Overlay "Editar mi explicación" — disponible en cualquier vista.
const manualEditOpen = ref(false);
const manualEditText = ref('');

const initialize = () => {
    errorMessage.value = '';
    aiSuggestionOverride.value = null;
    manualEditOpen.value = false;
    similarSubcategory.value = null;
    const p = currentDraft.value?.proposal || {};

    if (p.action === 'necesita_aclaracion') {
        viewMode.value = 'clarification';
        // Pre-poblamos el textarea con el texto original para refinar.
        const base = currentDraft.value?.originalText
            || currentDraft.value?.audioTranscription
            || p.audio_transcription
            || '';
        clarificationText.value = base ? base + '\n\n' : '';
        return;
    }

    if (p.action === 'usar_existente' && p.existing_category) {
        viewMode.value = 'use_existing';
        existing.value = p.existing_category;
        improvements.value = {
            description: p.improvements?.description || '',
            aliases_text: (p.improvements?.aliases_to_add || []).join(', '),
            includes_text: (p.improvements?.includes_to_add || []).join(', '),
            excludes_text: (p.improvements?.excludes_to_add || []).join(', '),
        };
    } else if (p.action === 'crear_subcategoria' && p.parent_category) {
        viewMode.value = 'create_subcategory';
        existing.value = p.parent_category;
        similarSubcategory.value = p.similar_subcategory || null;
    } else {
        viewMode.value = 'create_new';
        existing.value = null;
        category.value = {
            name: p.category?.name || '',
            description: p.category?.description || '',
            aliases_text: (p.category?.aliases || []).join(', '),
            includes_text: (p.category?.includes || []).join(', '),
            excludes_text: (p.category?.excludes || []).join(', '),
        };
    }

    subcategories.value = (p.subcategories || []).map(s => ({
        name: s.name || '',
        description: s.description || '',
        aliases_text: (s.aliases || []).join(', '),
        includes_text: (s.includes || []).join(', '),
        excludes_text: (s.excludes || []).join(', '),
    }));
};

// Cuando el prop draftResult cambia, sincronizamos el draft local y rerenderizamos.
watch(() => [props.show, props.draftResult], () => {
    if (props.show && props.draftResult) {
        currentDraft.value = props.draftResult;
        initialize();
    }
});

const reanalyze = async () => {
    const text = clarificationText.value.trim();
    if (!text || submitting.value) return;
    errorMessage.value = '';
    try {
        const result = await submitDraft({
            tenantSlug: props.tenantSlug,
            text,
            routePrefix: props.routePrefix,
        });
        // El nuevo draft REEMPLAZA al anterior; conservamos el texto enviado
        // como originalText para futuras iteraciones si vuelve a pedir más.
        currentDraft.value = { ...result, originalText: text };
        initialize();
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'No se pudo reanalizar.';
    }
};

const parseList = (text) => (text || '').split(',').map(s => s.trim()).filter(Boolean);

const switchToCreateNew = () => {
    // Capturamos el contexto de qué sugirió la IA para mostrar banner + confirmar al guardar.
    if (viewMode.value === 'create_subcategory') {
        aiSuggestionOverride.value = { type: 'subcategory', categoryName: existing.value?.name };
        // Pre-rellenar nombre con la subcategoría propuesta (es lo que el usuario quería como concepto).
        const firstSub = subcategories.value[0] || {};
        category.value = {
            name: firstSub.name || '',
            description: firstSub.description || '',
            aliases_text: firstSub.aliases_text || '',
            includes_text: firstSub.includes_text || '',
            excludes_text: firstSub.excludes_text || '',
        };
        // Vaciar subcategorías propuestas — el usuario está creando categoría nueva, decide aparte.
        subcategories.value = [];
    } else if (viewMode.value === 'use_existing') {
        aiSuggestionOverride.value = { type: 'reuse', categoryName: existing.value?.name };
        category.value = {
            name: '',
            description: improvements.value.description || '',
            aliases_text: improvements.value.aliases_text,
            includes_text: improvements.value.includes_text,
            excludes_text: improvements.value.excludes_text,
        };
    }
    viewMode.value = 'create_new';
    existing.value = null;
    similarSubcategory.value = null;
};

const useSimilarSubcategory = () => {
    // Sub-caso D.2: el usuario decide quedarse con la subcategoría que ya existe.
    // No persistimos nada, sólo cerramos con un mensaje informativo.
    const subName = similarSubcategory.value?.name;
    const catName = existing.value?.name;
    emit('saved', {
        message: `Mantienes la subcategoría existente "${subName}" en "${catName}". No se creó nada nuevo.`,
        unchanged: true,
    });
    emit('close');
};

const openManualEdit = () => {
    const base = currentDraft.value?.originalText
        || currentDraft.value?.audioTranscription
        || proposal.value?.audio_transcription
        || '';
    manualEditText.value = base ? base + '\n\n' : '';
    manualEditOpen.value = true;
};
const cancelManualEdit = () => { manualEditOpen.value = false; };
const submitManualEdit = async () => {
    const text = manualEditText.value.trim();
    if (!text || submitting.value) return;
    try {
        const result = await submitDraft({ tenantSlug: props.tenantSlug, text, routePrefix: props.routePrefix });
        currentDraft.value = { ...result, originalText: text };
        manualEditOpen.value = false;
        initialize();
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'No se pudo reanalizar.';
    }
};

const addSubcategory = () => {
    if (subcategories.value.length >= 8) return;
    subcategories.value.push({ name: '', description: '', aliases_text: '', includes_text: '', excludes_text: '' });
};
const removeSubcategory = (i) => { subcategories.value.splice(i, 1); };

const buildSubcategoriesPayload = () => subcategories.value
    .filter(s => s.name.trim() !== '')
    .map(s => ({
        name: s.name.trim(),
        description: s.description.trim() || null,
        aliases: parseList(s.aliases_text),
        includes: parseList(s.includes_text),
        excludes: parseList(s.excludes_text),
    }));

const save = async () => {
    if (applying.value) return;
    errorMessage.value = '';

    // Confirmación adicional cuando el usuario está overriding una sugerencia IA.
    if (aiSuggestionOverride.value && viewMode.value === 'create_new') {
        const msg = aiSuggestionOverride.value.type === 'subcategory'
            ? `La IA sugirió crear esto como SUBCATEGORÍA de "${aiSuggestionOverride.value.categoryName}". ¿Crear de todos modos como categoría nueva? Esto puede generar un catálogo redundante.`
            : `La IA sugirió REUTILIZAR la categoría "${aiSuggestionOverride.value.categoryName}". ¿Crear nueva de todos modos? Esto puede duplicar categorías.`;
        if (! window.confirm(msg)) return;
    }

    let payload;

    if (viewMode.value === 'create_subcategory' || viewMode.value === 'use_existing') {
        // Ambos modos UI mapean al backend `use_existing`.
        // - create_subcategory: agregar la subcategoría propuesta al parent (sin tocar parent).
        // - use_existing: mejorar categoría + agregar subcategorías nuevas.
        const updates = viewMode.value === 'use_existing'
            ? {
                description: improvements.value.description.trim() || null,
                aliases_to_add: parseList(improvements.value.aliases_text),
                includes_to_add: parseList(improvements.value.includes_text),
                excludes_to_add: parseList(improvements.value.excludes_text),
            }
            : { description: null, aliases_to_add: [], includes_to_add: [], excludes_to_add: [] };

        payload = {
            ai_draft_id: currentDraft.value?.draftId || null,
            mode: 'use_existing',
            existing_category_id: existing.value.id,
            category_updates: updates,
            subcategories: buildSubcategoriesPayload(),
        };

        if (viewMode.value === 'create_subcategory' && payload.subcategories.length === 0) {
            errorMessage.value = 'La subcategoría propuesta necesita un nombre.';
            return;
        }
    } else {
        if (!category.value.name.trim()) {
            errorMessage.value = 'El nombre de la categoría es obligatorio.';
            return;
        }
        payload = {
            ai_draft_id: currentDraft.value?.draftId || null,
            mode: 'create_new',
            category: {
                name: category.value.name.trim(),
                description: category.value.description.trim() || null,
                aliases: parseList(category.value.aliases_text),
                includes: parseList(category.value.includes_text),
                excludes: parseList(category.value.excludes_text),
            },
            subcategories: buildSubcategoriesPayload(),
        };
    }

    try {
        const result = await applyDraft({ tenantSlug: props.tenantSlug, payload, routePrefix: props.routePrefix });
        emit('saved', result);
        emit('close');
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'No se pudo guardar.';
    }
};

const confidence = computed(() => proposal.value?.confidence || 'baja');
const confidenceLabel = computed(() => ({
    alta: 'La IA está segura — revisa y confirma',
    media: 'Revisa con cuidado',
    baja: 'Verifica todo antes de guardar',
})[confidence.value]);
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" leave-active-class="transition duration-150" enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="show && draftResult" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm sm:items-center sm:p-4" @click.self="!applying && $emit('close')">
                <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl" @click.stop>
                    <!-- Header -->
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                        <div>
                            <h3 class="text-base font-bold text-gray-900">
                                <template v-if="viewMode === 'clarification'">Necesito más información</template>
                                <template v-else-if="viewMode === 'use_existing'">¿Reutilizar "{{ existing?.name }}"?</template>
                                <template v-else-if="viewMode === 'create_subcategory'">Esto encaja como subcategoría de "{{ existing?.name }}"</template>
                                <template v-else>Revisa la categoría propuesta</template>
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-500">
                                <template v-if="viewMode === 'clarification'">Responde lo que falta y reanaliza.</template>
                                <template v-else>Edita lo que necesites antes de guardar. La IA no guarda nada por sí sola.</template>
                            </p>
                        </div>
                        <button @click="!applying && !submitting && $emit('close')" :disabled="applying || submitting"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                        <!-- OVERLAY: editar mi explicación (cubre el contenido principal) -->
                        <template v-if="manualEditOpen">
                            <div class="rounded-2xl border border-violet-200 bg-violet-50/50 p-4">
                                <p class="text-sm font-bold text-violet-900">Edita tu explicación y reanaliza</p>
                                <p class="mt-0.5 text-xs text-gray-600">Puedes reformular, agregar detalle o cambiar de idea. La IA volverá a evaluar.</p>
                            </div>
                            <textarea v-model="manualEditText" rows="8" maxlength="2000" :disabled="submitting"
                                placeholder="Reformula o expande tu solicitud aquí…"
                                class="block w-full rounded-xl border-violet-300 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:ring-violet-300 disabled:bg-gray-50" />
                            <div v-if="submitting" class="flex items-center justify-center gap-3 rounded-2xl border border-violet-200 bg-violet-50/50 p-4">
                                <svg class="h-5 w-5 animate-spin text-violet-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <p class="text-sm font-semibold text-violet-800">Reanalizando…</p>
                            </div>
                        </template>

                        <template v-else>
                        <!-- Banner de confianza -->
                        <div v-if="viewMode !== 'clarification'" :class="[
                            'rounded-2xl border p-3',
                            confidence === 'alta' ? 'border-emerald-200 bg-emerald-50' :
                            confidence === 'media' ? 'border-amber-200 bg-amber-50' :
                            'border-red-200 bg-red-50',
                        ]">
                            <p class="text-sm font-bold" :class="{
                                'text-emerald-900': confidence === 'alta',
                                'text-amber-900': confidence === 'media',
                                'text-red-900': confidence === 'baja',
                            }">✨ {{ confidenceLabel }}</p>
                            <ul v-if="proposal.alerts?.length" class="mt-1 space-y-0.5 text-xs text-gray-700">
                                <li v-for="(a, i) in proposal.alerts" :key="i">· {{ a }}</li>
                            </ul>
                            <div v-if="transcription" class="mt-2 rounded-xl bg-white/70 p-2 ring-1 ring-violet-200">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-violet-700">🎤 Transcripción</p>
                                <p class="mt-0.5 text-xs italic text-gray-700">"{{ transcription }}"</p>
                            </div>
                        </div>

                        <!-- Banner amber persistente cuando user overrode una sugerencia IA -->
                        <div v-if="aiSuggestionOverride && viewMode === 'create_new'" class="rounded-2xl border border-amber-300 bg-amber-50 p-3">
                            <p class="text-sm font-bold text-amber-900">⚠️ Estás creando una categoría aunque la IA sugirió otra cosa</p>
                            <p class="mt-1 text-xs text-amber-800">
                                <template v-if="aiSuggestionOverride.type === 'subcategory'">
                                    La IA propuso esto como <strong>subcategoría de "{{ aiSuggestionOverride.categoryName }}"</strong>. Crear una categoría nueva puede generar redundancia.
                                </template>
                                <template v-else>
                                    La IA propuso <strong>reutilizar "{{ aiSuggestionOverride.categoryName }}"</strong>. Crear una nueva puede duplicar el catálogo.
                                </template>
                            </p>
                        </div>

                        <!-- CASO: aclaración (iterativo, sin cerrar el modal) -->
                        <template v-if="viewMode === 'clarification'">
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <p class="text-sm font-bold text-amber-900">La IA necesita más información para proponer una categoría:</p>
                                <ul class="mt-2 space-y-1.5">
                                    <li v-for="(q, i) in proposal.missing_questions" :key="i" class="flex gap-2 text-sm text-gray-800">
                                        <span class="font-bold text-amber-700">{{ i + 1 }}.</span>
                                        <span>{{ q }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="transcription && !currentDraft?.originalText" class="rounded-xl bg-violet-50 px-3 py-2 ring-1 ring-violet-200">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-violet-700">🎤 Lo que dijiste</p>
                                <p class="mt-0.5 text-xs italic text-gray-700">"{{ transcription }}"</p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-gray-600">Responde aquí o reformula tu solicitud</label>
                                <textarea v-model="clarificationText" rows="6" maxlength="2000" :disabled="submitting"
                                    placeholder="Responde las preguntas o agrega más detalle. Puedes editar el texto previo."
                                    class="block w-full rounded-xl border-violet-300 bg-white py-2.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:ring-violet-300 disabled:bg-gray-50" />
                                <p class="mt-1 text-[11px] text-gray-400">{{ clarificationText.length }} / 2000 caracteres</p>
                            </div>

                            <div v-if="submitting" class="rounded-2xl border border-violet-200 bg-violet-50/50 p-4 text-center">
                                <div class="flex items-center justify-center gap-3">
                                    <svg class="h-5 w-5 animate-spin text-violet-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <p class="text-sm font-semibold text-violet-800">Reanalizando con la nueva información…</p>
                                </div>
                            </div>
                        </template>

                        <!-- CASO: usar existente -->
                        <template v-else-if="viewMode === 'use_existing'">
                            <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-blue-700">Ya existe una categoría parecida</p>
                                <p class="mt-1 text-base font-bold text-gray-900">{{ existing?.name }}</p>
                                <p v-if="existing?.reason" class="mt-1 text-xs text-gray-700">{{ existing.reason }}</p>
                            </div>

                            <!-- Mejoras editables -->
                            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Mejoras a aplicar</p>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Descripción mejorada (opcional, reemplaza la actual si la dejas)</label>
                                    <textarea v-model="improvements.description" rows="2" maxlength="500"
                                        class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Aliases nuevos a sumar (separados por coma)</label>
                                    <input v-model="improvements.aliases_text" type="text" placeholder="Diésel, Camionetas, Llantas"
                                        class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Sí incluye (sumar)</label>
                                    <input v-model="improvements.includes_text" type="text" placeholder="Refacciones, Servicio mecánico"
                                        class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">No incluye (sumar)</label>
                                    <input v-model="improvements.excludes_text" type="text" placeholder="Mercancía, Sueldos"
                                        class="block w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                            </div>

                            <button type="button" @click="switchToCreateNew" class="text-xs font-semibold text-violet-700 underline hover:text-violet-900">
                                No, mejor crear una categoría nueva de todos modos
                            </button>
                        </template>

                        <!-- CASO: crear subcategoría dentro de un parent existente -->
                        <template v-else-if="viewMode === 'create_subcategory'">
                            <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-blue-700">Categoría padre sugerida</p>
                                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ existing?.name }}</p>
                                        <p v-if="existing?.reason" class="mt-1 text-xs text-gray-700">{{ existing.reason }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Sub-caso D.2: lado a lado cuando ya existe similar -->
                            <div v-if="similarSubcategory" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700">⚠️ Ya existe similar</p>
                                    <p class="mt-1 text-base font-bold text-gray-900">{{ similarSubcategory.name }}</p>
                                    <p class="text-[11px] text-gray-600">dentro de "{{ existing?.name }}"</p>
                                    <p v-if="similarSubcategory.reason" class="mt-1 text-xs text-gray-700">{{ similarSubcategory.reason }}</p>
                                    <button type="button" @click="useSimilarSubcategory"
                                        class="mt-3 w-full rounded-lg bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">
                                        Usar esa subcategoría
                                    </button>
                                </div>
                                <div class="rounded-2xl border border-violet-200 bg-white p-4">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-violet-700">O crea una nueva</p>
                                    <p class="mt-1 text-xs text-gray-600">Edita abajo si prefieres crear una distinta.</p>
                                </div>
                            </div>

                            <!-- Form editable de la subcategoría propuesta -->
                            <div class="space-y-3 rounded-2xl border-2 border-violet-300 bg-white p-4">
                                <p class="text-xs font-bold uppercase tracking-wider text-violet-700">Subcategoría a crear en "{{ existing?.name }}"</p>
                                <template v-if="subcategories.length === 0">
                                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">La IA no propuso una subcategoría concreta. Agrega una abajo o cancela.</p>
                                    <button type="button" @click="addSubcategory"
                                        class="rounded-lg bg-violet-50 px-3 py-2 text-xs font-bold text-violet-700 hover:bg-violet-100">+ Agregar subcategoría</button>
                                </template>
                                <template v-else>
                                    <div v-for="(sub, i) in subcategories" :key="i" class="space-y-2">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold text-gray-600">Nombre</label>
                                            <input v-model="sub.name" type="text" maxlength="120" required
                                                class="block w-full rounded-lg border-violet-300 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold text-gray-600">Descripción</label>
                                            <textarea v-model="sub.description" rows="2" maxlength="500"
                                                class="block w-full rounded-lg border-violet-300 text-xs shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold text-gray-600">Aliases (separados por coma)</label>
                                            <input v-model="sub.aliases_text" type="text"
                                                class="block w-full rounded-lg border-violet-300 text-xs shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="mb-1 block text-[11px] font-semibold text-emerald-700">✓ Sí incluye</label>
                                                <input v-model="sub.includes_text" type="text"
                                                    class="block w-full rounded-lg border-emerald-200 text-xs shadow-sm focus:border-emerald-400 focus:ring-emerald-300" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-semibold text-red-700">✗ No incluye</label>
                                                <input v-model="sub.excludes_text" type="text"
                                                    class="block w-full rounded-lg border-red-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <button type="button" @click="switchToCreateNew" class="text-xs font-semibold text-violet-700 underline hover:text-violet-900">
                                No, mejor crear como categoría nueva de todos modos
                            </button>
                        </template>

                        <!-- CASO: crear nueva -->
                        <template v-else>
                            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Categoría</p>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Nombre</label>
                                    <input v-model="category.name" type="text" maxlength="120" required
                                        class="block w-full rounded-xl border-violet-300 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Descripción</label>
                                    <textarea v-model="category.description" rows="3" maxlength="500"
                                        class="block w-full rounded-xl border-violet-300 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold text-gray-600">Aliases (separados por coma)</label>
                                    <input v-model="category.aliases_text" type="text"
                                        class="block w-full rounded-xl border-violet-300 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                </div>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-emerald-700">✓ Sí incluye</label>
                                        <input v-model="category.includes_text" type="text" placeholder="Gasolina, Llantas, Mantenimiento"
                                            class="block w-full rounded-xl border-emerald-200 text-sm shadow-sm focus:border-emerald-400 focus:ring-emerald-300" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold text-red-700">✗ No incluye</label>
                                        <input v-model="category.excludes_text" type="text" placeholder="Mercancía, Sueldos"
                                            class="block w-full rounded-xl border-red-200 text-sm shadow-sm focus:border-red-400 focus:ring-red-300" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Subcategorías auxiliares (sólo en create_new y use_existing — en create_subcategory ya están integradas arriba) -->
                        <div v-if="viewMode === 'create_new' || viewMode === 'use_existing'" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-500">
                                    Subcategorías ({{ subcategories.length }} / 8)
                                </p>
                                <button v-if="subcategories.length < 8" type="button" @click="addSubcategory"
                                    class="rounded-lg bg-violet-50 px-2.5 py-1 text-xs font-bold text-violet-700 hover:bg-violet-100">+ Agregar</button>
                            </div>

                            <p v-if="!subcategories.length" class="rounded-xl border border-dashed border-gray-200 px-3 py-4 text-center text-xs text-gray-500">
                                Sin subcategorías. Puedes agregar arriba o seguir sin ellas.
                            </p>

                            <div v-for="(sub, i) in subcategories" :key="i" class="space-y-2 rounded-xl border border-gray-200 bg-gray-50/50 p-3">
                                <div class="flex items-center justify-between">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Subcategoría {{ i + 1 }}</p>
                                    <button type="button" @click="removeSubcategory(i)" class="rounded-lg p-1 text-red-500 hover:bg-red-100" title="Quitar">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <input v-model="sub.name" type="text" maxlength="120" placeholder="Nombre"
                                    class="block w-full rounded-lg border-gray-200 text-sm shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                <textarea v-model="sub.description" rows="2" maxlength="500" placeholder="Descripción interna"
                                    class="block w-full rounded-lg border-gray-200 text-xs shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                <input v-model="sub.aliases_text" type="text" placeholder="Aliases (separados por coma)"
                                    class="block w-full rounded-lg border-gray-200 text-xs shadow-sm focus:border-violet-400 focus:ring-violet-300" />
                                <div class="grid grid-cols-2 gap-2">
                                    <input v-model="sub.includes_text" type="text" placeholder="Sí incluye"
                                        class="block w-full rounded-lg border-emerald-200 text-xs shadow-sm focus:border-emerald-400 focus:ring-emerald-300" />
                                    <input v-model="sub.excludes_text" type="text" placeholder="No incluye"
                                        class="block w-full rounded-lg border-red-200 text-xs shadow-sm focus:border-red-400 focus:ring-red-300" />
                                </div>
                            </div>
                        </div>

                        <p v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ errorMessage }}</p>
                        </template>
                    </div>

                    <!-- Footer -->
                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/50 px-6 py-4">
                        <!-- Overlay manualEdit tiene sus propios botones -->
                        <template v-if="manualEditOpen">
                            <button type="button" @click="cancelManualEdit" :disabled="submitting"
                                class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200 disabled:opacity-30">
                                Cancelar edición
                            </button>
                            <button type="button" @click="submitManualEdit" :disabled="submitting || !manualEditText.trim()"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg v-if="submitting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                {{ submitting ? 'Reanalizando…' : 'Reanalizar' }}
                            </button>
                        </template>

                        <template v-else>
                            <button type="button" @click="$emit('close')" :disabled="applying || submitting"
                                class="rounded-xl px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-200 disabled:opacity-30">
                                Cancelar
                            </button>

                            <!-- Botón siempre disponible para iterar: editar mi explicación -->
                            <button v-if="viewMode !== 'clarification'" type="button" @click="openManualEdit"
                                :disabled="applying || submitting"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-violet-200 bg-white px-3 py-2.5 text-xs font-semibold text-violet-700 transition hover:bg-violet-50 disabled:opacity-30">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                                Editar mi explicación
                            </button>

                            <!-- CTA principal según vista -->
                            <button v-if="viewMode === 'clarification'" type="button" @click="reanalyze"
                                :disabled="submitting || !clarificationText.trim()"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg v-if="!submitting" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09Z" /></svg>
                                <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                {{ submitting ? 'Reanalizando…' : 'Reanalizar con esta info' }}
                            </button>
                            <button v-else type="button" @click="save" :disabled="applying"
                                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:from-violet-700 hover:to-fuchsia-700 disabled:opacity-50">
                                <svg v-if="applying" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <template v-if="applying">Guardando…</template>
                                <template v-else-if="viewMode === 'use_existing'">Aplicar mejoras</template>
                                <template v-else-if="viewMode === 'create_subcategory'">Crear subcategoría</template>
                                <template v-else>Crear categoría</template>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
