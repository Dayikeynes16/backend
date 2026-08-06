<script setup>
import { computed } from 'vue';
import ArchitectureBreadcrumbs from './ArchitectureBreadcrumbs.vue';
import ArchitectureDetailPanel from './ArchitectureDetailPanel.vue';
import EcosystemScene from './EcosystemScene.vue';
import ArchitectureLegend from './ArchitectureLegend.vue';
import ArchitectureListView from './ArchitectureListView.vue';
import ArchitectureToolbar from './ArchitectureToolbar.vue';
import { useArchitectureExplorer } from '../composables/useArchitectureExplorer.js';

const props = defineProps({
    manifest: {
        type: Object,
        required: true,
        validator: (value) => (
            Array.isArray(value.applications)
            && Array.isArray(value.modules)
            && Array.isArray(value.connections)
        ),
    },
});

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
} = useArchitectureExplorer(props.manifest);

const connectionKinds = computed(() => (
    [...new Set(props.manifest.connections.map((connection) => connection.kind))].sort()
));

function selectRelated(id) {
    if (graph.modulesById.has(id)) {
        selectModule(id);
        return;
    }

    if (graph.applicationsById.has(id)) selectApplication(id);
}
</script>

<template>
    <div class="overflow-hidden rounded-3xl border border-slate-300 bg-slate-100 shadow-sm">
        <ArchitectureBreadcrumbs
            :level="level"
            :application="selectedApplication"
            :module="selectedModule"
            @go-ecosystem="goToEcosystem"
            @go-application="goToApplication"
        />

        <ArchitectureToolbar
            v-model:query="query"
            v-model:status-id="statusId"
            v-model:connection-kind="connectionKind"
            v-model:mode="mode"
            v-model:view="view"
            :statuses="manifest.statuses"
            :connection-kinds="connectionKinds"
            @clear="clearFilters"
        />

        <div class="p-4 sm:p-6">
            <div class="grid items-start gap-5" :class="selectedModule ? 'xl:grid-cols-[minmax(0,1fr)_26rem]' : ''">
                <section aria-label="Exploración de arquitectura" class="min-w-0">
                    <ArchitectureListView
                        v-if="view === 'list'"
                        :applications="manifest.applications"
                        :modules="filteredModules"
                        :statuses="manifest.statuses"
                        :query="query"
                        @select-application="selectApplication"
                        @select-module="selectModule"
                    />

                    <EcosystemScene
                        v-else-if="level === 'ecosystem'"
                        :applications="manifest.applications"
                        :connections="visibleConnections"
                        :layout="manifest.visualLayout"
                        :selected-id="selectedApplication?.id ?? null"
                        @select-application="selectApplication"
                    />

                    <div v-else class="rounded-3xl border border-gray-200 bg-slate-950 p-4 text-white shadow-sm">
                        <div class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-slate-700 bg-[linear-gradient(rgba(148,163,184,0.08)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,0.08)_1px,transparent_1px)] bg-[size:24px_24px] p-8 text-center">
                            <div>
                                <p class="font-mono text-xs uppercase tracking-[0.2em] text-red-300">Planta de aplicación · Hito 7</p>
                                <p class="mt-3 text-sm text-slate-300">Las habitaciones técnicas se incorporan en el siguiente hito.</p>
                                <button
                                    type="button"
                                    class="mt-5 min-h-11 rounded-lg border border-white/25 px-4 text-sm font-bold text-white outline-none transition hover:border-white hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white"
                                    @click="view = 'list'"
                                >
                                    Explorar en lista
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <ArchitectureDetailPanel
                    :entity="selectedModule"
                    :related="visibleConnections"
                    :manifest="manifest"
                    :open="Boolean(selectedModule)"
                    @close="goToApplication"
                    @select-related="selectRelated"
                />
            </div>

            <ArchitectureLegend
                class="mt-5"
                :statuses="manifest.statuses"
                :connection-types="connectionKinds"
            />
        </div>
    </div>
</template>
