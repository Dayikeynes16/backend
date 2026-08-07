import assert from 'node:assert/strict';
import test from 'node:test';
import {
    EMPTY_ARCHITECTURE_MANIFEST,
    architectureManifestOrFallback,
    hasMinimumArchitectureManifest,
    isEditableEscapeTarget,
} from '../../resources/js/Features/Architecture/lib/architectureRuntime.js';

const collectionKeys = [
    'statuses',
    'repositories',
    'applications',
    'modules',
    'components',
    'connections',
    'dataSources',
    'endpoints',
    'events',
    'databaseTables',
    'devices',
    'dependencies',
    'permissions',
    'featureFlags',
    'sourceFiles',
    'risks',
    'evidence',
];

function validManifest() {
    return {
        metadata: { verifiedAt: '2026-08-06' },
        ...Object.fromEntries(collectionKeys.map((key) => [key, []])),
        applications: [{ id: 'app.saas', name: 'SaaS' }],
        visualLayout: {
            viewBox: '0 0 1200 720',
            buildings: [],
            rooms: [],
            connectionRoutes: [],
        },
    };
}

test('runtime accepts the valid minimum manifest without cloning it', () => {
    const manifest = validManifest();

    assert.equal(hasMinimumArchitectureManifest(manifest), true);
    assert.equal(architectureManifestOrFallback(manifest), manifest);
});

test('runtime rejects null, absent collections and a catalog without applications', () => {
    assert.equal(hasMinimumArchitectureManifest(null), false);
    assert.equal(hasMinimumArchitectureManifest(undefined), false);

    for (const key of collectionKeys) {
        const manifest = validManifest();
        delete manifest[key];
        assert.equal(hasMinimumArchitectureManifest(manifest), false, key);
    }

    const emptyApplications = validManifest();
    emptyApplications.applications = [];
    assert.equal(hasMinimumArchitectureManifest(emptyApplications), false);

    const missingMetadata = validManifest();
    delete missingMetadata.metadata;
    assert.equal(hasMinimumArchitectureManifest(missingMetadata), false);
});

test('runtime rejects incomplete visual layouts and returns a graph-safe fallback', () => {
    for (const key of ['viewBox', 'buildings', 'rooms', 'connectionRoutes']) {
        const manifest = validManifest();
        delete manifest.visualLayout[key];
        assert.equal(hasMinimumArchitectureManifest(manifest), false, key);
    }

    const fallback = architectureManifestOrFallback({ applications: [] });

    assert.equal(fallback, EMPTY_ARCHITECTURE_MANIFEST);
    assert.deepEqual(fallback.applications, []);
    assert.deepEqual(fallback.modules, []);
    assert.deepEqual(fallback.connections, []);
    assert.deepEqual(fallback.visualLayout.buildings, []);
    assert.deepEqual(fallback.visualLayout.rooms, []);
    assert.deepEqual(fallback.visualLayout.connectionRoutes, []);
});

test('Escape ignores editable controls and contenteditable descendants', () => {
    for (const tagName of ['INPUT', 'textarea', 'Select']) {
        assert.equal(isEditableEscapeTarget({ tagName }), true, tagName);
    }

    assert.equal(isEditableEscapeTarget({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isEditableEscapeTarget({
        tagName: 'SPAN',
        closest: (selector) => (selector === '[contenteditable]' ? {} : null),
    }), true);
    assert.equal(isEditableEscapeTarget({ tagName: 'BUTTON' }), false);
    assert.equal(isEditableEscapeTarget(null), false);
});
