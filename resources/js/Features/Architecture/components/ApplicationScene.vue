<script setup>
import { computed } from 'vue';
import ConnectionLayer from './ConnectionLayer.vue';
import ModuleRoom from './ModuleRoom.vue';
import {
    APPLICATION_VIEW_BOX,
    buildApplicationScene,
} from '../lib/architectureGeometry.js';

const props = defineProps({
    application: {
        type: Object,
        required: true,
        validator: (value) => (
            typeof value.id === 'string'
            && typeof value.name === 'string'
            && typeof value.description === 'string'
        ),
    },
    modules: {
        type: Array,
        required: true,
        validator: (value) => value.every((module) => (
            typeof module.id === 'string' && typeof module.applicationId === 'string'
        )),
    },
    connections: {
        type: Array,
        required: true,
    },
    layout: {
        type: Object,
        required: true,
        validator: (value) => Array.isArray(value.rooms),
    },
    statuses: {
        type: Array,
        required: true,
    },
    entityNames: {
        type: Object,
        default: () => ({}),
    },
    selectedId: {
        type: String,
        default: null,
    },
    mode: {
        type: String,
        default: 'dependencies',
        validator: (value) => ['dependencies', 'data', 'sync'].includes(value),
    },
});

const emit = defineEmits({
    'select-module': (id) => typeof id === 'string',
});

const modeCopy = {
    dependencies: 'Qué necesita este componente para funcionar',
    data: 'Dónde se origina, persiste y consume la información',
    sync: 'Qué se conserva localmente y cuándo viaja al backend',
};

const scene = computed(() => buildApplicationScene({
    applicationId: props.application.id,
    modules: props.modules,
    roomLayouts: props.layout.rooms,
    connections: props.connections,
    entityNames: props.entityNames,
}));
const statusesById = computed(() => new Map(
    props.statuses.map((status) => [status.id, status]),
));
const viewBox = `${APPLICATION_VIEW_BOX.minX} ${APPLICATION_VIEW_BOX.minY} ${APPLICATION_VIEW_BOX.width} ${APPLICATION_VIEW_BOX.height}`;
const currentModeCopy = computed(() => modeCopy[props.mode] ?? modeCopy.dependencies);
const sceneTitleId = computed(() => `${props.application.id.replaceAll('.', '-')}-plant-title`);
const sceneDescriptionId = computed(() => `${props.application.id.replaceAll('.', '-')}-plant-description`);

function statusFor(module) {
    return statusesById.value.get(module.status?.id) ?? null;
}

function isDimmed(moduleId) {
    return Boolean(props.selectedId) && moduleId !== props.selectedId;
}

function gatewayLabelY(gateway) {
    return gateway.side === 'top' ? gateway.y - 26 : gateway.y + 18;
}

function gatewayLabelLines(label) {
    const words = label.split(/\s+/);
    const lines = [''];

    for (const word of words) {
        const current = lines.at(-1);
        if (current && current.length + word.length + 1 > 18 && lines.length < 2) {
            lines.push(word);
        } else {
            lines[lines.length - 1] = current ? `${current} ${word}` : word;
        }
    }

    if (lines[1]?.length > 18) lines[1] = `${lines[1].slice(0, 17).trim()}…`;

    return lines;
}
</script>

<template>
    <figure
        class="application-scene"
        :aria-labelledby="sceneTitleId"
        :aria-describedby="sceneDescriptionId"
    >
        <header class="application-scene__header">
            <div class="min-w-0">
                <p class="application-scene__eyebrow">ATLAS / PLANTA TÉCNICA</p>
                <h2 :id="sceneTitleId" class="application-scene__title">{{ application.name }}</h2>
                <p :id="sceneDescriptionId" class="application-scene__description">{{ application.description }}</p>
            </div>
            <div class="application-scene__counter" aria-label="Cantidad de habitaciones visibles">
                <strong>{{ scene.rooms.length.toString().padStart(2, '0') }}</strong>
                <span>habitaciones</span>
            </div>
        </header>

        <div class="application-scene__mode" role="note">
            <span aria-hidden="true">⌁</span>
            <p><strong>Lectura activa:</strong> {{ currentModeCopy }}</p>
        </div>

        <div
            v-if="scene.rooms.length === 0"
            role="status"
            class="application-scene__empty"
        >
            <p>No encontramos componentes en esta planta.</p>
            <span>Prueba otra búsqueda o limpia los filtros.</span>
        </div>

        <div v-else class="application-scene__viewport">
            <svg
                class="application-scene__svg"
                :viewBox="viewBox"
                role="group"
                :aria-labelledby="`${sceneTitleId} ${sceneDescriptionId}-map`"
                preserveAspectRatio="xMidYMid meet"
            >
                <desc :id="`${sceneDescriptionId}-map`">
                    Planta técnica de {{ application.name }} con habitaciones seleccionables y conexiones del modo {{ mode }}.
                </desc>

                <defs>
                    <pattern id="atlas-application-grid" width="20" height="20" patternUnits="userSpaceOnUse">
                        <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#263449" stroke-width="1" />
                    </pattern>
                </defs>

                <rect width="1200" height="720" fill="#07111f" />
                <rect x="20" y="20" width="1160" height="680" rx="12" fill="url(#atlas-application-grid)" stroke="#475569" stroke-width="2" vector-effect="non-scaling-stroke" />

                <g class="application-scene__floors" aria-hidden="true">
                    <g v-for="floor in scene.floors" :key="floor.floor">
                        <rect
                            class="application-scene__floor-plate"
                            :x="floor.x"
                            :y="floor.y"
                            :width="floor.width"
                            :height="floor.height"
                            rx="5"
                            vector-effect="non-scaling-stroke"
                        />
                        <text
                            class="application-scene__floor-label"
                            :x="floor.labelX"
                            :y="floor.labelY"
                        >
                            PISO {{ floor.floor.toString().padStart(2, '0') }}
                        </text>
                    </g>
                </g>

                <ConnectionLayer
                    :connections="scene.connections"
                    :nodes="scene.nodes"
                    :mode="mode"
                    :selected-entity-id="selectedId"
                    :marker-prefix="`atlas-${application.id.replaceAll('.', '-')}`"
                />

                <g class="application-scene__gateways" aria-hidden="true">
                    <g
                        v-for="gateway in scene.gateways"
                        :key="gateway.id"
                        :data-gateway-id="gateway.id"
                    >
                        <title>Salida hacia {{ gateway.label }}</title>
                        <rect
                            class="application-scene__gateway-node"
                            :x="gateway.x - 8"
                            :y="gateway.y - 8"
                            width="16"
                            height="16"
                            rx="2"
                            vector-effect="non-scaling-stroke"
                        />
                        <text
                            class="application-scene__gateway-label"
                            :x="gateway.x"
                            :y="gatewayLabelY(gateway)"
                            text-anchor="middle"
                        >
                            <tspan
                                v-for="(line, index) in gatewayLabelLines(gateway.label)"
                                :key="`${gateway.id}-label-${index}`"
                                :x="gateway.x"
                                :dy="index === 0 ? 0 : 10"
                            >{{ line }}</tspan>
                        </text>
                    </g>
                </g>

                <ModuleRoom
                    v-for="room in scene.rooms"
                    :key="room.module.id"
                    :module="room.module"
                    :layout="room.layout"
                    :status="statusFor(room.module)"
                    :selected="room.module.id === selectedId"
                    :dimmed="isDimmed(room.module.id)"
                    @select="emit('select-module', $event)"
                />
            </svg>
        </div>

        <figcaption v-if="scene.rooms.length" class="application-scene__caption">
            <span class="application-scene__caption-mark" aria-hidden="true">↳</span>
            <span>
                Los bloques son módulos reales. Las placas de borde indican conexiones hacia otra aplicación o servicio.
                Usa <kbd>Tab</kbd> y abre una habitación con <kbd>Enter</kbd> o <kbd>Espacio</kbd>.
            </span>
        </figcaption>
    </figure>
