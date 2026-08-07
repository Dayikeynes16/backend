const REQUIRED_COLLECTIONS = Object.freeze([
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
]);

const EMPTY_COLLECTION = Object.freeze([]);
const EMPTY_VISUAL_LAYOUT = Object.freeze({
    viewBox: '0 0 1200 720',
    buildings: EMPTY_COLLECTION,
    rooms: EMPTY_COLLECTION,
    connectionRoutes: EMPTY_COLLECTION,
});

export const EMPTY_ARCHITECTURE_MANIFEST = Object.freeze({
    metadata: Object.freeze({ verifiedAt: '' }),
    statuses: EMPTY_COLLECTION,
    repositories: EMPTY_COLLECTION,
    applications: EMPTY_COLLECTION,
    modules: EMPTY_COLLECTION,
    components: EMPTY_COLLECTION,
    connections: EMPTY_COLLECTION,
    dataSources: EMPTY_COLLECTION,
    endpoints: EMPTY_COLLECTION,
    events: EMPTY_COLLECTION,
    databaseTables: EMPTY_COLLECTION,
    devices: EMPTY_COLLECTION,
    dependencies: EMPTY_COLLECTION,
    permissions: EMPTY_COLLECTION,
    featureFlags: EMPTY_COLLECTION,
    sourceFiles: EMPTY_COLLECTION,
    risks: EMPTY_COLLECTION,
    evidence: EMPTY_COLLECTION,
    visualLayout: EMPTY_VISUAL_LAYOUT,
});

export function hasMinimumArchitectureManifest(manifest) {
    if (!manifest || typeof manifest !== 'object') return false;
    if (!manifest.metadata || typeof manifest.metadata.verifiedAt !== 'string') return false;
    if (!REQUIRED_COLLECTIONS.every((key) => Array.isArray(manifest[key]))) return false;
    if (manifest.applications.length === 0) return false;

    const layout = manifest.visualLayout;

    return Boolean(
        layout
        && typeof layout === 'object'
        && typeof layout.viewBox === 'string'
        && layout.viewBox.trim()
        && Array.isArray(layout.buildings)
        && Array.isArray(layout.rooms)
        && Array.isArray(layout.connectionRoutes)
    );
}

export function architectureManifestOrFallback(manifest) {
    return hasMinimumArchitectureManifest(manifest)
        ? manifest
        : EMPTY_ARCHITECTURE_MANIFEST;
}

export function isEditableEscapeTarget(target) {
    if (!target || typeof target !== 'object') return false;

    const tagName = String(target.tagName ?? '').toLowerCase();
    if (['input', 'textarea', 'select'].includes(tagName)) return true;
    if (target.isContentEditable === true) return true;

    return typeof target.closest === 'function' && Boolean(target.closest('[contenteditable]'));
}
