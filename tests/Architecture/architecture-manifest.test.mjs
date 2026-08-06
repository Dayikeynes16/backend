import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { validateManifest } from '../../scripts/validate-architecture-manifest.mjs';

const manifestPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);
const schemaPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.schema.json', import.meta.url);

const auditedModuleIds = [
    'saas.core.tenancy-auth',
    'saas.core.organizations',
    'saas.catalog.products',
    'saas.inventory.stock',
    'saas.sales.workbench',
    'saas.sales.payments',
    'saas.cash.shifts',
    'saas.customers.credit',
    'saas.expenses',
    'saas.purchases-providers',
    'saas.metrics',
    'saas.agenda',
    'saas.ai-assistant',
    'saas.web-orders',
    'saas.realtime',
    'saas.api.scales',
    'saas.api.hub',
    'saas.api.public',
    'hub.shell-ipc',
    'hub.shared-online-flows',
    'hub.local-scale-api',
    'hub.sqlite',
    'hub.sync.scale-sales',
    'hub.catalog-cache',
    'hub.realtime',
    'hub.printing',
    'hub.discovery-mdns',
    'hub.pairing.scale-devices',
    'hub.users',
    'scale-web.setup',
    'scale-web.catalog-sales',
    'scale-web.hardware.adapter',
    'scale-web.hub-compatibility',
    'scale-web.idempotency',
    'scale-web.offline',
    'scale-web.tests',
    'scale-android.hardware.usb-serial',
    'scale-android.weight-capture',
    'scale-android.setup-qr',
    'scale-android.discovery-mdns',
    'scale-android.sales.direct-cloud',
    'scale-android.sales.via-hub',
    'scale-android.catalog-cache',
    'scale-android.updates',
    'scale-android.pairing',
    'scale-android.printing',
    'scale-android.product-scanner',
];

const auditedRiskIds = [
    'risk.audit.direct-scale-idempotency',
    'risk.audit.scale-web-hub-compatibility',
    'risk.audit.hub-offline-scope',
    'risk.audit.hub-offline-session-restoration',
    'risk.audit.hub-sync-configuration',
    'risk.audit.hub-local-growth',
    'risk.audit.lan-security',
    'risk.audit.realtime-coverage',
    'risk.audit.documentation-drift',
    'risk.audit.concurrent-pairing-work',
];

const auditedFeatureFlagIds = [
    'flag.saas.web-orders',
    'flag.branch.cashier-customers',
    'flag.branch.cashier-expenses',
    'flag.branch.cashier-purchases',
    'flag.branch.admin-providers',
    'flag.branch.admin-expense-categories',
    'flag.branch.payment-receipts',
    'flag.branch.payment-receipts-required',
];

function sortedIds(collection) {
    return collection.map(({ id }) => id).sort();
}

async function loadManifest() {
    return JSON.parse(await readFile(manifestPath, 'utf8'));
}

async function loadSchema() {
    return JSON.parse(await readFile(schemaPath, 'utf8'));
}

test('the checked-in architecture manifest is valid', async () => {
    const manifest = await loadManifest();
    assert.deepEqual(validateManifest(manifest), []);
});

test('duplicate ids and broken references are rejected', async () => {
    const manifest = await loadManifest();
    manifest.applications.push({ ...manifest.applications[0] });
    manifest.modules[0].applicationId = 'app.missing';

    const errors = validateManifest(manifest);
    assert.ok(errors.some((error) => error.includes('duplicate id')));
    assert.ok(errors.some((error) => error.includes('app.missing')));
});

test('duplicate status ids are rejected', async () => {
    const manifest = await loadManifest();
    manifest.statuses.push({ ...manifest.statuses[0] });

    assert.ok(validateManifest(manifest).some((error) => error.includes('duplicate id: implemented')));
});

test('a conclusive status requires evidence', async () => {
    const manifest = await loadManifest();
    manifest.modules[0].status.evidenceIds = [];

    assert.ok(validateManifest(manifest).some((error) => error.includes('requires evidence')));
});

test('module responsibility must reference an existing application', async () => {
    const manifest = await loadManifest();
    manifest.modules[0].responsibleApplicationId = 'app.missing';

    assert.ok(validateManifest(manifest).some((error) => error.includes('responsibleApplicationId references missing id app.missing')));
});

