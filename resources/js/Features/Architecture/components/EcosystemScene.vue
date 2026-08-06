<script setup>
import { computed } from 'vue';
import ApplicationBuilding from './ApplicationBuilding.vue';
import ConnectionLayer from './ConnectionLayer.vue';

const FALLBACK_VIEW_BOX = Object.freeze({
    minX: 0,
    minY: 0,
    width: 1200,
    height: 720,
});

const props = defineProps({
    applications: {
        type: Array,
        required: true,
        validator: (value) => value.every((application) => (
            typeof application.id === 'string' && typeof application.name === 'string'
        )),
    },
    connections: {
        type: Array,
        required: true,
    },
    layout: {
        type: Object,
        required: true,
        validator: (value) => (
            typeof value.viewBox === 'string'
            && Array.isArray(value.buildings)
            && Array.isArray(value.connectionRoutes)
        ),
    },
    selectedId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits({
    'select-application': (id) => typeof id === 'string',
});

const applicationsById = computed(() => new Map(
    props.applications.map((application) => [application.id, application]),
));

const buildings = computed(() => props.layout.buildings
    .map((building) => ({
        application: applicationsById.value.get(building.applicationId),
        layout: building,
    }))
    .filter(({ application }) => Boolean(application)));

const viewBoxMetrics = computed(() => {
    const values = props.layout.viewBox.trim().split(/\s+/).map(Number);

    if (
        values.length !== 4
        || values.some((value) => !Number.isFinite(value))
        || values[2] <= 0
        || values[3] <= 0
    ) {
        return FALLBACK_VIEW_BOX;
    }

    const [minX, minY, width, height] = values;
    return { minX, minY, width, height };
});

const safeViewBox = computed(() => {
    const {
        minX,
        minY,
        width,
        height,
    } = viewBoxMetrics.value;

    return `${minX} ${minY} ${width} ${height}`;
});

const sceneStyle = computed(() => ({
    aspectRatio: `${viewBoxMetrics.value.width} / ${viewBoxMetrics.value.height}`,
}));

const terrainInset = computed(() => ({
    x: viewBoxMetrics.value.minX + 24,
    y: viewBoxMetrics.value.minY + 24,
    width: Math.max(0, viewBoxMetrics.value.width - 48),
    height: Math.max(0, viewBoxMetrics.value.height - 48),
}));

function isDimmed(applicationId) {
    return Boolean(props.selectedId) && props.selectedId !== applicationId;
}
</script>

<template>
    <figure
        class="ecosystem-scene"
        aria-labelledby="ecosystem-map-title"
        aria-describedby="ecosystem-map-caption"
    >
        <div class="ecosystem-scene__header" aria-hidden="true">
            <span>ATLAS / PLANO 01</span>
            <span>{{ buildings.length.toString().padStart(2, '0') }} NODOS DE APLICACIÓN</span>
        </div>

        <div class="ecosystem-scene__viewport">
            <svg
                class="ecosystem-scene__svg"
                :viewBox="safeViewBox"
                :style="sceneStyle"
                role="group"
                aria-labelledby="ecosystem-map-title ecosystem-map-description"
                preserveAspectRatio="xMidYMid meet"
            >
                <title id="ecosystem-map-title">Mapa vivo del ecosistema Carnicería</title>
                <desc id="ecosystem-map-description">
                    Campus técnico con aplicaciones seleccionables y conductos que representan las conexiones visibles.
                </desc>

                <defs>
                    <pattern
                        id="atlas-ecosystem-minor-grid"
                        width="24"
                        height="24"
                        patternUnits="userSpaceOnUse"
                    >
                        <path d="M 24 0 L 0 0 0 24" fill="none" stroke="#334155" stroke-width="1" />
                    </pattern>
                    <pattern
                        id="atlas-ecosystem-major-grid"
                        width="120"
                        height="120"
                        patternUnits="userSpaceOnUse"
                    >
                        <rect width="120" height="120" fill="url(#atlas-ecosystem-minor-grid)" />
                        <path d="M 120 0 L 0 0 0 120" fill="none" stroke="#475569" stroke-width="1.5" />
                    </pattern>
                </defs>

                <rect
                    :x="viewBoxMetrics.minX"
                    :y="viewBoxMetrics.minY"
                    :width="viewBoxMetrics.width"
                    :height="viewBoxMetrics.height"
                    fill="#07111f"
                />
                <rect
                    :x="terrainInset.x"
                    :y="terrainInset.y"
                    :width="terrainInset.width"
                    :height="terrainInset.height"
                    rx="12"
                    fill="url(#atlas-ecosystem-major-grid)"
                    stroke="#475569"
                    stroke-width="2"
                    vector-effect="non-scaling-stroke"
                />

                <g class="ecosystem-scene__axes" aria-hidden="true">
                    <path
                        class="ecosystem-scene__axis-line"
                        :d="`M ${terrainInset.x} ${terrainInset.y + 42} H ${terrainInset.x + terrainInset.width}`"
                        vector-effect="non-scaling-stroke"
                    />
                    <path
                        class="ecosystem-scene__axis-line"
                        :d="`M ${terrainInset.x + 42} ${terrainInset.y} V ${terrainInset.y + terrainInset.height}`"
                        vector-effect="non-scaling-stroke"
                    />
                    <text class="ecosystem-scene__axis-label" :x="terrainInset.x + 55" :y="terrainInset.y + 30">EJE Y / SERVICIOS EXTERNOS</text>
                    <text class="ecosystem-scene__axis-label" :x="terrainInset.x + terrainInset.width - 12" :y="terrainInset.y + 31" text-anchor="end">NORTE TÉCNICO ↑</text>
                </g>

                <ConnectionLayer
                    :connections="connections"
                    :routes="layout.connectionRoutes"
                    :selected-entity-id="selectedId"
                    marker-prefix="atlas-ecosystem"
                />

                <ApplicationBuilding
                    v-for="building in buildings"
                    :key="building.application.id"
                    :application="building.application"
                    :layout="building.layout"
                    :selected="selectedId === building.application.id"
                    :dimmed="isDimmed(building.application.id)"
                    @select="emit('select-application', $event)"
                />

                <text
                    v-if="buildings.length === 0"
                    class="ecosystem-scene__empty"
                    :x="viewBoxMetrics.minX + viewBoxMetrics.width / 2"
                    :y="viewBoxMetrics.minY + viewBoxMetrics.height / 2"
                    text-anchor="middle"
                >
                    No hay edificios ubicados en el manifiesto.
                </text>
            </svg>
        </div>

        <figcaption id="ecosystem-map-caption" class="ecosystem-scene__caption">
            <span class="ecosystem-scene__caption-mark" aria-hidden="true">⌁</span>
            <span>
                Usa <kbd class="ecosystem-scene__key">Tab</kbd> para recorrer los edificios y
                <kbd class="ecosystem-scene__key">Enter</kbd> o <kbd class="ecosystem-scene__key">Espacio</kbd> para abrir una aplicación.
                Los conductos visibles cambian con el modo de lectura.
            </span>
        </figcaption>
    </figure>
</template>

<style scoped>
.ecosystem-scene {
    overflow: hidden;
    border: 1px solid #334155;
    border-radius: 1.5rem;
    background: #020617;
    box-shadow: 0 20px 45px -28px rgba(2, 6, 23, 0.9);
    color: #f8fafc;
}

.ecosystem-scene__header {
    display: flex;
    min-height: 2.75rem;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid #334155;
    padding: 0.6rem 1rem;
    color: #94a3b8;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.15em;
}

.ecosystem-scene__viewport {
    overflow-x: auto;
    background: #07111f;
}

.ecosystem-scene__svg {
    display: block;
    width: 100%;
    min-width: 46rem;
    height: auto;
}

.ecosystem-scene__axis-line {
    fill: none;
    stroke: rgba(100, 116, 139, 0.42);
    stroke-dasharray: 4 8;
    stroke-width: 1;
}

.ecosystem-scene__axis-label {
    fill: #64748b;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.12em;
}

.ecosystem-scene__empty {
    fill: #cbd5e1;
    font-size: 16px;
    font-weight: 700;
}

.ecosystem-scene__caption {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-top: 1px solid #334155;
    padding: 0.85rem 1rem;
    color: #cbd5e1;
    font-size: 0.78rem;
    line-height: 1.4;
}

.ecosystem-scene__caption-mark {
    color: #f87171;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.2rem;
}

.ecosystem-scene__key {
    border: 1px solid #64748b;
    border-bottom-width: 2px;
    border-radius: 0.25rem;
    background: #1e293b;
    padding: 0.08rem 0.32rem;
    color: #ffffff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.68rem;
}

@media (max-width: 640px) {
    .ecosystem-scene__header {
        align-items: flex-start;
        flex-direction: column;
    }

    .ecosystem-scene__caption {
        align-items: flex-start;
    }
}
</style>
