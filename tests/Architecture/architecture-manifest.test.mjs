import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { validateManifest } from '../../scripts/validate-architecture-manifest.mjs';

const manifestPath = new URL('../../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);

async function loadManifest() {
    return JSON.parse(await readFile(manifestPath, 'utf8'));
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
