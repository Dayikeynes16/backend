<script setup>
import { computed } from 'vue';
import { ECOSYSTEM_TYPOGRAPHY_UNITS } from '../lib/architectureSceneTokens.js';

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
    layout: {
        type: Object,
        required: true,
        validator: (value) => (
            ['x', 'y', 'width', 'depth', 'height'].every((key) => Number.isFinite(value[key]))
            && typeof value.tone === 'string'
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

const tonePalettes = {
    red: { top: '#f87171', left: '#991b1b', right: '#7f1d1d' },
    amber: { top: '#fbbf24', left: '#92400e', right: '#78350f' },
    sky: { top: '#38bdf8', left: '#075985', right: '#0c4a6e' },
    slate: { top: '#94a3b8', left: '#334155', right: '#1e293b' },
};

const palette = computed(() => tonePalettes[props.layout.tone] ?? tonePalettes.slate);

const points = computed(() => {
    const {
        x,
        y,
        width,
        depth,
        height,
    } = props.layout;

    return {
        top: `${x},${y} ${x + width},${y + depth / 2} ${x},${y + depth} ${x - width},${y + depth / 2}`,
        left: `${x - width},${y + depth / 2} ${x},${y + depth} ${x},${y + depth + height} ${x - width},${y + depth / 2 + height}`,
        right: `${x},${y + depth} ${x + width},${y + depth / 2} ${x + width},${y + depth / 2 + height} ${x},${y + depth + height}`,
        ground: `${x - width - 16},${y + depth / 2 + height + 8} ${x},${y + depth + height + 18} ${x + width + 16},${y + depth / 2 + height + 8} ${x},${y + depth + height + 30}`,
    };
});

const hitArea = computed(() => ({
    x: props.layout.x - props.layout.width - 18,
    y: props.layout.y - 18,
    width: (props.layout.width * 2) + 36,
    height: props.layout.depth + props.layout.height + 92,
}));

const label = computed(() => (
    `${props.application.name}. ${props.application.description}. Presiona Enter o Espacio para explorar.`
));

function select() {
    emit('select', props.application.id);
}

function onKeydown(event) {
    if (!['Enter', ' '].includes(event.key)) return;

    event.preventDefault();
    select();
}
</script>

<template>
    <g
        class="application-building"
        :class="{
            'application-building--selected': selected,
            'application-building--dimmed': dimmed,
        }"
        role="button"
        tabindex="0"
        :aria-label="label"
        :data-application-id="application.id"
        @click="select"
        @keydown="onKeydown"
    >
        <rect
            class="application-building__hit-area"
            :x="hitArea.x"
            :y="hitArea.y"
            :width="hitArea.width"
            :height="hitArea.height"
            rx="18"
        />
        <rect
            class="application-building__focus-outline"
            :x="hitArea.x + 4"
            :y="hitArea.y + 4"
            :width="hitArea.width - 8"
            :height="hitArea.height - 8"
            rx="15"
            vector-effect="non-scaling-stroke"
        />

        <polygon class="application-building__ground" :points="points.ground" />
        <polygon
            class="application-building__face"
            :points="points.left"
            :fill="selected ? '#b91c1c' : palette.left"
        />
        <polygon
            class="application-building__face"
            :points="points.right"
            :fill="selected ? '#7f1d1d' : palette.right"
        />
        <polygon
            class="application-building__roof"
            :points="points.top"
            :fill="selected ? '#ffffff' : palette.top"
        />

        <path
            class="application-building__structure-line"
            :d="`M ${layout.x} ${layout.y + layout.depth} V ${layout.y + layout.depth + layout.height}`"
            vector-effect="non-scaling-stroke"
        />
        <path
            class="application-building__structure-line application-building__structure-line--muted"
            :d="`M ${layout.x - layout.width * 0.54} ${layout.y + layout.depth * 0.73 + layout.height * 0.56} L ${layout.x} ${layout.y + layout.depth + layout.height * 0.74} L ${layout.x + layout.width * 0.54} ${layout.y + layout.depth * 0.73 + layout.height * 0.56}`"
            vector-effect="non-scaling-stroke"
        />

        <g :transform="`translate(${layout.x}, ${layout.y + layout.depth + layout.height + 45})`">
            <rect
                class="application-building__label-plate"
                :class="{ 'application-building__label-plate--selected': selected }"
                x="-112"
                y="-29"
                width="224"
                height="58"
                rx="4"
                vector-effect="non-scaling-stroke"
            />
            <path
                v-if="selected"
                class="application-building__selection-mark"
                d="M -100 -11 V 12 M -106 6 L -100 12 L -94 6"
                vector-effect="non-scaling-stroke"
            />
            <text
                class="application-building__name"
                text-anchor="middle"
                y="-5"
                :font-size="ECOSYSTEM_TYPOGRAPHY_UNITS.applicationName"
            >
                {{ application.name }}
            </text>
            <text
                class="application-building__id"
                text-anchor="middle"
                y="16"
                :font-size="ECOSYSTEM_TYPOGRAPHY_UNITS.applicationId"
            >
                {{ application.id }}
            </text>
        </g>
    </g>
</template>

<style scoped>
.application-building {
    cursor: pointer;
    outline: none;
}

.application-building--dimmed .application-building__ground,
.application-building--dimmed .application-building__face,
.application-building--dimmed .application-building__roof,
.application-building--dimmed .application-building__structure-line {
    opacity: 0.42;
}

.application-building__hit-area {
    fill: transparent;
    pointer-events: all;
}

.application-building__focus-outline {
    fill: none;
    opacity: 0;
    stroke: #ffffff;
    stroke-dasharray: 7 5;
    stroke-width: 3;
}

.application-building:focus-visible .application-building__focus-outline {
    opacity: 1;
}

.application-building__ground {
    fill: #020617;
    opacity: 0.74;
    stroke: #475569;
    stroke-width: 1;
}

.application-building__face,
.application-building__roof {
    stroke: #020617;
    stroke-linejoin: round;
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}

.application-building:hover .application-building__roof,
.application-building:focus-visible .application-building__roof {
    opacity: 1;
    stroke: #ffffff;
    stroke-width: 3;
}

.application-building__structure-line {
    fill: none;
    stroke: rgba(255, 255, 255, 0.74);
    stroke-width: 1.5;
}

.application-building__structure-line--muted {
    stroke: rgba(255, 255, 255, 0.3);
}

.application-building__label-plate {
    fill: #0f172a;
    stroke: #64748b;
    stroke-width: 1.5;
}

.application-building__label-plate--selected {
    fill: #ffffff;
    stroke: #dc2626;
    stroke-width: 3;
}

.application-building__selection-mark {
    fill: none;
    stroke: #dc2626;
    stroke-linecap: square;
    stroke-width: 2;
}

.application-building__name {
    fill: #f8fafc;
    font-weight: 900;
    letter-spacing: 0.025em;
}

.application-building--selected .application-building__name {
    fill: #7f1d1d;
}

.application-building__id {
    fill: #cbd5e1;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-weight: 700;
    letter-spacing: 0.08em;
}

.application-building--selected .application-building__id {
    fill: #991b1b;
}
</style>
