import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const componentUrl = (name) => new URL(
    `../../resources/js/Features/Architecture/components/${name}`,
    import.meta.url,
);
const composableUrl = new URL(
    '../../resources/js/Features/Architecture/composables/useArchitectureExplorer.js',
    import.meta.url,
);

test('explorer initializes from a safe manifest and exposes the exact visible fallback', async () => {
    const explorer = await readFile(componentUrl('ArchitectureExplorer.vue'), 'utf8');

    assert.match(explorer, /architectureManifestOrFallback\(props\.manifest\)/);
    assert.match(explorer, /useArchitectureExplorer\(runtimeManifest\)/);
    assert.match(explorer, /role="alert"/);
    assert.match(
        explorer,
        /No se pudo cargar el catálogo de arquitectura\. Ejecuta npm run validate:architecture y revisa el manifiesto\./,
    );
});

test('level changes move focus to the visible current-level heading only', async () => {
    const explorer = await readFile(componentUrl('ArchitectureExplorer.vue'), 'utf8');

    assert.match(explorer, /ref="sceneHeading"/);
    assert.match(explorer, /tabindex="-1"/);
    assert.match(explorer, />Nivel actual</);
    assert.match(explorer, /nextTick\(\(\) => sceneHeading\.value\?\.focus/);
    assert.match(explorer, /handleSelectApplication/);
    assert.match(explorer, /handleSelectModule/);
    assert.match(explorer, /handleGoToEcosystem/);
    assert.match(explorer, /handleGoToApplication/);
    assert.doesNotMatch(explorer, /watch\(\s*\[?query/);
});

test('Escape and the history watcher are scoped to the mounted explorer', async () => {
    const [explorer, composable] = await Promise.all([
        readFile(componentUrl('ArchitectureExplorer.vue'), 'utf8'),
        readFile(composableUrl, 'utf8'),
    ]);

    assert.match(explorer, /window\.addEventListener\('keydown', onEscape\)/);
    assert.match(explorer, /window\.removeEventListener\('keydown', onEscape\)/);
    assert.match(explorer, /isEditableEscapeTarget\(event\.target\)/);
    assert.match(explorer, /event\.key !== 'Escape'/);
    assert.match(explorer, /selectedModule\.value/);
    assert.doesNotMatch(explorer, /onEscape[\s\S]{0,400}preventDefault/);
    assert.match(composable, /stopHistoryWatcher/);
    assert.match(composable, /onBeforeUnmount/);
    assert.match(composable, /stopHistoryWatcher\?\.\(\)/);
});

test('tablet recommendation, safe panel breakpoint and contained scene overflow are explicit', async () => {
    const [explorer, ecosystem, application, detail] = await Promise.all([
        readFile(componentUrl('ArchitectureExplorer.vue'), 'utf8'),
        readFile(componentUrl('EcosystemScene.vue'), 'utf8'),
        readFile(componentUrl('ApplicationScene.vue'), 'utf8'),
        readFile(componentUrl('ArchitectureDetailPanel.vue'), 'utf8'),
    ]);

    assert.match(explorer, /architecture-explorer__tablet-recommendation/);
    assert.match(explorer, /@media \(max-width: 48rem\)/);
    assert.match(explorer, /Cambiar a vista Lista/);
    assert.match(explorer, /@media \(min-width: 112rem\)/);
    assert.match(ecosystem, /ecosystem-scene__viewport[\s\S]*overflow-x: auto/);
    assert.match(application, /application-scene__viewport[\s\S]*overflow-x: auto/);
    assert.match(detail, /@media \(min-width: 112rem\)[\s\S]*max-height: calc\(100vh - 8rem\)/);
    assert.doesNotMatch(detail.split('@media (min-width: 112rem)')[0], /max-height|overflow-y: auto/);
});

test('Atlas controls and scenes preserve accessible contrast, targets and reduced motion', async () => {
    const names = [
        'ArchitectureBreadcrumbs.vue',
        'ArchitectureDetailPanel.vue',
        'ArchitectureExplorer.vue',
        'ArchitectureLegend.vue',
        'ArchitectureListView.vue',
        'ArchitectureToolbar.vue',
        'ApplicationBuilding.vue',
        'ApplicationScene.vue',
        'ConnectionLayer.vue',
        'EcosystemScene.vue',
        'ModuleRoom.vue',
    ];
    const sources = Object.fromEntries(await Promise.all(names.map(async (name) => [
        name,
        await readFile(componentUrl(name), 'utf8'),
    ])));
    const combined = Object.values(sources).join('\n');

    assert.doesNotMatch(combined, /\bv-html\b|transition-all/);
    assert.doesNotMatch(combined, /text-(?:slate|gray)-(?:400|500|600)\b/);
    assert.match(sources['ArchitectureToolbar.vue'], /role="group"/);
    assert.match(sources['ArchitectureToolbar.vue'], /:aria-pressed=/);
    assert.match(sources['ArchitectureToolbar.vue'], /<label[\s\S]*type="search"/);
    assert.match(sources['ArchitectureToolbar.vue'], /<label[\s\S]*<select/);
    assert.match(sources['ArchitectureDetailPanel.vue'], /aria-label="Cerrar detalle"[\s\S]*h-11 w-11/);
    assert.match(sources['ApplicationBuilding.vue'], /:focus-visible[\s\S]*stroke: #ffffff/);
    assert.match(sources['ModuleRoom.vue'], /:focus-visible[\s\S]*stroke: #ffffff/);
    assert.match(sources['ConnectionLayer.vue'], /@media \(prefers-reduced-motion: reduce\)[\s\S]*animation: none/);
});
