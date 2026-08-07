<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    useTemplateRef,
} from 'vue';
import ArchitectureBreadcrumbs from './ArchitectureBreadcrumbs.vue';
import ArchitectureDetailPanel from './ArchitectureDetailPanel.vue';
import ArchitectureLegend from './ArchitectureLegend.vue';
import ArchitectureListView from './ArchitectureListView.vue';
import ArchitectureToolbar from './ArchitectureToolbar.vue';
import ApplicationScene from './ApplicationScene.vue';
import EcosystemScene from './EcosystemScene.vue';
import { useArchitectureExplorer } from '../composables/useArchitectureExplorer.js';
import {
    architectureManifestOrFallback,
    hasMinimumArchitectureManifest,
    isEditableEscapeTarget,
} from '../lib/architectureRuntime.js';

const props = defineProps({
    manifest: {
        type: Object,
        required: true,
        validator: (value) => value === null || typeof value === 'object',
    },
});

const hasMinimumManifest = hasMinimumArchitectureManifest(props.manifest);
const runtimeManifest = architectureManifestOrFallback(props.manifest);

const {
    graph,
    level,
    query,
    statusId,
    connectionKind,
    mode,
    view,
    selectedApplication,
    selectedModule,
    filteredModules,
    visibleConnections,
    selectApplication,
    selectModule,
    goToEcosystem,
    goToApplication,
    clearFilters,
} = useArchitectureExplorer(runtimeManifest);

const connectionKinds = computed(() => (
    [...new Set(runtimeManifest.connections.map((connection) => connection.kind))].sort()
));
const entityNames = computed(() => Object.fromEntries(
    [...graph.entitiesById].map(([id, entity]) => [id, entity.name ?? id]),
));
const entityApplicationIds = computed(() => Object.fromEntries(
    [...graph.entitiesById].map(([id, entity]) => [
        id,
        graph.applicationsById.has(id) ? id : (entity.applicationId ?? null),
    ]),
));
const sceneHeading = useTemplateRef('sceneHeading');
const currentLevelName = computed(() => {
    if (selectedModule.value) return selectedModule.value.name;
    if (selectedApplication.value) return selectedApplication.value.name;

    return 'Ecosistema completo';
});
const currentLevelDescription = computed(() => {
    if (selectedModule.value) return 'Detalle técnico del módulo seleccionado.';
    if (selectedApplication.value) return 'Planta técnica y módulos de la aplicación seleccionada.';

    return 'Aplicaciones, servicios y conexiones principales del sistema.';
});

function focusCurrentLevel() {
    nextTick(() => sceneHeading.value?.focus());
}

function handleSelectApplication(id) {
    if (!graph.applicationsById.has(id)) return;

    selectApplication(id);
    focusCurrentLevel();
}

function handleSelectModule(id) {
    if (!graph.modulesById.has(id)) return;

    selectModule(id);
    focusCurrentLevel();
}

function handleGoToEcosystem() {
    goToEcosystem();
    focusCurrentLevel();
}

function handleGoToApplication() {
    goToApplication();
    focusCurrentLevel();
}

function selectRelated(id) {
    if (graph.modulesById.has(id)) {
        handleSelectModule(id);
        return;
    }

    if (graph.applicationsById.has(id)) handleSelectApplication(id);
}

function onEscape(event) {
    if (event.key !== 'Escape') return;
    if (isEditableEscapeTarget(event.target)) return;
    if (!selectedModule.value) return;

    handleGoToApplication();
}

