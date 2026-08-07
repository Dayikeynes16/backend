import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    APPLICATION_VIEW_BOX,
    buildApplicationScene,
    buildConnectionPath,
    getRoomGeometry,
} from '../../resources/js/Features/Architecture/lib/architectureGeometry.js';
import {
    CONNECTION_VISUAL_TOKENS,
    ROOM_TEXT_COLORS,
    STATUS_VISUAL_TOKENS,
} from '../../resources/js/Features/Architecture/lib/architectureVisualTokens.js';
import {
    APPLICATION_SCENE_MIN_WIDTH_PX,
    ECOSYSTEM_COLORS,
    ECOSYSTEM_SCENE_MIN_WIDTH_PX,
    ECOSYSTEM_TYPOGRAPHY_UNITS,
    getRoomLabelVerticalGeometry,
    ROOM_LABEL_LAYOUT,
    ROOM_TYPOGRAPHY_UNITS,
    wrapRoomNameLabel,
    wrapRoomStatusLabel,
} from '../../resources/js/Features/Architecture/lib/architectureSceneTokens.js';
import {
    createArchitectureGraph,
    getVisibleConnections,
} from '../../resources/js/Features/Architecture/lib/architectureGraph.js';

const manifestPath = new URL(
    '../../resources/js/Features/Architecture/data/system-architecture.json',
    import.meta.url,
);
const applicationScenePath = new URL(
    '../../resources/js/Features/Architecture/components/ApplicationScene.vue',
    import.meta.url,
);
const ecosystemScenePath = new URL(
    '../../resources/js/Features/Architecture/components/EcosystemScene.vue',
    import.meta.url,
);
const applicationBuildingPath = new URL(
    '../../resources/js/Features/Architecture/components/ApplicationBuilding.vue',
    import.meta.url,
);
const architectureExplorerPath = new URL(
    '../../resources/js/Features/Architecture/components/ArchitectureExplorer.vue',
    import.meta.url,
);
const architectureDetailPanelPath = new URL(
    '../../resources/js/Features/Architecture/components/ArchitectureDetailPanel.vue',
    import.meta.url,
);
const moduleRoomPath = new URL(
    '../../resources/js/Features/Architecture/components/ModuleRoom.vue',
    import.meta.url,
);
const connectionLayerPath = new URL(
    '../../resources/js/Features/Architecture/components/ConnectionLayer.vue',
    import.meta.url,
);
const architectureLegendPath = new URL(
    '../../resources/js/Features/Architecture/components/ArchitectureLegend.vue',
    import.meta.url,
);

async function loadManifest() {
    return JSON.parse(await readFile(manifestPath, 'utf8'));
}

function relativeLuminance(hexColor) {
    const channels = hexColor.match(/[a-f\d]{2}/gi).map((channel) => {
        const normalized = Number.parseInt(channel, 16) / 255;

        return normalized <= 0.04045
            ? normalized / 12.92
            : ((normalized + 0.055) / 1.055) ** 2.4;
    });

    return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
}

function contrastRatio(first, second) {
    const lighter = Math.max(relativeLuminance(first), relativeLuminance(second));
    const darker = Math.min(relativeLuminance(first), relativeLuminance(second));

    return (lighter + 0.05) / (darker + 0.05);
}

function boxesIntersect(left, right) {
    return (
        left.x < right.x + right.width
        && left.x + left.width > right.x
        && left.y < right.y + right.height
        && left.y + left.height > right.y
    );
}

function boxIsInside(inner, outer) {
    return (
        inner.x >= outer.x
        && inner.y >= outer.y
        && inner.x + inner.width <= outer.x + outer.width
        && inner.y + inner.height <= outer.y + outer.height
    );
}

