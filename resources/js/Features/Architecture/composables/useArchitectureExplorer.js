import { computed, onMounted, shallowRef, watch } from 'vue';
import {
    createArchitectureGraph,
    getVisibleConnections,
    searchEntities,
} from '../lib/architectureGraph.js';
import {
    parseArchitectureQuery,
    serializeArchitectureQuery,
} from '../lib/architectureQuery.js';

const DEFAULT_STATE = {
    applicationId: null,
    moduleId: null,
    mode: 'dependencies',
    view: 'map',
};

export function useArchitectureExplorer(manifest) {
    const graph = createArchitectureGraph(manifest);
    const initial = typeof window === 'undefined'
        ? DEFAULT_STATE
        : parseArchitectureQuery(window.location.search, graph);

    const selectedApplicationId = shallowRef(initial.applicationId);
    const selectedModuleId = shallowRef(initial.moduleId);
    const mode = shallowRef(initial.mode);
    const view = shallowRef(initial.view);
    const query = shallowRef('');
    const statusId = shallowRef('');
    const connectionKind = shallowRef('');

    const selectedApplication = computed(() => (
        graph.applicationsById.get(selectedApplicationId.value) ?? null
    ));
    const selectedModule = computed(() => (
        graph.modulesById.get(selectedModuleId.value) ?? null
    ));
    const level = computed(() => {
        if (selectedModule.value) return 'module';
        if (selectedApplication.value) return 'application';

        return 'ecosystem';
    });
    const filteredModules = computed(() => searchEntities(graph, query.value, {
        applicationId: selectedApplicationId.value,
        statusId: statusId.value,
        connectionKind: connectionKind.value,
    }));
    const visibleConnections = computed(() => getVisibleConnections(
        graph,
        selectedModuleId.value ?? selectedApplicationId.value,
        mode.value,
    ));

    function selectApplication(id) {
        if (!graph.applicationsById.has(id)) return;

        selectedApplicationId.value = id;
        selectedModuleId.value = null;
    }

    function selectModule(id) {
        const module = graph.modulesById.get(id);
        if (!module) return;

        selectedApplicationId.value = module.applicationId;
        selectedModuleId.value = id;
    }

    function goToEcosystem() {
        selectedApplicationId.value = null;
        selectedModuleId.value = null;
    }

    function goToApplication() {
        selectedModuleId.value = null;
    }

    function clearFilters() {
        query.value = '';
        statusId.value = '';
        connectionKind.value = '';
    }

    onMounted(() => {
        if (typeof window === 'undefined') return;

        watch(
            [selectedApplicationId, selectedModuleId, mode, view],
            () => {
                const architectureQuery = serializeArchitectureQuery({
                    applicationId: selectedApplicationId.value,
                    moduleId: selectedModuleId.value,
                    mode: mode.value,
                    view: view.value,
                });

                window.history.replaceState(
                    {},
                    '',
                    `${window.location.pathname}${architectureQuery}`,
                );
            },
            { immediate: true },
        );
    });

    return {
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
    };
}