</template>

<style scoped>
.application-scene {
    overflow: hidden;
    border: 1px solid #334155;
    border-radius: 1.5rem;
    background: #020617;
    box-shadow: 0 20px 45px -28px rgba(2, 6, 23, 0.9);
    color: #f8fafc;
}

.application-scene__header {
    display: flex;
    min-height: 7rem;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    border-bottom: 1px solid #334155;
    padding: 1rem 1.25rem;
}

.application-scene__eyebrow,
.application-scene__counter span {
    color: #fca5a5;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.application-scene__title {
    margin-top: 0.3rem;
    color: #ffffff;
    font-size: 1.2rem;
    font-weight: 900;
    line-height: 1.3;
}

.application-scene__description {
    margin-top: 0.35rem;
    max-width: 46rem;
    color: #94a3b8;
    font-size: 0.8rem;
    line-height: 1.5;
}

.application-scene__counter {
    display: grid;
    min-width: 6.5rem;
    justify-items: end;
}

.application-scene__counter strong {
    color: #ffffff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.75rem;
    line-height: 1;
}

.application-scene__counter span {
    margin-top: 0.35rem;
    color: #94a3b8;
    letter-spacing: 0.08em;
}

.application-scene__mode {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    border-bottom: 1px solid #334155;
    background: #0f172a;
    padding: 0.7rem 1.25rem;
    color: #cbd5e1;
    font-size: 0.78rem;
}

.application-scene__mode > span {
    color: #f87171;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.1rem;
}

.application-scene__viewport {
    overflow-x: auto;
}

.application-scene__svg {
    display: block;
    width: 100%;
    min-width: 46rem;
    height: auto;
    aspect-ratio: 5 / 3;
}

.application-scene__floor-plate {
    fill: rgba(15, 23, 42, 0.78);
    stroke: #64748b;
    stroke-dasharray: 4 5;
    stroke-width: 1.5;
}

.application-scene__floor-label {
    fill: #94a3b8;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.14em;
}

.application-scene__gateway-node {
    fill: #f8fafc;
    stroke: #ef4444;
    stroke-width: 3;
}

.application-scene__gateway-label {
    fill: #e2e8f0;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.05em;
}

.application-scene__empty {
    margin: 1.25rem;
    border: 1px dashed #64748b;
    border-radius: 1rem;
    background: #0f172a;
    padding: 3rem 1.5rem;
    text-align: center;
}

.application-scene__empty p {
    color: #ffffff;
    font-weight: 900;
}

.application-scene__empty span {
    display: block;
    margin-top: 0.35rem;
    color: #94a3b8;
    font-size: 0.82rem;
}

.application-scene__caption {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    border-top: 1px solid #334155;
    padding: 0.85rem 1rem;
    color: #cbd5e1;
    font-size: 0.78rem;
    line-height: 1.5;
}

.application-scene__caption-mark {
    color: #f87171;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1rem;
}

.application-scene__caption kbd {
    border: 1px solid #64748b;
    border-bottom-width: 2px;
    border-radius: 0.25rem;
    background: #1e293b;
    padding: 0.08rem 0.3rem;
    color: #ffffff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.68rem;
}

@media (max-width: 640px) {
    .application-scene__header {
        flex-direction: column;
    }

    .application-scene__counter {
        justify-items: start;
    }
}
</style>