test('room geometry respects floor, row, column and span inside the application viewBox', () => {
    const first = getRoomGeometry({ floor: 1, column: 1, row: 1, columnSpan: 1 });
    const spanning = getRoomGeometry({ floor: 1, column: 2, row: 2, columnSpan: 2 });
    const nextFloorBand = getRoomGeometry({ floor: 3, column: 1, row: 1, columnSpan: 1 });

    assert.deepEqual(
        { x: first.x, y: first.y, width: first.width },
        { x: 140, y: 110, width: 138 },
    );
    assert.equal(spanning.x, 290);
    assert.equal(spanning.y, 202);
    assert.equal(spanning.width, 288);
    assert.ok(nextFloorBand.y > spanning.y);

    for (const geometry of [first, spanning, nextFloorBand]) {
        assert.ok(geometry.x >= APPLICATION_VIEW_BOX.minX);
        assert.ok(geometry.y >= APPLICATION_VIEW_BOX.minY);
        assert.ok(geometry.x + geometry.width <= APPLICATION_VIEW_BOX.width);
        assert.ok(geometry.y + geometry.height + geometry.depth <= APPLICATION_VIEW_BOX.height);
    }
});

test('every application scene renders exactly its filtered modules and declared rooms', async () => {
    const manifest = await loadManifest();
    const names = Object.fromEntries([
        ...manifest.applications,
        ...manifest.modules,
        ...manifest.components,
        ...manifest.devices,
    ].map((entity) => [entity.id, entity.name ?? entity.id]));
    let renderedRooms = 0;

    for (const application of manifest.applications) {
        const expectedModules = manifest.modules.filter((module) => (
            module.applicationId === application.id
        ));
        const scene = buildApplicationScene({
            applicationId: application.id,
            modules: manifest.modules,
            roomLayouts: manifest.visualLayout.rooms,
            connections: manifest.connections,
            entityNames: names,
        });

        assert.deepEqual(
            scene.rooms.map(({ module }) => module.id).sort(),
            expectedModules.map(({ id }) => id).sort(),
            application.id,
        );
        for (const room of scene.rooms) {
            const plate = scene.floors.find(({ floor }) => floor === room.layout.floor);

            assert.ok(plate, `${room.module.id} has no floor plate`);
            assert.ok(room.geometry.x >= plate.x, `${room.module.id} starts outside its floor`);
            assert.ok(room.geometry.y >= plate.y, `${room.module.id} starts above its floor`);
            assert.ok(
                room.geometry.x + room.geometry.width + room.geometry.depth <= plate.x + plate.width,
                `${room.module.id} exceeds its floor width`,
            );
            assert.ok(
                room.geometry.y + room.geometry.height + room.geometry.depth <= plate.y + plate.height,
                `${room.module.id} exceeds its floor height`,
            );
        }
        renderedRooms += scene.rooms.length;
    }

    assert.equal(renderedRooms, 47);
});

test('dynamic application routes are finite, deterministic and unique', async () => {
    const manifest = await loadManifest();
    const graph = createArchitectureGraph(manifest);
    const entityApplicationIds = Object.fromEntries(
        [...graph.entitiesById].map(([id, entity]) => [
            id,
            graph.applicationsById.has(id) ? id : (entity.applicationId ?? null),
        ]),
    );
    const expectedCounts = {
        'app.saas': { dependencies: 5, data: 7, sync: 2 },
        'app.hub': { dependencies: 4, data: 5, sync: 3 },
        'app.scale-web': { dependencies: 1, data: 1, sync: 0 },
        'app.scale-android': { dependencies: 5, data: 4, sync: 1 },
    };

    for (const application of manifest.applications) {
        for (const mode of ['dependencies', 'data', 'sync']) {
            const scene = buildApplicationScene({
                applicationId: application.id,
                modules: manifest.modules,
                roomLayouts: manifest.visualLayout.rooms,
                connections: getVisibleConnections(graph, application.id, mode),
                entityApplicationIds,
            });
            const routeIds = scene.routes.map(({ connectionId }) => connectionId);

            assert.equal(scene.routes.length, expectedCounts[application.id][mode]);
            assert.equal(new Set(routeIds).size, routeIds.length, `${application.id}/${mode}`);
            for (const route of scene.routes) {
                assert.equal(/NaN|undefined|null/.test(route.path), false, route.path);
                assert.equal(route.path, scene.routes.find(({ id }) => id === route.id).path);
            }
        }
    }

    assert.equal(buildConnectionPath({ x: 10, y: 20 }, { x: 30, y: 40 }), 'M 10 20 C 20 20, 20 40, 30 40');
    assert.equal(buildConnectionPath({ x: Number.NaN, y: 20 }, { x: 30, y: 40 }), null);
});

