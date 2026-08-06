<script setup>
import { computed } from 'vue';
import { getRoomGeometry } from '../lib/architectureGeometry.js';

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

const statusStyles = {
    implemented: { fill: '#dcfce7', stroke: '#22c55e', pattern: 'solid', dash: '' },
    partial: { fill: '#fef3c7', stroke: '#f59e0b', pattern: 'diagonal', dash: '8 3' },
    pending: { fill: '#e2e8f0', stroke: '#94a3b8', pattern: 'horizontal', dash: '3 4' },
    'in-review': { fill: '#dbeafe', stroke: '#3b82f6', pattern: 'vertical', dash: '10 3' },
    issues: { fill: '#fee2e2', stroke: '#ef4444', pattern: 'cross', dash: '2 2' },
    'requires-review': { fill: '#ffedd5', stroke: '#f97316', pattern: 'dots', dash: '1 4' },
    unknown: { fill: '#e5e7eb', stroke: '#6b7280', pattern: 'dense', dash: '6 5' },
    'not-responsible': { fill: '#ede9fe', stroke: '#8b5cf6', pattern: 'wide', dash: '12 3 2 3' },
};

const geometry = computed(() => getRoomGeometry(props.layout));
const statusId = computed(() => props.module.status?.id ?? 'unknown');
const statusLabel = computed(() => props.status?.label ?? 'Sin estado');
const style = computed(() => statusStyles[statusId.value] ?? statusStyles.unknown);
const patternId = computed(() => (
    `atlas-room-${props.module.id.replace(/[^a-z0-9-]/gi, '-')}-${style.value.pattern}`
));
const roomFill = computed(() => (
    style.value.pattern === 'solid' ? style.value.fill : `url(#${patternId.value})`
));
const opacity = computed(() => (props.dimmed ? 0.28 : 1));
const isOffline = computed(() => props.module.offlineCapability?.supported === true);
const ariaLabel = computed(() => (
    `${props.module.name}. ${props.module.description}. Estado: ${statusLabel.value}. `
    + `${isOffline.value ? 'Tiene capacidad offline. ' : ''}Presiona Enter o Espacio para abrir el detalle técnico.`
));

const labelLines = computed(() => {
    const maxCharacters = isOffline.value
        ? (props.layout.columnSpan > 1 ? 30 : 11)
        : (props.layout.columnSpan > 1 ? 38 : 20);
    const words = props.module.name.split(/\s+/);
    const lines = [];

    for (const word of words) {
        const current = lines.at(-1);
        if (!current || (current.length + word.length + 1 > maxCharacters && lines.length < 2)) {
            lines.push(word);
        } else {
            lines[lines.length - 1] = `${current} ${word}`;
        }
    }

    if (lines.length > 2) {
        lines[1] = `${lines.slice(1).join(' ').slice(0, maxCharacters - 1).trim()}…`;
        lines.length = 2;
    } else if (lines[1]?.length > maxCharacters) {
        lines[1] = `${lines[1].slice(0, maxCharacters - 1).trim()}…`;
    }

    return lines.map((line) => (
        line.length > maxCharacters
            ? `${line.slice(0, maxCharacters - 1).trim()}…`
            : line
    ));
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
        :class="{ 'module-room--selected': selected }"
        role="button"
        tabindex="0"
        :aria-label="ariaLabel"
        :data-module-id="module.id"
        :data-status="statusId"
        :style="{ '--atlas-room-opacity': opacity }"
        @click="select"
        @keydown="onKeydown"
    >
        <defs v-if="style.pattern !== 'solid'">
            <pattern :id="patternId" width="12" height="12" patternUnits="userSpaceOnUse">
                <rect width="12" height="12" :fill="style.fill" />
                <path v-if="style.pattern === 'diagonal'" d="M -3 12 L 12 -3 M 3 15 L 15 3" :stroke="style.stroke" stroke-width="2" opacity="0.42" />
                <path v-else-if="style.pattern === 'horizontal'" d="M 0 3 H 12 M 0 9 H 12" :stroke="style.stroke" stroke-width="1.5" opacity="0.46" />
                <path v-else-if="style.pattern === 'vertical'" d="M 3 0 V 12 M 9 0 V 12" :stroke="style.stroke" stroke-width="1.5" opacity="0.42" />
                <path v-else-if="style.pattern === 'cross'" d="M 0 0 L 12 12 M 12 0 L 0 12" :stroke="style.stroke" stroke-width="1.4" opacity="0.34" />
                <circle v-else-if="style.pattern === 'dots'" cx="3" cy="3" r="1.5" :fill="style.stroke" opacity="0.58" />
                <path v-else-if="style.pattern === 'dense'" d="M 0 2 H 12 M 0 6 H 12 M 0 10 H 12" :stroke="style.stroke" stroke-width="1" opacity="0.44" />
                <path v-else d="M -6 12 L 12 -6 M 0 18 L 18 0" :stroke="style.stroke" stroke-width="3" opacity="0.3" />
            </pattern>
        </defs>

        <rect
            class="module-room__hit-area"
            :x="geometry.x - 5"
            :y="geometry.y - 5"
            :width="geometry.width + geometry.depth + 10"
            :height="geometry.height + geometry.depth + 10"
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
            :x="geometry.x + 12"
            :y="geometry.y + 24"
        >
            <tspan
                v-for="(line, index) in labelLines"
                :key="`${module.id}-line-${index}`"
                :x="geometry.x + 12"
                :dy="index === 0 ? 0 : 16"
            >{{ line }}</tspan>
        </text>
        <text
            class="module-room__status"
            :x="geometry.x + 12"
            :y="geometry.y + geometry.height - 9"
        >
            {{ statusLabel }}
        </text>

        <g
            v-if="isOffline"
            class="module-room__offline"
            :transform="`translate(${geometry.x + geometry.width - 58}, ${geometry.y + 7})`"
        >
            <rect width="50" height="18" rx="2" />
            <text x="25" y="12" text-anchor="middle">OFFLINE</text>
        </g>
    </g>
</template>

<style scoped>
.module-room {
    cursor: pointer;
    opacity: var(--atlas-room-opacity, 1);
    outline: none;
}

.module-room:focus-visible {
    opacity: 1;
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
    stroke-width: 3;
}

.module-room__selected-mark {
    fill: none;
    stroke: #7f1d1d;
    stroke-linecap: square;
    stroke-width: 2;
}

.module-room__name {
    fill: #0f172a;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.01em;
    pointer-events: none;
}

.module-room__status {
    fill: #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.09em;
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
    font-size: 7px;
    font-weight: 900;
    letter-spacing: 0.08em;
}
</style>
