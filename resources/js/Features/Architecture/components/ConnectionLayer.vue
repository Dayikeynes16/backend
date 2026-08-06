<script setup>
import { computed } from 'vue';
import { buildConnectionPath } from '../lib/architectureGeometry.js';
import { connectionVisualToken } from '../lib/architectureVisualTokens.js';

const props = defineProps({
    connections: {
        type: Array,
        required: true,
        validator: (value) => value.every((connection) => (
            typeof connection.id === 'string' && typeof connection.kind === 'string'
        )),
    },
    routes: {
        type: Array,
        default: () => [],
        validator: (value) => value.every((route) => (
            typeof route.id === 'string'
            && typeof route.connectionId === 'string'
            && typeof route.path === 'string'
        )),
    },
    nodes: {
        type: Array,
        default: () => [],
        validator: (value) => value.every((node) => (
            typeof node.id === 'string'
            && Number.isFinite(node.x)
            && Number.isFinite(node.y)
        )),
    },
    mode: {
        type: String,
        default: null,
        validator: (value) => value === null || ['dependencies', 'data', 'sync'].includes(value),
    },
    selectedEntityId: {
        type: String,
        default: null,
    },
    markerPrefix: {
        type: String,
        default: 'atlas-ecosystem',
        validator: (value) => /^[a-z0-9-]+$/i.test(value),
    },
});

const visibleRoutes = computed(() => {
    const uniqueConnections = [...new Map(
        props.connections.map((connection) => [connection.id, connection]),
    ).values()].filter((connection) => (
        !props.mode || (connection.viewModes ?? ['dependencies']).includes(props.mode)
    ));
    const connectionsById = new Map(uniqueConnections.map((connection) => [connection.id, connection]));
    const declaredConnectionIds = new Set();
    const declaredRoutes = props.routes
        .filter((route) => connectionsById.has(route.connectionId))
        .filter((route) => {
            if (declaredConnectionIds.has(route.connectionId)) return false;

            declaredConnectionIds.add(route.connectionId);
            return true;
        })
        .map((route) => ({ ...route, connection: connectionsById.get(route.connectionId) }));
    const nodesById = new Map(props.nodes.map((node) => [node.id, node]));
    const dynamicRoutes = uniqueConnections
        .filter((connection) => !declaredConnectionIds.has(connection.id))
        .map((connection) => ({
            id: `dynamic.${connection.id}`,
            connectionId: connection.id,
            connection,
            path: buildConnectionPath(
                nodesById.get(connection.fromId),
                nodesById.get(connection.toId),
            ),
        }))
        .filter((route) => route.path);

    return [...declaredRoutes, ...dynamicRoutes];
});

const usedKinds = computed(() => [...new Set(
    visibleRoutes.value.map(({ connection }) => connection.kind),
)]);

function styleFor(kind) {
    return connectionVisualToken(kind);
}

function markerId(kind) {
    return `${props.markerPrefix}-${kind.replace(/[^a-z0-9-]/gi, '-')}-arrow`;
}

function isSelected(connection) {
    return Boolean(props.selectedEntityId)
        && [connection.fromId, connection.toId].includes(props.selectedEntityId);
}
</script>

<template>
    <g class="connection-layer" pointer-events="none" aria-hidden="true">
        <defs>
            <marker
                v-for="kind in usedKinds"
                :id="markerId(kind)"
                :key="kind"
                viewBox="0 0 10 10"
                refX="8"
                refY="5"
                markerWidth="6"
                markerHeight="6"
                orient="auto-start-reverse"
                markerUnits="strokeWidth"
            >
                <path d="M 0 0 L 10 5 L 0 10 Z" :fill="styleFor(kind).color" />
            </marker>
        </defs>

        <g
            v-for="route in visibleRoutes"
            :key="route.id"
            :data-connection-id="route.connection.id"
            :data-connection-kind="route.connection.kind"
        >
            <path
                class="connection-layer__rail"
                :d="route.path"
                vector-effect="non-scaling-stroke"
            />
            <path
                class="connection-layer__path"
                :class="{ 'connection-layer__path--sync': route.connection.kind === 'sync-outbox' }"
                :d="route.path"
                fill="none"
                :stroke="styleFor(route.connection.kind).color"
                :stroke-width="isSelected(route.connection) ? 3 : 1.5"
                :stroke-dasharray="styleFor(route.connection.kind).dash || undefined"
                :stroke-linecap="styleFor(route.connection.kind).linecap"
                :marker-end="`url(#${markerId(route.connection.kind)})`"
                vector-effect="non-scaling-stroke"
            />
        </g>
    </g>
</template>

<style scoped>
.connection-layer {
    pointer-events: none;
}

.connection-layer__rail {
    fill: none;
    stroke: rgba(2, 6, 23, 0.86);
    stroke-linecap: square;
    stroke-width: 6;
    vector-effect: non-scaling-stroke;
}

.connection-layer__path--sync {
    animation: atlas-outbox-flow 1.4s linear infinite;
}

@keyframes atlas-outbox-flow {
    to {
        stroke-dashoffset: -18;
    }
}

@media (prefers-reduced-motion: reduce) {
    .connection-layer__path--sync {
        animation: none;
    }
}
</style>