test('same-application endpoints do not become gateways while cross-app and service endpoints do', async () => {
    const manifest = await loadManifest();
    const graph = createArchitectureGraph(manifest);
    const entityApplicationIds = Object.fromEntries(
        [...graph.entitiesById].map(([id, entity]) => [
            id,
            graph.applicationsById.has(id) ? id : (entity.applicationId ?? null),
        ]),
    );
    const scene = buildApplicationScene({
        applicationId: 'app.saas',
        modules: manifest.modules,
        roomLayouts: manifest.visualLayout.rooms,
        connections: getVisibleConnections(graph, 'app.saas', 'dependencies'),
        entityApplicationIds,
    });
    const gatewayIds = scene.gateways.map(({ id }) => id);
    const routedConnectionIds = scene.routes.map(({ connectionId }) => connectionId);

    assert.equal(gatewayIds.includes('component.saas.public-menu'), false);
    assert.equal(routedConnectionIds.includes('conn.public-menu.saas'), false);
    assert.ok(gatewayIds.includes('hub.shared-online-flows'), 'cross-application module has a gateway');
    assert.ok(gatewayIds.includes('component.external.openai'), 'external service has a gateway');

    const androidScene = buildApplicationScene({
        applicationId: 'app.scale-android',
        modules: manifest.modules,
        roomLayouts: manifest.visualLayout.rooms,
        connections: getVisibleConnections(graph, 'app.scale-android', 'dependencies'),
        entityApplicationIds,
    });
    assert.ok(androidScene.gateways.some(({ id }) => id === 'device.scale.usb'));
});

test('every real gateway label occupies a safe band without intersecting rooms or its node', async () => {
    const manifest = await loadManifest();
    const graph = createArchitectureGraph(manifest);
    const entityNames = Object.fromEntries(
        [...graph.entitiesById].map(([id, entity]) => [id, entity.name ?? id]),
    );
    const entityApplicationIds = Object.fromEntries(
        [...graph.entitiesById].map(([id, entity]) => [
            id,
            graph.applicationsById.has(id) ? id : (entity.applicationId ?? null),
        ]),
    );
    const viewBox = {
        x: APPLICATION_VIEW_BOX.minX,
        y: APPLICATION_VIEW_BOX.minY,
        width: APPLICATION_VIEW_BOX.width,
        height: APPLICATION_VIEW_BOX.height,
    };
    let checkedGateways = 0;
    let checkedTopGateways = 0;
    let checkedBottomGateways = 0;

    assert.equal(APPLICATION_VIEW_BOX.width, 1200);
    assert.equal(APPLICATION_VIEW_BOX.height, 780);

    for (const application of manifest.applications) {
        for (const mode of ['dependencies', 'data', 'sync']) {
            const scene = buildApplicationScene({
                applicationId: application.id,
                modules: manifest.modules,
                roomLayouts: manifest.visualLayout.rooms,
                connections: getVisibleConnections(graph, application.id, mode),
                entityNames,
                entityApplicationIds,
            });
            const minimumRoomTop = Math.min(
                ...scene.rooms.map((room) => room.geometry.y),
            );
            const maximumRoomBottom = Math.max(
                ...scene.rooms.map((room) => (
                    room.geometry.y + room.geometry.height + room.geometry.depth
                )),
            );

            for (const gateway of scene.gateways) {
                checkedGateways += 1;
                assert.ok(gateway.labelLines.length >= 1 && gateway.labelLines.length <= 2);
                assert.equal(gateway.labelBaselines.length, gateway.labelLines.length);
                assert.ok(boxIsInside(gateway.labelBox, viewBox), `${gateway.id} label leaves viewBox`);
                assert.ok(boxIsInside(gateway.nodeBox, viewBox), `${gateway.id} node leaves viewBox`);
                assert.equal(
                    boxesIntersect(gateway.labelBox, gateway.nodeBox),
                    false,
                    `${gateway.id} label intersects its node`,
                );

                for (const room of scene.rooms) {
                    const roomBox = {
                        x: room.geometry.x,
                        y: room.geometry.y,
                        width: room.geometry.width + room.geometry.depth,
                        height: room.geometry.height + room.geometry.depth,
                    };

                    assert.equal(
                        boxesIntersect(gateway.labelBox, roomBox),
                        false,
                        `${gateway.id} label intersects ${room.module.id}`,
                    );
                }

                if (gateway.side === 'top') {
                    checkedTopGateways += 1;
                    assert.ok(
                        gateway.labelBox.y + gateway.labelBox.height <= minimumRoomTop,
                        `${gateway.id} top label leaves its safe band`,
                    );
                } else {
                    checkedBottomGateways += 1;
                    assert.equal(gateway.y, 746);
                    assert.equal(gateway.labelBaselines[0], 708);
                    assert.ok(
                        gateway.labelBox.y >= maximumRoomBottom,
                        `${gateway.id} bottom label leaves its safe band`,
                    );
                    if (gateway.labelBaselines.length === 2) {
                        assert.equal(gateway.labelBaselines[1], 724);
                    }
                }
            }
        }
    }

    assert.ok(checkedGateways > 0);
    assert.ok(checkedTopGateways > 0);
    assert.ok(checkedBottomGateways > 0);
});

