import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildSourceUrl,
    createArchitectureGraph,
    getVisibleConnections,
    normalizeSearchText,
    searchEntities,
} from '../../resources/js/Features/Architecture/lib/architectureGraph.js';

const manifest = {
    statuses: [{ id: 'implemented', label: 'Implementado' }],
    repositories: [{ id: 'repo.saas', webUrl: 'https://github.com/acme/saas/', commit: 'abc123' }],
    applications: [
        { id: 'app.saas', name: 'SaaS', description: 'Núcleo central' },
        { id: 'app.hub', name: 'Hub', description: 'Sucursal local' },
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
            endpointIds: [],
            sourceFileIds: [],
        },
    ],
    endpoints: [{ id: 'endpoint.customers', applicationId: 'app.saas', method: 'GET', path: '/clientes especiales' }],
    events: [],
    components: [],
    dataSources: [],
    databaseTables: [],
    devices: [],
    dependencies: [],
    permissions: [],
    featureFlags: [],
    risks: [],
    evidence: [],
    sourceFiles: [{ id: 'file.customers', repositoryId: 'repo.saas', path: 'app/Domain/Clientes y fiado.php' }],
    connections: [
        { id: 'conn.dependency', fromId: 'app.saas', toId: 'saas.customers', kind: 'http', viewModes: ['dependencies'] },
        { id: 'conn.sync', fromId: 'hub.sync', toId: 'app.saas', kind: 'polling', viewModes: ['sync'] },
        { id: 'conn.data', fromId: 'saas.customers', toId: 'app.hub', kind: 'database', viewModes: ['data'] },
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
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'dependencies').map(({ id }) => id), ['conn.dependency']);
    assert.deepEqual(getVisibleConnections(graph, 'saas.customers', 'data').map(({ id }) => id), ['conn.data']);
    assert.deepEqual(getVisibleConnections(graph, 'app.saas', 'sync').map(({ id }) => id), ['conn.sync']);
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

test('builds a source URL pinned to the audit commit and encodes each path segment', () => {
    assert.equal(
        buildSourceUrl(manifest.repositories[0], manifest.sourceFiles[0]),
        'https://github.com/acme/saas/blob/abc123/app/Domain/Clientes%20y%20fiado.php',
    );
});