onMounted(() => {
    if (typeof window === 'undefined') return;

    window.addEventListener('keydown', onEscape);
});
onBeforeUnmount(() => {
    if (typeof window === 'undefined') return;

    window.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div class="architecture-explorer min-w-0 max-w-full overflow-hidden rounded-3xl border border-slate-300 bg-slate-100 shadow-sm">
        <div
            v-if="!hasMinimumManifest"
            role="alert"
            class="m-4 rounded-2xl border border-red-300 bg-red-50 px-5 py-6 text-sm font-semibold leading-6 text-red-950 sm:m-6"
        >
            No se pudo cargar el catálogo de arquitectura. Ejecuta npm run validate:architecture y revisa el manifiesto.
        </div>

        <template v-else>
        <ArchitectureBreadcrumbs
            :level="level"
            :application="selectedApplication"
            :module="selectedModule"
            @go-ecosystem="handleGoToEcosystem"
            @go-application="handleGoToApplication"
        />

        <ArchitectureToolbar
            v-model:query="query"
            v-model:status-id="statusId"
            v-model:connection-kind="connectionKind"
            v-model:mode="mode"
            v-model:view="view"
            :statuses="runtimeManifest.statuses"
            :connection-kinds="connectionKinds"
            @clear="clearFilters"
        />

        <div class="p-4 sm:p-6">
            <div
                v-if="view === 'map'"
                class="architecture-explorer__tablet-recommendation mb-4 items-center justify-between gap-4 rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-slate-800"
                role="note"
            >
                <p>En tablet, la vista Lista facilita el recorrido y conserva todos los detalles.</p>
                <button
                    type="button"
                    class="min-h-11 shrink-0 rounded-lg border border-sky-700 bg-white px-4 font-bold text-sky-900 outline-none focus-visible:ring-2 focus-visible:ring-sky-800 focus-visible:ring-offset-2"
                    @click="view = 'list'"
                >
                    Cambiar a vista Lista
                </button>
            </div>

            <header class="mb-4 border-l-4 border-red-700 pl-4">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-red-700">Nivel actual</p>
                <h2
                    ref="sceneHeading"
                    tabindex="-1"
                    class="mt-1 text-xl font-black text-slate-950 outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-4"
                >
                    {{ currentLevelName }}
                </h2>
                <p class="mt-1 text-sm text-slate-700">{{ currentLevelDescription }}</p>
            </header>

            <div
                class="architecture-explorer__layout grid items-start gap-5"
                :class="{ 'architecture-explorer__layout--with-detail': selectedModule }"
            >
                <section aria-label="Exploración de arquitectura" class="min-w-0">
                    <ArchitectureListView
                        v-if="view === 'list'"
                        :applications="runtimeManifest.applications"
                        :modules="filteredModules"
                        :statuses="runtimeManifest.statuses"
                        :query="query"
                        @select-application="handleSelectApplication"
                        @select-module="handleSelectModule"
                    />

                    <EcosystemScene
                        v-else-if="level === 'ecosystem'"
                        :applications="runtimeManifest.applications"
                        :connections="visibleConnections"
                        :layout="runtimeManifest.visualLayout"
                        :selected-id="selectedApplication?.id ?? null"
                        @select-application="handleSelectApplication"
                    />

                    <ApplicationScene
                        v-else-if="selectedApplication"
                        :application="selectedApplication"
                        :modules="filteredModules"
                        :connections="visibleConnections"
                        :layout="runtimeManifest.visualLayout"
                        :statuses="runtimeManifest.statuses"
                        :entity-names="entityNames"
                        :entity-application-ids="entityApplicationIds"
                        :selected-id="selectedModule?.id ?? null"
                        :mode="mode"
                        @select-module="handleSelectModule"
                    />
                </section>

                <ArchitectureDetailPanel
                    :entity="selectedModule"
                    :related="visibleConnections"
                    :manifest="runtimeManifest"
                    :open="Boolean(selectedModule)"
                    @close="handleGoToApplication"
                    @select-related="selectRelated"
                />
            </div>

            <ArchitectureLegend
                class="mt-5"
                :statuses="runtimeManifest.statuses"
                :connection-types="connectionKinds"
                :mode="mode"
            />
        </div>
        </template>
    </div>
</template>

<style scoped>
.architecture-explorer__tablet-recommendation {
    display: none;
}

@media (max-width: 48rem) {
    .architecture-explorer__tablet-recommendation {
        display: flex;
    }
}

@media (min-width: 112rem) {
    .architecture-explorer__layout--with-detail {
        grid-template-columns: minmax(0, 1fr) 23.75rem;
    }
}
</style>