test('rooms, legend and conductors share exact visual tokens', async () => {
    assert.deepEqual(
        Object.fromEntries(Object.entries(STATUS_VISUAL_TOKENS).map(([id, token]) => [id, token.pattern])),
        {
            implemented: 'solid',
            partial: 'diagonal',
            pending: 'horizontal',
            'in-review': 'vertical',
            issues: 'cross',
            'requires-review': 'dots',
            unknown: 'dense',
            'not-responsible': 'wide',
        },
    );
    assert.equal(CONNECTION_VISUAL_TOKENS['usb-serial'].dash, '14 3 2 3');
    assert.equal(CONNECTION_VISUAL_TOKENS.websocket.color, '#38bdf8');

    const [moduleRoom, connectionLayer, legend] = await Promise.all([
        readFile(moduleRoomPath, 'utf8'),
        readFile(connectionLayerPath, 'utf8'),
        readFile(architectureLegendPath, 'utf8'),
    ]);
    assert.match(moduleRoom, /architectureVisualTokens\.js/);
    assert.match(moduleRoom, /ArchitectureStatusPattern/);
    assert.match(connectionLayer, /architectureVisualTokens\.js/);
    assert.match(legend, /architectureVisualTokens\.js/);
    assert.match(legend, /ArchitectureStatusPattern/);
});

test('room typography stays at or above 12 effective pixels at the minimum scene width', () => {
    const minimumScale = APPLICATION_SCENE_MIN_WIDTH_PX / APPLICATION_VIEW_BOX.width;
    const effectiveSizes = Object.fromEntries(
        Object.entries(ROOM_TYPOGRAPHY_UNITS).map(([label, units]) => [
            label,
            Number((units * minimumScale).toFixed(2)),
        ]),
    );

    assert.equal(APPLICATION_SCENE_MIN_WIDTH_PX, 960);
    assert.deepEqual(effectiveSizes, {
        name: 13.6,
        status: 12,
        offline: 12,
    });
    for (const [label, effectivePixels] of Object.entries(effectiveSizes)) {
        assert.ok(effectivePixels >= 12, `${label} renders at only ${effectivePixels}px`);
    }

    const room = getRoomGeometry({ floor: 1, column: 1, row: 1, columnSpan: 1 });
    const badge = ROOM_LABEL_LAYOUT.offlineBadge;
    const nameRight = room.x + room.width
        - ROOM_LABEL_LAYOUT.horizontalInset
        - ROOM_LABEL_LAYOUT.offlineNameReserve;
    const badgeLeft = room.x + room.width - badge.width - badge.rightInset;
    const badgeBottom = room.y + badge.topInset + badge.height;
    const statusTop = room.y + room.height
        - ROOM_LABEL_LAYOUT.statusBottomInset
        - ROOM_TYPOGRAPHY_UNITS.status;

    assert.ok(nameRight < badgeLeft, 'offline badge must not overlap the wrapped name');
    assert.ok(badgeBottom <= statusTop, 'offline badge must not overlap the status line');
    assert.ok(badge.textLength <= badge.height - (badge.textInset * 2));
});

