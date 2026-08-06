<script setup>
import { computed } from 'vue';

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
        required: true,
        validator: (value) => value.every((route) => (
            typeof route.id === 'string'
            && typeof route.connectionId === 'string'
            && typeof route.path === 'string'
        )),
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

const connectionStyles = {
    http: { dash: '', color: '#cbd5e1', linecap: 'square' },
    websocket: { dash: '18 4', color: '#38bdf8', linecap: 'round' },
    polling: { dash: '7 9', color: '#f59e0b', linecap: 'butt' },
    ipc: { dash: '2 5', color: '#c084fc', linecap: 'round' },
    'usb-serial': { dash: '14 3 2 3', color: '#fb923c', linecap: 'butt' },
    mdns: { dash: '1 8', color: '#2dd4bf', linecap: 'round' },
    database: { dash: '2 2', color: '#a78bfa', linecap: 'butt' },
    'sync-outbox': { dash: '12 6', color: '#22c55e', linecap: 'square' },
    cache: { dash: '4 5', color: '#eab308', linecap: 'butt' },
    'external-link': { dash: '8 8', color: '#94a3b8', linecap: 'square' },
};

const fallbackStyle = connectionStyles['external-link'];

const visibleRoutes = computed(() => {
    const connectionsById = new Map(
        props.connections.map((connection) => [connection.id, connection]),
    );

    return props.routes
        .filter((route) => connectionsById.has(route.connectionId))
        .map((route) => ({
            ...route,
            connection: connectionsById.get(route.connectionId),
        }));
});

const usedKinds = computed(() => [...new Set(
    visibleRoutes.value.map(({ connection }) => connection.kind),
)]);

function styleFor(kind) {
    return connectionStyles[kind] ?? fallbackStyle;
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