test('connections validate their status and evidence references', async () => {
    const manifest = await loadManifest();
    const connection = {
        id: 'conn.test.status-evidence',
        fromId: 'app.saas',
        toId: 'app.hub',
        kind: 'http',
        status: { id: 'implemented', determination: 'verified', confidence: 'high', evidenceIds: [] },
        endpointIds: [],
        eventIds: [],
        evidenceIds: [],
    };
    manifest.connections.push(connection);

    assert.ok(validateManifest(manifest).some((error) => error.includes('conn.test.status-evidence requires evidence')));

    connection.status = { id: 'not-a-status', determination: 'verified', confidence: 'high', evidenceIds: ['ev.missing'] };
    connection.evidenceIds = ['ev.missing-flat'];
    const errors = validateManifest(manifest);

    assert.ok(errors.some((error) => error.includes('conn.test.status-evidence has unknown status not-a-status')));
    assert.ok(errors.some((error) => error.includes('conn.test.status-evidence references missing evidence ev.missing')));
    assert.ok(errors.some((error) => error.includes('conn.test.status-evidence references missing evidence ev.missing-flat')));
});

test('the schema defines closed building and room contracts for the checked-in layout', async () => {
    const [manifest, schema] = await Promise.all([loadManifest(), loadSchema()]);
    const layout = schema.$defs.visualLayout.properties;

    assert.equal(layout.buildings.items.$ref, '#/$defs/building');
    assert.equal(layout.rooms.items.$ref, '#/$defs/room');
    assert.ok(schema.$defs.connection.required.includes('status'));
    assert.equal(schema.$defs.building.additionalProperties, false);
    assert.equal(schema.$defs.room.additionalProperties, false);

    for (const building of manifest.visualLayout.buildings) {
        assert.deepEqual(Object.keys(building).sort(), Object.keys(schema.$defs.building.properties).sort());
    }
    for (const room of manifest.visualLayout.rooms) {
        assert.deepEqual(Object.keys(room).sort(), Object.keys(schema.$defs.room.properties).sort());
    }
});

test('the MVP inventory covers the audited ecosystem', async () => {
    const manifest = await loadManifest();

    assert.equal(manifest.applications.length, 4);
    assert.deepEqual(sortedIds(manifest.modules), [...auditedModuleIds].sort());
    assert.deepEqual(sortedIds(manifest.risks), [...auditedRiskIds].sort());

    const roomModuleIds = manifest.visualLayout.rooms.map(({ moduleId }) => moduleId);
    assert.equal(new Set(roomModuleIds).size, roomModuleIds.length, 'a module has more than one room');
    assert.deepEqual([...roomModuleIds].sort(), [...auditedModuleIds].sort());
});

test('the MVP graph covers audited connections', async () => {
    const manifest = await loadManifest();
    const auditedConnectionIds = [
        'conn.web.saas-inertia',
        'conn.saas.postgresql',
        'conn.saas.redis',
        'conn.saas.reverb-web',
        'conn.hub.renderer-ipc',
        'conn.hub.saas-sanctum',
        'conn.hub.reverb',
        'conn.hub.polling-fallback',
        'conn.hub.sqlite',
        'conn.hub.saas-sync',
        'conn.android.usb-scale',
        'conn.android.hub-lan',
        'conn.android.saas-cloud',
        'conn.android.hub-discovery',
        'conn.scale-web.saas',
        'conn.saas.openai',
        'conn.saas.google-maps',
        'conn.saas.object-storage',
        'conn.android.apk-update',
        'conn.public-menu.saas',
    ];

    assert.deepEqual(manifest.connections.map(({ id }) => id), auditedConnectionIds);
    assert.deepEqual(
        manifest.visualLayout.connectionRoutes.map(({ connectionId }) => connectionId),
        auditedConnectionIds,
    );
    assert.equal(new Set(manifest.visualLayout.connectionRoutes.map(({ id }) => id)).size, 20);
    for (const route of manifest.visualLayout.connectionRoutes) {
        assert.ok(route.path, `${route.id} has no declarative path`);
    }

    for (const connection of manifest.connections) {
        for (const field of [
            'direction', 'auth', 'onlineRequired', 'durable', 'status',
            'endpointIds', 'eventIds', 'evidenceIds', 'viewModes',
        ]) assert.ok(Object.hasOwn(connection, field), `${connection.id} is missing ${field}`);

        assert.ok(Array.isArray(connection.endpointIds));
        assert.ok(Array.isArray(connection.eventIds));
        assert.ok(Array.isArray(connection.evidenceIds));
        assert.ok(connection.evidenceIds.length > 0, `${connection.id} has no evidence`);
        assert.ok(Array.isArray(connection.viewModes));
    }
});

