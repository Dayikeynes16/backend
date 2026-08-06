export const APPLICATION_VIEW_BOX = Object.freeze({
    minX: 0,
    minY: 0,
    width: 1200,
    height: 720,
});

export const CELL_WIDTH = 150;
export const CELL_HEIGHT = 92;
export const FLOOR_GAP = 22;
export const ORIGIN_X = 140;
export const ORIGIN_Y = 110;

const FLOORS_PER_ROW = 2;
const FLOOR_COLUMN_GAP = 70;
const MAX_COLUMNS = 3;
const MAX_ROWS = 3;
const ROOM_GUTTER = 12;
const ROOM_HEIGHT = 72;
const ROOM_DEPTH = 10;
const GATEWAY_Y = Object.freeze({ top: 48, bottom: 686 });

function finiteNumber(value, fallback = 0) {
    return Number.isFinite(value) ? value : fallback;
}

function floorOrigin(floor) {
    const floorIndex = Math.max(0, Math.trunc(finiteNumber(floor, 1)) - 1);
    const floorColumn = floorIndex % FLOORS_PER_ROW;
    const floorRow = Math.floor(floorIndex / FLOORS_PER_ROW);
    const floorWidth = MAX_COLUMNS * CELL_WIDTH;
    const floorHeight = MAX_ROWS * CELL_HEIGHT;

    return {
        x: ORIGIN_X + floorColumn * (floorWidth + FLOOR_COLUMN_GAP),
        y: ORIGIN_Y + floorRow * (floorHeight + FLOOR_GAP),
    };
}

export function getRoomGeometry(layout) {
    const column = Math.max(1, Math.trunc(finiteNumber(layout?.column, 1)));
    const row = Math.max(1, Math.trunc(finiteNumber(layout?.row, 1)));
    const columnSpan = Math.max(1, Math.trunc(finiteNumber(layout?.columnSpan, 1)));
    const origin = floorOrigin(layout?.floor);
    const x = origin.x + (column - 1) * CELL_WIDTH;
    const y = origin.y + (row - 1) * CELL_HEIGHT;
    const width = columnSpan * CELL_WIDTH - ROOM_GUTTER;

    return {
        x,
        y,
        width,
        height: ROOM_HEIGHT,
        depth: ROOM_DEPTH,
        centerX: x + width / 2,
        centerY: y + ROOM_HEIGHT / 2,
    };
}

function floorPlate(floor, floorRooms) {
    const origin = floorOrigin(floor);
    const maxRow = Math.max(...floorRooms.map(({ layout }) => layout.row), 1);

    return {
        floor,
        x: origin.x - 22,
        y: origin.y - 18,
        width: MAX_COLUMNS * CELL_WIDTH + 28,
        height: maxRow * CELL_HEIGHT + 16,
        labelX: origin.x - 8,
        labelY: origin.y - 26,
    };
}

function gatewayLabel(id, entityNames) {
    if (Object.hasOwn(entityNames, id)) return entityNames[id];

    return id
        .split('.')
        .slice(-2)
        .join(' · ')
        .replaceAll('-', ' ');
}

function gatewayPosition(side, index, count) {
    return {
        x: APPLICATION_VIEW_BOX.width * ((index + 1) / (count + 1)),
        y: GATEWAY_Y[side],
    };
}

export function buildConnectionPath(fromNode, toNode) {
    const values = [fromNode?.x, fromNode?.y, toNode?.x, toNode?.y];
    if (values.some((value) => !Number.isFinite(value))) return null;

    const middleX = (fromNode.x + toNode.x) / 2;

    return `M ${fromNode.x} ${fromNode.y} C ${middleX} ${fromNode.y}, ${middleX} ${toNode.y}, ${toNode.x} ${toNode.y}`;
}

export function buildApplicationScene({
    applicationId,
    modules = [],
    roomLayouts = [],
    connections = [],
    entityNames = {},
}) {
    const modulesById = new Map(
        modules
            .filter((module) => module.applicationId === applicationId)
            .map((module) => [module.id, module]),
    );
    const layoutsByModuleId = new Map(
        roomLayouts
            .filter((layout) => modulesById.has(layout.moduleId))
            .map((layout) => [layout.moduleId, layout]),
    );
    const rooms = [...modulesById.values()]
        .filter((module) => layoutsByModuleId.has(module.id))
        .map((module) => {
            const layout = layoutsByModuleId.get(module.id);

            return {
                module,
                layout,
                geometry: getRoomGeometry(layout),
            };
        })
        .sort((left, right) => (
            left.layout.floor - right.layout.floor
            || left.layout.row - right.layout.row
            || left.layout.column - right.layout.column
            || left.module.id.localeCompare(right.module.id)
        ));
    const nodes = rooms.map(({ module, geometry }) => ({
        id: module.id,
        x: geometry.centerX,
        y: geometry.centerY,
        gateway: false,
    }));
    const localNodesById = new Map(nodes.map((node) => [node.id, node]));
    const uniqueConnections = [...new Map(
        connections.map((connection) => [connection.id, connection]),
    ).values()];
    const drawableConnections = [];
    const pendingGateways = new Map();

    for (const connection of uniqueConnections) {
        const fromNode = localNodesById.get(connection.fromId);
        const toNode = localNodesById.get(connection.toId);

        if (fromNode && toNode) {
            drawableConnections.push(connection);
            continue;
        }

        const localNode = fromNode ?? toNode;
        if (!localNode) continue;

        const externalId = fromNode ? connection.toId : connection.fromId;
        const previous = pendingGateways.get(externalId) ?? {
            id: externalId,
            label: gatewayLabel(externalId, entityNames),
            localYTotal: 0,
            references: 0,
        };

        previous.localYTotal += localNode.y;
        previous.references += 1;
        pendingGateways.set(externalId, previous);
        drawableConnections.push(connection);
    }

    const gatewaysBySide = { top: [], bottom: [] };
    for (const gateway of [...pendingGateways.values()].sort((left, right) => left.id.localeCompare(right.id))) {
        const averageLocalY = gateway.localYTotal / gateway.references;
        const side = averageLocalY < APPLICATION_VIEW_BOX.height / 2 ? 'top' : 'bottom';
        gatewaysBySide[side].push({ ...gateway, side });
    }

    const gateways = Object.entries(gatewaysBySide).flatMap(([side, sideGateways]) => (
        sideGateways.map((gateway, index) => ({
            ...gatewayPosition(side, index, sideGateways.length),
            id: gateway.id,
            label: gateway.label,
            gateway: true,
            side,
        }))
    ));
    const allNodes = [...nodes, ...gateways];
    const nodesById = new Map(allNodes.map((node) => [node.id, node]));
    const routes = drawableConnections
        .map((connection) => ({
            id: `dynamic.${connection.id}`,
            connectionId: connection.id,
            path: buildConnectionPath(
                nodesById.get(connection.fromId),
                nodesById.get(connection.toId),
            ),
        }))
        .filter((route) => route.path);
    const floors = [...new Set(rooms.map(({ layout }) => layout.floor))]
        .sort((left, right) => left - right)
        .map((floor) => floorPlate(
            floor,
            rooms.filter(({ layout }) => layout.floor === floor),
        ));

    return {
        rooms,
        floors,
        gateways,
        nodes: allNodes,
        connections: drawableConnections,
        routes,
    };
}