test('campus typography stays at or above 12 effective pixels and axes meet WCAG AA', () => {
    const ecosystemViewBoxWidth = 1200;
    const minimumScale = ECOSYSTEM_SCENE_MIN_WIDTH_PX / ecosystemViewBoxWidth;
    const effectiveSizes = Object.fromEntries(
        Object.entries(ECOSYSTEM_TYPOGRAPHY_UNITS).map(([label, units]) => [
            label,
            Number((units * minimumScale).toFixed(2)),
        ]),
    );

    assert.equal(ECOSYSTEM_SCENE_MIN_WIDTH_PX, 1056);
    assert.deepEqual(effectiveSizes, {
        applicationName: 12.32,
        applicationId: 12.32,
        axis: 12.32,
        empty: 14.08,
    });
    for (const [label, effectivePixels] of Object.entries(effectiveSizes)) {
        assert.ok(effectivePixels >= 12, `${label} renders at only ${effectivePixels}px`);
    }
    assert.ok(
        contrastRatio(ECOSYSTEM_COLORS.axisText, ECOSYSTEM_COLORS.terrain) >= 4.5,
        'axis labels must keep a 4.5:1 contrast ratio over the campus terrain',
    );
});

test('room text meets WCAG AA on every status and current status labels remain complete', async () => {
    const manifest = await loadManifest();
    const room = getRoomGeometry({ floor: 1, column: 1, row: 1, columnSpan: 1 });
    const contentWidth = room.width - (ROOM_LABEL_LAYOUT.horizontalInset * 2);
    const maximumCharacters = Math.floor(
        contentWidth
        / (ROOM_TYPOGRAPHY_UNITS.status * ROOM_LABEL_LAYOUT.estimatedGlyphWidthRatio),
    );

    for (const status of manifest.statuses) {
        const token = STATUS_VISUAL_TOKENS[status.id];
        const lines = wrapRoomStatusLabel(
            status.label,
            contentWidth,
            ROOM_TYPOGRAPHY_UNITS.status,
        );

        assert.ok(contrastRatio(ROOM_TEXT_COLORS.name, token.fill) >= 4.5, `${status.id} name`);
        assert.ok(contrastRatio(ROOM_TEXT_COLORS.status, token.fill) >= 4.5, `${status.id} status`);
        assert.ok(lines.length >= 1 && lines.length <= ROOM_LABEL_LAYOUT.statusMaxLines, status.id);
        assert.equal(lines.join(' '), status.label, `${status.id} must not be truncated`);
        assert.ok(lines.every((line) => !line.includes('…')), `${status.id} contains an ellipsis`);
        assert.ok(
            lines.every((line) => line.length <= maximumCharacters),
            `${status.id} exceeds the visible status width`,
        );
    }
});

test('real two-line room names and statuses occupy disjoint vertical regions', async () => {
    const manifest = await loadManifest();
    const statusesById = new Map(manifest.statuses.map((status) => [status.id, status]));
    const collidingModuleIds = ['hub.users', 'scale-web.hub-compatibility'];

    for (const moduleId of collidingModuleIds) {
        const module = manifest.modules.find(({ id }) => id === moduleId);
        const layout = manifest.visualLayout.rooms.find(({ moduleId: id }) => id === moduleId);
        const room = getRoomGeometry(layout);
        const nameContentWidth = room.width
            - (ROOM_LABEL_LAYOUT.horizontalInset * 2)
            - (module.offlineCapability?.supported ? ROOM_LABEL_LAYOUT.offlineNameReserve : 0);
        const statusContentWidth = room.width - (ROOM_LABEL_LAYOUT.horizontalInset * 2);
        const nameLines = wrapRoomNameLabel(
            module.name,
            nameContentWidth,
            ROOM_TYPOGRAPHY_UNITS.name,
        );
        const statusLines = wrapRoomStatusLabel(
            statusesById.get(module.status.id).label,
            statusContentWidth,
            ROOM_TYPOGRAPHY_UNITS.status,
        );
        const labels = getRoomLabelVerticalGeometry({
            roomHeight: room.height,
            nameLineCount: nameLines.length,
            statusLineCount: statusLines.length,
        });

        assert.equal(nameLines.length, 2, `${moduleId} must exercise a two-line name`);
        assert.equal(statusLines.length, 2, `${moduleId} must exercise a two-line status`);
        assert.ok(
            labels.nameBox.bottom < labels.statusBox.top,
            `${moduleId} name ends at ${labels.nameBox.bottom}, status starts at ${labels.statusBox.top}`,
        );
        assert.ok(
            ROOM_LABEL_LAYOUT.nameClipTop + ROOM_LABEL_LAYOUT.nameClipHeight
                < ROOM_LABEL_LAYOUT.statusClipTop,
            `${moduleId} clip regions overlap`,
        );
        assert.ok(labels.statusBox.bottom <= room.height, `${moduleId} status leaves the room`);
    }
});