test('the technical graph records audited sources, surfaces, tables and controls', async () => {
    const manifest = await loadManifest();
    const ids = (collection) => new Set(manifest[collection].map(({ id }) => id));

    for (const [collection, expectedIds] of Object.entries({
        components: [
            'component.saas.laravel', 'component.saas.vue-inertia', 'component.saas.postgresql',
            'component.saas.redis', 'component.saas.reverb', 'component.hub.electron-main',
            'component.hub.vue-renderer', 'component.hub.fastify', 'component.hub.sqlite',
            'component.android.usb-driver', 'component.external.openai',
            'component.external.google-maps', 'component.external.object-storage',
            'component.external.apk-distribution',
        ],
        dataSources: [
            'data.saas.postgresql.business', 'data.saas.laravel-rules',
            'data.hub.sqlite.unsynced-sales', 'data.hub.sqlite.catalog-cache',
            'data.hub.sqlite.scale-devices', 'data.hub.electron-store', 'data.android.datastore',
            'data.scale-web.local-storage', 'data.device.physical-weight',
        ],
        devices: [
            'device.scale.usb', 'device.android-terminal', 'device.camera.qr',
            'device.printer.system', 'device.reader.unverified',
        ],
        endpoints: [
            'endpoint.saas.scale.branch-me', 'endpoint.saas.scale.categories',
            'endpoint.saas.scale.products', 'endpoint.saas.scale.sales-create',
            'endpoint.saas.scale.sales-index', 'endpoint.saas.scale.sales-show',
            'endpoint.hub.local.branch-me', 'endpoint.hub.local.categories',
            'endpoint.hub.local.products', 'endpoint.hub.local.sales-create',
            'endpoint.hub.local.sales-status', 'endpoint.saas.hub.surface',
            'endpoint.saas.public.surface', 'endpoint.saas.web.admin',
            'endpoint.saas.web.empresa', 'endpoint.saas.web.sucursal',
            'endpoint.saas.web.caja', 'endpoint.saas.web.assistant',
            'endpoint.saas.web.agenda', 'endpoint.hub.local.pair-request',
            'endpoint.hub.local.pair-status',
        ],
        events: [
            'event.saas.new-external-sale', 'event.saas.sale-updated',
            'event.saas.sale-locked', 'event.saas.sale-unlocked',
            'event.saas.agenda-item-assigned',
        ],
        permissions: [
            'permission.role.superadmin', 'permission.role.admin-empresa',
            'permission.role.admin-sucursal', 'permission.role.cajero',
            'permission.hub.roles',
        ],
        featureFlags: [
            'flag.saas.web-orders', 'flag.branch.cashier-customers',
            'flag.branch.cashier-expenses', 'flag.branch.cashier-purchases',
            'flag.branch.admin-providers', 'flag.branch.admin-expense-categories',
            'flag.branch.payment-receipts', 'flag.branch.payment-receipts-required',
        ],
    })) {
        const actualIds = ids(collection);
        for (const id of expectedIds) assert.ok(actualIds.has(id), `missing ${collection} entity ${id}`);
    }

    const sources = new Map(manifest.dataSources.map((source) => [source.id, source]));
    assert.equal(sources.get('data.saas.postgresql.business').authoritative, true);
    assert.equal(sources.get('data.saas.laravel-rules').authoritative, true);
    assert.equal(sources.get('data.hub.sqlite.catalog-cache').authoritative, false);
    assert.equal(sources.get('data.scale-web.local-storage').authoritative, false);

    const physicalTableNames = new Set(manifest.databaseTables.map(({ name }) => name));
    for (const name of [
        'tenants', 'branches', 'users', 'categories', 'products', 'product_presentations',
        'sales', 'sale_items', 'payments', 'payment_receipts', 'cash_register_shifts',
        'cash_withdrawals', 'customers', 'customer_product_prices', 'customer_payments',
        'expense_categories', 'expense_subcategories', 'expenses', 'expense_attachments',
        'providers', 'purchases', 'purchase_items', 'purchase_attachments',
        'provider_payments', 'purchase_products', 'purchase_product_categories',
        'agenda_items', 'ai_assistant_sessions', 'ai_assistant_messages',
        'assistant_drafts', 'audit_logs', 'personal_access_tokens', 'roles', 'permissions',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'outbox_sales', 'catalog_snapshots', 'scale_devices',
    ]) assert.ok(physicalTableNames.has(name), `missing physical table ${name}`);

    for (const name of physicalTableNames) {
        assert.doesNotMatch(name, /inventor|stock|warehouse|movement/i);
    }
});

