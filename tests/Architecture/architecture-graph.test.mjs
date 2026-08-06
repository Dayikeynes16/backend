import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    buildSourceUrl,
    createArchitectureGraph,
    getVisibleConnections,
    normalizeSearchText,
    searchEntities,
} from '../../resources/js/Features/Architecture/lib/architectureGraph.js';
import { filterConnectionsByKind } from '../../resources/js/Features/Architecture/composables/useArchitectureExplorer.js';

const checkedInManifestPath = new URL(
    '../../resources/js/Features/Architecture/data/system-architecture.json',
    import.meta.url,
);

const manifest = {
    statuses: [{ id: 'implemented', label: 'Implementado' }],
    repositories: [{ id: 'repo.saas', webUrl: 'https://github.com/acme/saas/', commit: 'abc123' }],
    applications: [
        { id: 'app.saas', name: 'SaaS', description: 'Núcleo central' },
        { id: 'app.hub', name: 'Carnicería Hub', description: 'Sucursal local' },
    ],
    modules: [
        {
            id: 'saas.customers',
            applicationId: 'app.saas',
            name: 'Clientes',
            description: 'Cobro de fiado',
            status: { id: 'implemented' },
            searchTerms: ['cobranza'],
            endpointIds: ['endpoint.customers'],
            sourceFileIds: ['file.customers'],
        },
        {
            id: 'hub.sync',
            applicationId: 'app.hub',
            name: 'Sincronización',
            description: 'Cola local',
            status: { id: 'implemented' },
            searchTerms: [],
            endpointIds: ['endpoint.scale.sales.create'],
            eventIds: ['event.saas.sale-updated'],
            sourceFileIds: ['file.hub-sync'],
        },
    ],
    endpoints: [
        { id: 'endpoint.customers', applicationId: 'app.saas', method: 'GET', path: '/clientes especiales' },
        { id: 'endpoint.scale.sales.create', applicationId: 'app.hub', method: 'POST', path: '/api/v1/sales' },
    ],
    events: [{
        id: 'event.saas.sale-updated',
        applicationId: 'app.saas',
        name: 'SaleUpdated',
        channel: 'sucursal.{branchId}',
    }],
    components: [
        { id: 'component.saas.api', applicationId: 'app.saas' },
        { id: 'component.hub.sync', applicationId: 'app.hub' },
        { id: 'component.saas.cache', applicationId: 'app.saas' },
    ],
    dataSources: [],
    databaseTables: [],
    devices: [],
    dependencies: [],
    permissions: [],
    featureFlags: [],
    risks: [],
    evidence: [],
    sourceFiles: [
        { id: 'file.customers', repositoryId: 'repo.saas', path: 'app/Domain/Clientes y fiado.php' },
        { id: 'file.hub-sync', repositoryId: 'repo.saas', path: 'app/Hub/Sync.php' },
    ],
    connections: [
        { id: 'conn.dependency', fromId: 'app.saas', toId: 'saas.customers', kind: 'http', viewModes: ['dependencies'] },
        { id: 'conn.sync', fromId: 'hub.sync', toId: 'app.saas', kind: 'polling', viewModes: ['sync'] },
        { id: 'conn.data', fromId: 'saas.customers', toId: 'app.hub', kind: 'database', viewModes: ['data'] },
        { id: 'conn.components', fromId: 'component.saas.api', toId: 'component.hub.sync', kind: 'http', viewModes: ['dependencies'] },
        { id: 'conn.internal-components', fromId: 'component.saas.api', toId: 'component.saas.cache', kind: 'http', viewModes: ['dependencies'] },
    ],
};

test('normalizes case and accents deterministically', () => {
    assert.equal(normalizeSearchText('  COBRÁNZA Ñ  '), 'cobranza n');
});

test('search is case and accent insensitive across terms, endpoints and source paths', () => {
    const graph = createArchitectureGraph(manifest);

    assert.deepEqual(searchEntities(graph, 'COBRÁNZA', {}).map(({ id }) => id), ['saas.customers']);
    assert.deepEqual(searchEntities(graph, 'CLIENTES ESPECIALES', {}).map(({ id }) => id), ['saas.customers']);
    assert.deepEqual(searchEntities(graph, 'FIADO.PHP', {}).map(({ id }) => id), ['saas.customers']);
});

test('searches a module through its application, related endpoint and related event', () => {
    const graph = createArchitectureGraph(manifest);

    for (const query of [
        'Carnicería Hub',
        'app.hub',
        'event.saas.sale-updated',
        'endpoint.scale.sales.create',
    ]) assert.deepEqual(searchEntities(graph, query, {}).map(({ id }) => id), ['hub.sync']);
});

