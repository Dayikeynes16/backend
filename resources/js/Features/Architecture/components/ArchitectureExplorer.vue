<script setup>
import { computed } from 'vue';
import ArchitectureBreadcrumbs from './ArchitectureBreadcrumbs.vue';
import ArchitectureDetailPanel from './ArchitectureDetailPanel.vue';
import ArchitectureLegend from './ArchitectureLegend.vue';
import ArchitectureListView from './ArchitectureListView.vue';
import ArchitectureToolbar from './ArchitectureToolbar.vue';
import ApplicationScene from './ApplicationScene.vue';
import EcosystemScene from './EcosystemScene.vue';
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
const entityNames = computed(() => Object.fromEntries(
    [...graph.entitiesById].map(([id, entity]) => [id, entity.name ?? id]),
));
const entityApplicationIds = computed(() => Object.fromEntries(
    [...graph.entitiesById].map(([id, entity]) => [
        id,
        graph.applicationsById.has(id) ? id : (entity.applicationId ?? null),
    ]),
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
            <div class="grid items-start gap-5" :class="selectedModule ? '2xl:grid-cols-[minmax(0,1fr)_23.75rem]' : ''">
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

                    <ApplicationScene
                        v-else-if="selectedApplication"
                        :application="selectedApplication"
                        :modules="filteredModules"
                        :connections="visibleConnections"
                        :layout="manifest.visualLayout"
                        :statuses="manifest.statuses"
                        :entity-names="entityNames"
                        :entity-application-ids="entityApplicationIds"
                        :selected-id="selectedModule?.id ?? null"
                        :mode="mode"
                        @select-module="selectModule"
                    />
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
                :mode="mode"
            />
        </div>
    </div>
</template>
