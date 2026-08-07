<script setup>
import { computed } from 'vue';
import ArchitectureStatusPattern from './ArchitectureStatusPattern.vue';
import {
    connectionVisualToken,
    statusVisualToken,
} from '../lib/architectureVisualTokens.js';

const props = defineProps({
    statuses: {
        type: Array,
        required: true,
        validator: (value) => value.every((status) => (
            typeof status.id === 'string'
            && typeof status.label === 'string'
            && typeof status.description === 'string'
        )),
    },
    connectionTypes: {
        type: Array,
        required: true,
        validator: (value) => value.every((kind) => typeof kind === 'string'),
    },
    mode: {
        type: String,
        default: 'dependencies',
        validator: (value) => ['dependencies', 'data', 'sync'].includes(value),
    },
});

const modeDescriptions = {
    dependencies: 'Qué necesita este componente para funcionar',
    data: 'Dónde se origina, persiste y consume la información',
    sync: 'Qué se conserva localmente y cuándo viaja al backend',
};

const currentModeDescription = computed(() => (
    modeDescriptions[props.mode] ?? modeDescriptions.dependencies
));

function connectionLabel(kind) {
    return kind.replaceAll('-', ' ');
}

function statusToken(id) {
    return statusVisualToken(id);
}

function statusPatternId(id) {
    return `atlas-legend-status-${id.replace(/[^a-z0-9-]/gi, '-')}`;
}
</script>

<template>
    <section aria-labelledby="architecture-legend-title" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="border-b border-slate-200 pb-3">
            <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-red-700">Clave de lectura</p>
            <h2 id="architecture-legend-title" class="mt-1 text-base font-black text-slate-950">Leyenda del manifiesto</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-700">
                <strong class="text-slate-900">Modo activo:</strong> {{ currentModeDescription }}.
                El borde y la trama conservan el significado cuando el color no está disponible.
            </p>
        </div>

        <div class="mt-4 grid gap-6 xl:grid-cols-2">
            <div>
                <h3 class="text-xs font-black uppercase tracking-wide text-slate-700">Estados</h3>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <li v-for="status in statuses" :key="status.id" class="flex gap-3 rounded-lg border border-slate-100 bg-slate-50 p-2.5">
                        <svg
                            aria-hidden="true"
                            class="mt-0.5 h-5 w-5 shrink-0"
                            viewBox="0 0 20 20"
                        >
                            <defs v-if="statusToken(status.id).pattern !== 'solid'">
                                <ArchitectureStatusPattern
                                    :pattern-id="statusPatternId(status.id)"
                                    :token="statusToken(status.id)"
                                />
                            </defs>
                            <rect
                                x="2"
                                y="2"
                                width="16"
                                height="16"
                                rx="2"
                                :fill="statusToken(status.id).pattern === 'solid' ? statusToken(status.id).fill : `url(#${statusPatternId(status.id)})`"
                                :stroke="statusToken(status.id).stroke"
                                :stroke-dasharray="statusToken(status.id).dash || undefined"
                                stroke-width="2"
                            />
                        </svg>
                        <span>
                            <span class="block text-xs font-black text-slate-900">{{ status.label }}</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-slate-700">{{ status.description }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div>
                <h3 class="text-xs font-black uppercase tracking-wide text-slate-700">Conexiones presentes</h3>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <li v-for="kind in connectionTypes" :key="kind" class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <svg
                            aria-hidden="true"
                            class="h-3 w-12 shrink-0"
                            viewBox="0 0 48 12"
                        >
                            <line
                                x1="2"
                                y1="6"
                                x2="46"
                                y2="6"
                                :stroke="connectionVisualToken(kind).color"
                                :stroke-dasharray="connectionVisualToken(kind).dash || undefined"
                                :stroke-linecap="connectionVisualToken(kind).linecap"
                                stroke-width="2"
                            />
                        </svg>
                        <span class="font-mono text-xs font-bold uppercase text-slate-700">{{ connectionLabel(kind) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