test('application scene uses tested routes, readable tokens and the 1792px side-panel breakpoint', async () => {
    const [applicationScene, explorer, detailPanel, moduleRoom] = await Promise.all([
        readFile(applicationScenePath, 'utf8'),
        readFile(architectureExplorerPath, 'utf8'),
        readFile(architectureDetailPanelPath, 'utf8'),
        readFile(moduleRoomPath, 'utf8'),
    ]);

    assert.match(applicationScene, /:routes="scene\.routes"/);
    assert.match(applicationScene, /:style="sceneStyle"/);
    assert.match(applicationScene, /:width="sceneBounds\.width"/);
    assert.match(applicationScene, /:height="sceneBounds\.height"/);
    assert.match(applicationScene, /:width="terrainBounds\.width"/);
    assert.match(applicationScene, /:height="terrainBounds\.height"/);
    assert.match(applicationScene, /:y="gateway\.labelBaselines\[index\]"/);
    assert.doesNotMatch(applicationScene, /\b(?:680|720)\b|5 \/ 3/);
    assert.match(moduleRoom, /:font-size="ROOM_TYPOGRAPHY_UNITS\.name"/);
    assert.match(moduleRoom, /:font-size="ROOM_TYPOGRAPHY_UNITS\.status"/);
    assert.match(moduleRoom, /:font-size="ROOM_TYPOGRAPHY_UNITS\.offline"/);
    assert.match(moduleRoom, /module-room__content-clip/);
    assert.match(moduleRoom, /rotate\(90 12 22\)/);
    assert.match(explorer, /architecture-explorer__layout--with-detail/);
    assert.match(explorer, /@media \(min-width: 112rem\)/);
    assert.match(explorer, /grid-template-columns: minmax\(0, 1fr\) 23\.75rem/);
    assert.doesNotMatch(explorer, /2xl:grid-cols-\[minmax\(0,1fr\)_23\.75rem\]/);
    assert.match(detailPanel, /architecture-detail-panel__body/);
    assert.match(detailPanel, /@media \(min-width: 112rem\)/);
    assert.match(detailPanel, /position: sticky/);
    assert.match(detailPanel, /max-height: calc\(100vh - 8rem\)/);
    assert.doesNotMatch(detailPanel, /2xl:max-h-/);
});

test('ecosystem scene applies the tested minimum width and typography tokens', async () => {
    const [ecosystemScene, applicationBuilding] = await Promise.all([
        readFile(ecosystemScenePath, 'utf8'),
        readFile(applicationBuildingPath, 'utf8'),
    ]);

    assert.match(ecosystemScene, /minWidth: `\$\{ECOSYSTEM_SCENE_MIN_WIDTH_PX\}px`/);
    assert.match(ecosystemScene, /:font-size="ECOSYSTEM_TYPOGRAPHY_UNITS\.axis"/);
    assert.match(ecosystemScene, /:fill="ECOSYSTEM_COLORS\.axisText"/);
    assert.match(applicationBuilding, /:font-size="ECOSYSTEM_TYPOGRAPHY_UNITS\.applicationName"/);
    assert.match(applicationBuilding, /:font-size="ECOSYSTEM_TYPOGRAPHY_UNITS\.applicationId"/);
});

test('ecosystem declarative routes retain the audited mode coverage', async () => {
    const manifest = await loadManifest();
    const graph = createArchitectureGraph(manifest);
    const routeConnectionIds = new Set(
        manifest.visualLayout.connectionRoutes.map(({ connectionId }) => connectionId),
    );

    for (const [mode, expectedCount] of Object.entries({ dependencies: 18, data: 19, sync: 4 })) {
        const visible = getVisibleConnections(graph, null, mode);

        assert.equal(visible.length, expectedCount);
        assert.ok(visible.every(({ id }) => routeConnectionIds.has(id)));
    }
});
