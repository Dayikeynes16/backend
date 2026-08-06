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
    createArchitectureGraph,
    getVisibleConnections,
} from '../../resources/js/Features/Architecture/lib/architectureGraph.js';

const manifestPath = new URL(
    '../../resources/js/Features/Architecture/data/system-architecture.json',
    import.meta.url,
);

async function loadManifest() {
    return JSON.parse(await readFile(manifestPath, 'utf8'));
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

    for (const application of manifest.applications) {
        for (const mode of ['dependencies', 'data', 'sync']) {
            const scene = buildApplicationScene({
                applicationId: application.id,
                modules: manifest.modules,
                roomLayouts: manifest.visualLayout.rooms,
                connections: getVisibleConnections(graph, application.id, mode),
            });
            const routeIds = scene.routes.map(({ connectionId }) => connectionId);

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