test('Task 3 evidence and declarative module references stay intact', async () => {
    const manifest = await loadManifest();

    assert.deepEqual(sortedIds(manifest.modules), [...auditedModuleIds].sort());
    assert.deepEqual(sortedIds(manifest.risks), [...auditedRiskIds].sort());
    assert.deepEqual(sortedIds(manifest.featureFlags), [...auditedFeatureFlagIds].sort());

    for (const module of manifest.modules) {
        for (const field of [
            'sourceOfTruthIds', 'endpointIds', 'eventIds', 'dependencyIds',
            'sourceFileIds', 'riskIds', 'featureFlagIds', 'permissionIds',
            'databaseTableIds', 'componentIds', 'deviceIds',
        ]) assert.ok(Array.isArray(module[field]), `${module.id} must declare ${field}`);
    }
});

test('audited infrastructure, events and receipt controls use direct evidence', async () => {
    const manifest = await loadManifest();
    const byId = (collection) => new Map(manifest[collection].map((entity) => [entity.id, entity]));
    const components = byId('components');
    const connections = byId('connections');
    const dependencies = byId('dependencies');
    const events = byId('events');
    const flags = byId('featureFlags');
    const modules = byId('modules');

    for (const entity of [
        components.get('component.saas.redis'),
        connections.get('conn.saas.redis'),
        dependencies.get('dependency.saas.redis'),
    ]) {
        assert.ok(entity.evidenceIds.includes('ev.saas.database-redis'));
        assert.ok(!entity.evidenceIds.includes('ev.saas.sale-queue'));
    }

    for (const [eventId, sourceFileId, evidenceId] of [
        ['event.saas.new-external-sale', 'file.saas.event-new-external-sale', 'ev.saas.event-new-external-sale'],
        ['event.saas.sale-updated', 'file.saas.event-sale-updated', 'ev.saas.event-sale-updated'],
        ['event.saas.sale-locked', 'file.saas.event-sale-locked', 'ev.saas.event-sale-locked'],
        ['event.saas.sale-unlocked', 'file.saas.event-sale-unlocked', 'ev.saas.event-sale-unlocked'],
    ]) {
        assert.equal(events.get(eventId).sourceFileId, sourceFileId);
        assert.ok(events.get(eventId).evidenceIds.includes(evidenceId));
    }

    for (const flagId of ['flag.branch.payment-receipts', 'flag.branch.payment-receipts-required']) {
        const flag = flags.get(flagId);
        assert.equal(flag.sourceFileId, 'file.saas.branch-model');
        assert.ok(flag.evidenceIds.includes('ev.saas.branch-payment-receipt-controls'));
        assert.ok(flag.evidenceIds.includes('ev.saas.payment-receipt-enforcement'));
        assert.ok(flag.evidenceIds.includes('ev.saas.customer-payment-receipt-enforcement'));
    }

    const affectedModuleIds = [
        'saas.core.organizations',
        'saas.sales.payments',
        'saas.customers.credit',
    ];
    for (const flagId of ['flag.branch.payment-receipts', 'flag.branch.payment-receipts-required']) {
        assert.deepEqual(
            manifest.modules.filter((module) => module.featureFlagIds.includes(flagId)).map(({ id }) => id).sort(),
            [...affectedModuleIds].sort(),
        );
    }
});

test('technical evidence references are valid and pinned to each repository snapshot', async () => {
    const manifest = await loadManifest();
    const evidenceIds = new Set(manifest.evidence.map(({ id }) => id));
    const sources = new Map(manifest.sourceFiles.map((source) => [source.id, source]));
    const repositories = new Map(manifest.repositories.map((repository) => [repository.id, repository]));

    for (const evidence of manifest.evidence) {
        const source = sources.get(evidence.sourceFileId);
        assert.ok(source, `${evidence.id} references missing source ${evidence.sourceFileId}`);
        assert.equal(evidence.commit, repositories.get(source.repositoryId).commit, `${evidence.id} is not pinned to its repository snapshot`);
    }

    for (const collection of [
        'components', 'connections', 'dataSources', 'events', 'devices',
        'dependencies', 'permissions', 'featureFlags',
    ]) {
        for (const entity of manifest[collection]) {
            assert.ok(entity.evidenceIds.length > 0, `${entity.id} has no evidence`);
            for (const evidenceId of entity.evidenceIds) {
                assert.ok(evidenceIds.has(evidenceId), `${entity.id} references missing evidence ${evidenceId}`);
            }
        }
    }
});

test('disabled features and delegated responsibility are not reported as pending', async () => {
    const manifest = await loadManifest();
    const modules = new Map(manifest.modules.map((module) => [module.id, module]));

    assert.equal(modules.get('saas.web-orders').status.id, 'implemented');
    assert.deepEqual(modules.get('saas.web-orders').featureFlagIds, ['flag.saas.web-orders']);
    assert.equal(modules.get('hub.users').status.id, 'not-responsible');
    assert.equal(modules.get('saas.inventory.stock').status.id, 'pending');
});