test('filters modules by application, status and adjacent connection kind', () => {
    const graph = createArchitectureGraph(manifest);

    assert.deepEqual(
        searchEntities(graph, '', { applicationId: 'app.saas', statusId: 'implemented', connectionKind: 'database' }).map(({ id }) => id),
        ['saas.customers'],
    );
    assert.deepEqual(searchEntities(graph, '', { applicationId: 'app.saas', connectionKind: 'polling' }), []);
    assert.deepEqual(searchEntities(graph, '', { statusId: 'pending' }), []);
});

test('keeps adjacency isolated from the manifest and filters visible connection modes', () => {
    const before = JSON.stringify(manifest);
    const graph = createArchitectureGraph(manifest);

    assert.equal(JSON.stringify(manifest), before);
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'dependencies').map(({ id }) => id), [
        'conn.dependency',
        'conn.components',
        'conn.internal-components',
    ]);
    assert.deepEqual(getVisibleConnections(graph, 'saas.customers', 'data').map(({ id }) => id), ['conn.data']);
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'sync').map(({ id }) => id), ['conn.sync']);
});

test('finds component connections when an owning application is selected without duplicates', () => {
    const graph = createArchitectureGraph(manifest);

    assert.deepEqual(getVisibleConnections(graph, 'app.hub', 'dependencies').map(({ id }) => id), ['conn.components']);
    assert.equal(
        getVisibleConnections(graph, 'app.saas', 'dependencies').filter(({ id }) => id === 'conn.internal-components').length,
        1,
    );
});

test('does not expose connections for an unknown selection', () => {
    const graph = createArchitectureGraph(manifest);

    assert.deepEqual(getVisibleConnections(graph, 'app.missing', 'dependencies'), []);
    assert.deepEqual(getVisibleConnections(graph, 'app.missing', 'sync'), []);
});

test('shows every connection in a selected mode when nothing is selected', () => {
    const graph = createArchitectureGraph(manifest);

    assert.deepEqual(getVisibleConnections(graph, null, 'sync').map(({ id }) => id), ['conn.sync']);
});

test('filters every ecosystem mode and route by the selected connection kind', async () => {
    const checkedInManifest = JSON.parse(await readFile(checkedInManifestPath, 'utf8'));
    const graph = createArchitectureGraph(checkedInManifest);
    const kinds = [...new Set(checkedInManifest.connections.map(({ kind }) => kind))];

    for (const mode of ['dependencies', 'data', 'sync']) {
        const modeConnections = getVisibleConnections(graph, null, mode);

        assert.equal(filterConnectionsByKind(modeConnections, ''), modeConnections);

        for (const kind of kinds) {
            const filtered = filterConnectionsByKind(modeConnections, kind);
            const filteredIds = filtered.map(({ id }) => id).sort();
            const expectedIds = modeConnections
                .filter((connection) => connection.kind === kind)
                .map(({ id }) => id)
                .sort();
            const routedIds = checkedInManifest.visualLayout.connectionRoutes
                .filter((route) => filteredIds.includes(route.connectionId))
                .map(({ connectionId }) => connectionId)
                .sort();

            assert.deepEqual(filteredIds, expectedIds, `${mode}/${kind} filtra conexiones`);
            assert.deepEqual(routedIds, expectedIds, `${mode}/${kind} filtra rutas`);
            assert.ok(filtered.every((connection) => connection.kind === kind));
        }
    }
});

test('builds a source URL pinned to the audit commit and encodes each path segment', () => {
    assert.equal(
        buildSourceUrl(manifest.repositories[0], manifest.sourceFiles[0]),
        'https://github.com/acme/saas/blob/abc123/app/Domain/Clientes%20y%20fiado.php',
    );
    assert.equal(
        buildSourceUrl(
            { webUrl: 'https://github.com/acme/saas', commit: 'abc123' },
            { path: 'app/Éxito #? archivo.php' },
        ),
        'https://github.com/acme/saas/blob/abc123/app/%C3%89xito%20%23%3F%20archivo.php',
    );
});

test('rejects unsafe repository URLs and source locations', () => {
    const sourceFile = { path: 'app/Customer.php' };

    for (const repository of [
        { webUrl: 'http://github.com/acme/saas', commit: 'abc123' },
        { webUrl: 'https://gitlab.com/acme/saas', commit: 'abc123' },
        { webUrl: 'https://token@github.com/acme/saas', commit: 'abc123' },
        { webUrl: 'https://github.com/acme/saas?ref=main', commit: 'abc123' },
        { webUrl: 'https://github.com/acme/saas#readme', commit: 'abc123' },
        { webUrl: 'https://github.com/acme/saas', commit: '' },
    ]) assert.throws(() => buildSourceUrl(repository, sourceFile));

    for (const path of ['', '/app/Customer.php', 'app//Customer.php', './app/Customer.php', 'app/../Customer.php']) {
        assert.throws(() => buildSourceUrl({ webUrl: 'https://github.com/acme/saas', commit: 'abc123' }, { path }));
    }
});
