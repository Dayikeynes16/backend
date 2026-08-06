const MODES = new Set(['dependencies', 'data', 'sync']);
const VIEWS = new Set(['map', 'list']);

export function parseArchitectureQuery(search, graph) {
    const params = new URLSearchParams(search);
    const requestedApplicationId = params.get('app');
    const applicationId = graph.applicationsById.has(requestedApplicationId)
        ? requestedApplicationId
        : null;
    const requestedModuleId = params.get('module');
    const module = graph.modulesById.get(requestedModuleId);
    const moduleId = module?.applicationId === applicationId ? requestedModuleId : null;
    const requestedMode = params.get('mode');
    const requestedView = params.get('view');

    return {
        applicationId,
        moduleId,
        mode: MODES.has(requestedMode) ? requestedMode : 'dependencies',
        view: VIEWS.has(requestedView) ? requestedView : 'map',
    };
}

export function serializeArchitectureQuery(state) {
    const params = new URLSearchParams();

    if (state.applicationId) params.set('app', state.applicationId);
    if (state.moduleId) params.set('module', state.moduleId);
    if (state.mode && state.mode !== 'dependencies') params.set('mode', state.mode);
    if (state.view && state.view !== 'map') params.set('view', state.view);

    const query = params.toString();

    return query ? `?${query}` : '';
}
