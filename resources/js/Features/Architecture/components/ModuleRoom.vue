<script setup>
import { computed } from 'vue';
import ArchitectureStatusPattern from './ArchitectureStatusPattern.vue';
import { getRoomGeometry } from '../lib/architectureGeometry.js';
import {
    getRoomLabelVerticalGeometry,
    ROOM_LABEL_LAYOUT,
    ROOM_TYPOGRAPHY_UNITS,
    wrapRoomNameLabel,
    wrapRoomStatusLabel,
} from '../lib/architectureSceneTokens.js';
import {
    ROOM_TEXT_COLORS,
    statusVisualToken,
} from '../lib/architectureVisualTokens.js';

const props = defineProps({
    module: {
        type: Object,
        required: true,
        validator: (value) => (
            typeof value.id === 'string'
            && typeof value.name === 'string'
            && typeof value.description === 'string'
        ),
    },
    layout: {
        type: Object,
        required: true,
        validator: (value) => (
            typeof value.moduleId === 'string'
            && ['floor', 'column', 'row', 'columnSpan'].every((key) => Number.isFinite(value[key]))
        ),
    },
    status: {
        type: Object,
        default: null,
        validator: (value) => value === null || (
            typeof value.id === 'string' && typeof value.label === 'string'
        ),
    },
    selected: {
        type: Boolean,
        default: false,
    },
    dimmed: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits({
    select: (id) => typeof id === 'string',
});

const geometry = computed(() => getRoomGeometry(props.layout));
const statusId = computed(() => props.module.status?.id ?? 'unknown');
const statusLabel = computed(() => props.status?.label ?? 'Sin estado');
const style = computed(() => statusVisualToken(statusId.value));
const patternId = computed(() => (
    `atlas-room-${props.module.id.replace(/[^a-z0-9-]/gi, '-')}-${style.value.pattern}`
));
const safeModuleId = computed(() => props.module.id.replace(/[^a-z0-9-]/gi, '-'));
const nameClipId = computed(() => `atlas-room-${safeModuleId.value}-name-clip`);
const statusClipId = computed(() => `atlas-room-${safeModuleId.value}-status-clip`);
const roomFill = computed(() => (
    style.value.pattern === 'solid' ? style.value.fill : `url(#${patternId.value})`
));
const isOffline = computed(() => props.module.offlineCapability?.supported === true);
const ariaLabel = computed(() => (
    `${props.module.name}. ${props.module.description}. Estado: ${statusLabel.value}. `
    + `${isOffline.value ? 'Tiene capacidad offline. ' : ''}Presiona Enter o Espacio para abrir el detalle técnico.`
));
const nameContentWidth = computed(() => (
    geometry.value.width
    - (ROOM_LABEL_LAYOUT.horizontalInset * 2)
    - (isOffline.value ? ROOM_LABEL_LAYOUT.offlineNameReserve : 0)
));
const statusContentWidth = computed(() => (
    geometry.value.width - (ROOM_LABEL_LAYOUT.horizontalInset * 2)
));

const labelLines = computed(() => wrapRoomNameLabel(
    props.module.name,
    nameContentWidth.value,
    ROOM_TYPOGRAPHY_UNITS.name,
));
const statusLines = computed(() => wrapRoomStatusLabel(
    statusLabel.value,
    statusContentWidth.value,
    ROOM_TYPOGRAPHY_UNITS.status,
));
const labelGeometry = computed(() => getRoomLabelVerticalGeometry({
    roomHeight: geometry.value.height,
    nameLineCount: labelLines.value.length,
    statusLineCount: statusLines.value.length,
}));
const offlineBadgeTransform = computed(() => {
    const badge = ROOM_LABEL_LAYOUT.offlineBadge;
    const x = geometry.value.x + geometry.value.width - badge.width - badge.rightInset;
    const y = geometry.value.y + badge.topInset;

    return `translate(${x}, ${y})`;
});

const bevelPoints = computed(() => ({
    right: `${geometry.value.x + geometry.value.width},${geometry.value.y} ${geometry.value.x + geometry.value.width + geometry.value.depth},${geometry.value.y + geometry.value.depth} ${geometry.value.x + geometry.value.width + geometry.value.depth},${geometry.value.y + geometry.value.height + geometry.value.depth} ${geometry.value.x + geometry.value.width},${geometry.value.y + geometry.value.height}`,
    bottom: `${geometry.value.x},${geometry.value.y + geometry.value.height} ${geometry.value.x + geometry.value.width},${geometry.value.y + geometry.value.height} ${geometry.value.x + geometry.value.width + geometry.value.depth},${geometry.value.y + geometry.value.height + geometry.value.depth} ${geometry.value.x + geometry.value.depth},${geometry.value.y + geometry.value.height + geometry.value.depth}`,
}));

function select() {
    emit('select', props.module.id);
}

function onKeydown(event) {
    if (!['Enter', ' '].includes(event.key)) return;

    event.preventDefault();
    select();
}
</script>

<template>
    <g
        class="module-room"
        :class="{
            'module-room--selected': selected,
            'module-room--dimmed': dimmed,
        }"
        role="button"
        tabindex="0"
        :aria-label="ariaLabel"
        :data-module-id="module.id"
        :data-status="statusId"
        @click="select"
        @keydown="onKeydown"
    >
        <defs>
            <ArchitectureStatusPattern
                v-if="style.pattern !== 'solid'"
                :pattern-id="patternId"
                :token="style"
            />
            <clipPath :id="nameClipId">
                <rect
                    class="module-room__content-clip"
                    :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
                    :y="geometry.y + ROOM_LABEL_LAYOUT.nameClipTop"
                    :width="nameContentWidth"
                    :height="ROOM_LABEL_LAYOUT.nameClipHeight"
                />
            </clipPath>
            <clipPath :id="statusClipId">
                <rect
                    class="module-room__content-clip"
                    :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
                    :y="geometry.y + ROOM_LABEL_LAYOUT.statusClipTop"
                    :width="statusContentWidth"
                    :height="ROOM_LABEL_LAYOUT.statusClipHeight"
                />
            </clipPath>
        </defs>

        <rect
            class="module-room__hit-area"
            :x="geometry.x - 2"
            :y="geometry.y - 2"
            :width="geometry.width + geometry.depth + 4"
            :height="geometry.height + geometry.depth + 4"
            rx="6"
        />
        <polygon class="module-room__bevel module-room__bevel--bottom" :points="bevelPoints.bottom" />
        <polygon class="module-room__bevel module-room__bevel--right" :points="bevelPoints.right" />
        <rect
            class="module-room__body"
            :x="geometry.x"
            :y="geometry.y"
            :width="geometry.width"
            :height="geometry.height"
            rx="3"
            :fill="roomFill"
            :stroke="selected ? '#ffffff' : style.stroke"
            :stroke-dasharray="selected ? undefined : (style.dash || undefined)"
            vector-effect="non-scaling-stroke"
        />
        <rect
            class="module-room__focus-outline"
            :x="geometry.x - 4"
            :y="geometry.y - 4"
            :width="geometry.width + geometry.depth + 8"
            :height="geometry.height + geometry.depth + 8"
            rx="6"
            vector-effect="non-scaling-stroke"
        />

        <path
            v-if="selected"
            class="module-room__selected-mark"
            :d="`M ${geometry.x} ${geometry.y + 20} L ${geometry.x - 9} ${geometry.y + 10} L ${geometry.x} ${geometry.y}`"
            vector-effect="non-scaling-stroke"
        />

        <text
            class="module-room__name"
            :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
            :y="geometry.y + labelGeometry.nameBaselines[0]"
            :font-size="ROOM_TYPOGRAPHY_UNITS.name"
            :fill="ROOM_TEXT_COLORS.name"
            :clip-path="`url(#${nameClipId})`"
        >
            <tspan
                v-for="(line, index) in labelLines"
                :key="`${module.id}-line-${index}`"
                :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
                :dy="index === 0 ? 0 : ROOM_LABEL_LAYOUT.nameLineHeight"
            >{{ line }}</tspan>
        </text>
        <text
            class="module-room__status"
            :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
            :y="geometry.y + labelGeometry.statusBaselines[0]"
            :font-size="ROOM_TYPOGRAPHY_UNITS.status"
            :fill="ROOM_TEXT_COLORS.status"
            :clip-path="`url(#${statusClipId})`"
        >
            <tspan
                v-for="(line, index) in statusLines"
                :key="`${module.id}-status-line-${index}`"
                :x="geometry.x + ROOM_LABEL_LAYOUT.horizontalInset"
                :dy="index === 0 ? 0 : ROOM_LABEL_LAYOUT.statusLineHeight"
            >{{ line }}</tspan>
        </text>

        <g
            v-if="isOffline"
            class="module-room__offline"
            :transform="offlineBadgeTransform"
        >
            <rect
                :width="ROOM_LABEL_LAYOUT.offlineBadge.width"
                :height="ROOM_LABEL_LAYOUT.offlineBadge.height"
                rx="3"
            />
            <text
                :x="ROOM_LABEL_LAYOUT.offlineBadge.textCenterX"
                :y="ROOM_LABEL_LAYOUT.offlineBadge.textCenterY"
                :font-size="ROOM_TYPOGRAPHY_UNITS.offline"
                :textLength="ROOM_LABEL_LAYOUT.offlineBadge.textLength"
                lengthAdjust="spacingAndGlyphs"
                text-anchor="middle"
                dominant-baseline="middle"
                transform="rotate(90 12 22)"
            >OFFLINE</text>
        </g>
    </g>
</template>

<style scoped>
.module-room {
    cursor: pointer;
    outline: none;
}

.module-room--dimmed .module-room__bevel {
    opacity: 0.42;
}

.module-room--dimmed .module-room__body {
    stroke-opacity: 0.38;
}

.module-room__hit-area {
    fill: transparent;
    pointer-events: all;
}

.module-room__body {
    stroke-width: 2;
}

.module-room--selected .module-room__body {
    stroke-width: 4;
}

.module-room__bevel {
    fill: #0f172a;
    stroke: #475569;
    stroke-linejoin: round;
    stroke-width: 1;
}

.module-room__bevel--right {
    fill: #111827;
}

.module-room__focus-outline {
    fill: none;
    opacity: 0;
    stroke: #ffffff;
    stroke-dasharray: 5 3;
    stroke-width: 3;
}

.module-room:focus-visible .module-room__focus-outline {
    opacity: 1;
}

.module-room:hover .module-room__body,
.module-room:focus-visible .module-room__body {
    stroke: #ffffff;
    stroke-opacity: 1;
    stroke-width: 3;
}

.module-room__selected-mark {
    fill: none;
    stroke: #7f1d1d;
    stroke-linecap: square;
    stroke-width: 2;
}

.module-room__name {
    font-weight: 900;
    letter-spacing: 0.01em;
    pointer-events: none;
}

.module-room__status {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}

.module-room__offline rect {
    fill: #0f172a;
    stroke: #64748b;
    stroke-width: 1;
}

.module-room__offline text {
    fill: #f8fafc;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 900;
    letter-spacing: 0.04em;
}
</style>
