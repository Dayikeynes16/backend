import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { validateManifest } from '../../scripts/validate-architecture-manifest.mjs';

const manifestPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);
const schemaPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.schema.json', import.meta.url);

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
    assert.equal(schema.$defs.building.additionalProperties, false);
    assert.equal(schema.$defs.room.additionalProperties, false);

    for (const building of manifest.visualLayout.buildings) {
        assert.deepEqual(Object.keys(building).sort(), Object.keys(schema.$defs.building.properties).sort());
    }
    for (const room of manifest.visualLayout.rooms) {
        assert.deepEqual(Object.keys(room).sort(), Object.keys(schema.$defs.room.properties).sort());
    }
});
