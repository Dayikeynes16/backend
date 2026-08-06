const VISIBLE_CONNECTION_MODES = new Set(['dependencies', 'data', 'sync']);

function collectionById(collection = []) {
    return new Map(collection.map((entity) => [entity.id, entity]));
}

function referencedValues(ids, entitiesById, value) {
    return (ids ?? []).map((id) => value(entitiesById.get(id))).filter(Boolean);
}

function addAdjacentConnection(adjacency, entityId, connection) {
    const adjacent = adjacency.get(entityId) ?? [];

    if (!adjacent.some(({ id }) => id === connection.id)) {
        adjacency.set(entityId, [...adjacent, connection]);
    }
}

function applicationIdForEntity(graph, entityId) {
    if (graph.applicationsById.has(entityId)) return entityId;

    return graph.entitiesById.get(entityId)?.applicationId ?? null;
}

function requiredRepositoryUrl(repository) {
    let url;

    try {
        url = new URL(repository?.webUrl);
    } catch {
        throw new TypeError('Repository URL must be a valid GitHub HTTPS URL.');
    }

    const pathSegments = url.pathname.split('/').filter(Boolean);
    const isSafeGitHubRepository = url.protocol === 'https:'
        && url.hostname === 'github.com'
        && !url.port
        && !url.username
        && !url.password
        && !url.search
        && !url.hash
        && pathSegments.length >= 2;

    if (!isSafeGitHubRepository) {
        throw new TypeError('Repository URL must be an HTTPS GitHub repository without credentials, query, or hash.');
    }

    return `${url.origin}${url.pathname.replace(/\/+$/, '')}`;
}

function requiredSourcePath(sourceFile) {
    if (typeof sourceFile?.path !== 'string' || !sourceFile.path.trim()) {
        throw new TypeError('Source file path must be a non-empty relative path.');
    }

    const segments = sourceFile.path.split('/');

    if (segments.some((segment) => !segment.trim() || segment === '.' || segment === '..')) {
        throw new TypeError('Source file path cannot contain empty, dot, or parent segments.');
    }

    return segments.map((segment) => encodeURIComponent(segment)).join('/');
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
            addAdjacentConnection(graph.adjacentConnections, entityId, connection);

            const applicationId = applicationIdForEntity(graph, entityId);
            if (applicationId) addAdjacentConnection(graph.adjacentConnections, applicationId, connection);
        }
    }

    graph.searchable = (manifest.modules ?? []).map((module) => {
        const application = graph.applicationsById.get(module.applicationId);
        const endpoints = referencedValues(module.endpointIds, graph.endpointsById, (endpoint) => (
            endpoint ? `${endpoint.id} ${endpoint.name ?? ''} ${endpoint.method ?? ''} ${endpoint.path ?? ''}` : ''
        ));
        const events = referencedValues(module.eventIds, graph.eventsById, (event) => (
            event ? `${event.id} ${event.name ?? ''} ${event.channel ?? ''}` : ''
        ));
        const sourceFiles = referencedValues(module.sourceFileIds, graph.sourceFilesById, (sourceFile) => (
            sourceFile ? `${sourceFile.id} ${sourceFile.path}` : ''
        ));
        const tables = referencedValues(module.databaseTableIds, graph.databaseTablesById, (table) => (
            table ? `${table.id} ${table.name}` : ''
        ));

        return {
            ...module,
            normalizedSearch: normalizeSearchText([
                module.id,
                module.name,
                module.description,
                application?.id,
                application?.name,
                application?.description,
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
    if (typeof repository?.commit !== 'string' || !repository.commit.trim()) {
        throw new TypeError('Repository commit must be a non-empty string.');
    }

    const repositoryUrl = requiredRepositoryUrl(repository);
    const commit = encodeURIComponent(repository.commit.trim());
    const path = requiredSourcePath(sourceFile);

    return `${repositoryUrl}/blob/${commit}/${path}`;
}
