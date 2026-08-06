const VISIBLE_CONNECTION_MODES = new Set(['dependencies', 'data', 'sync']);

function collectionById(collection = []) {
    return new Map(collection.map((entity) => [entity.id, entity]));
}

function referencedValues(ids, entitiesById, value) {
    return (ids ?? []).map((id) => value(entitiesById.get(id))).filter(Boolean);
}

export function normalizeSearchText(value = '') {
    return String(value)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

export function createArchitectureGraph(manifest) {
    const graph = {
        manifest,
        repositoriesById: collectionById(manifest.repositories),
        applicationsById: collectionById(manifest.applications),
        modulesById: collectionById(manifest.modules),
        endpointsById: collectionById(manifest.endpoints),
        eventsById: collectionById(manifest.events),
        databaseTablesById: collectionById(manifest.databaseTables),
        sourceFilesById: collectionById(manifest.sourceFiles),
        entitiesById: new Map(),
        adjacentConnections: new Map(),
        searchable: [],
    };

    const entityCollections = [
        manifest.applications,
        manifest.modules,
        manifest.components,
        manifest.dataSources,
        manifest.endpoints,
        manifest.events,
        manifest.databaseTables,
        manifest.devices,
        manifest.dependencies,
        manifest.permissions,
        manifest.featureFlags,
        manifest.sourceFiles,
        manifest.risks,
    ];

    for (const collection of entityCollections) {
        for (const entity of collection ?? []) {
            graph.entitiesById.set(entity.id, entity);
        }
    }

    for (const connection of manifest.connections ?? []) {
        for (const entityId of [connection.fromId, connection.toId]) {
            const adjacent = graph.adjacentConnections.get(entityId) ?? [];
            graph.adjacentConnections.set(entityId, [...adjacent, connection]);
        }
    }

    graph.searchable = (manifest.modules ?? []).map((module) => {
        const endpoints = referencedValues(module.endpointIds, graph.endpointsById, (endpoint) => (
            endpoint ? `${endpoint.method ?? ''} ${endpoint.path ?? ''}` : ''
        ));
        const events = referencedValues(module.eventIds, graph.eventsById, (event) => (
            event ? `${event.name ?? ''} ${event.channel ?? ''}` : ''
        ));
        const sourceFiles = referencedValues(module.sourceFileIds, graph.sourceFilesById, (sourceFile) => sourceFile?.path ?? '');
        const tables = referencedValues(module.databaseTableIds, graph.databaseTablesById, (table) => table?.name ?? '');

        return {
            ...module,
            normalizedSearch: normalizeSearchText([
                module.id,
                module.name,
                module.description,
                ...(module.searchTerms ?? []),
                ...endpoints,
                ...events,
                ...sourceFiles,
                ...tables,
            ].join(' ')),
        };
    });

    return graph;
}

export function searchEntities(graph, query = '', filters = {}) {
    const needle = normalizeSearchText(query);

    return graph.searchable.filter((module) => {
        if (needle && !module.normalizedSearch.includes(needle)) return false;
        if (filters.applicationId && module.applicationId !== filters.applicationId) return false;
        if (filters.statusId && module.status?.id !== filters.statusId) return false;

        if (filters.connectionKind) {
            const adjacent = graph.adjacentConnections.get(module.id) ?? [];
            if (!adjacent.some((connection) => connection.kind === filters.connectionKind)) return false;
        }

        return true;
    });
}

export function getVisibleConnections(graph, selectedEntityId = null, mode = 'dependencies') {
    if (!VISIBLE_CONNECTION_MODES.has(mode)) return [];

    if (selectedEntityId && !graph.entitiesById.has(selectedEntityId)) return [];

    const connections = selectedEntityId
        ? graph.adjacentConnections.get(selectedEntityId) ?? []
        : graph.manifest.connections ?? [];

    return connections.filter((connection) => (connection.viewModes ?? ['dependencies']).includes(mode));
}

export function buildSourceUrl(repository, sourceFile) {
    const repositoryUrl = String(repository.webUrl).replace(/\/+$/, '');
    const commit = encodeURIComponent(String(repository.commit));
    const path = String(sourceFile.path)
        .split('/')
        .map((segment) => {
            if (segment === '.') return '%2E';
            if (segment === '..') return '%2E%2E';

            return encodeURIComponent(segment);
        })
        .join('/');

    return `${repositoryUrl}/blob/${commit}/${path}`;
}
