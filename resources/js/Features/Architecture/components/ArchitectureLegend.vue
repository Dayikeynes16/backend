<script setup>
import { computed } from 'vue';

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

const statusClasses = {
    implemented: 'border-emerald-600 bg-emerald-100 text-emerald-800',
    partial: 'border-amber-600 bg-amber-100 text-amber-900',
    pending: 'border-slate-500 bg-slate-200 text-slate-700',
    'in-review': 'border-blue-600 bg-blue-100 text-blue-800',
    issues: 'border-red-600 bg-red-100 text-red-800',
    'requires-review': 'border-orange-600 bg-orange-100 text-orange-900',
    unknown: 'border-gray-500 bg-gray-200 text-gray-700',
    'not-responsible': 'border-violet-600 bg-violet-100 text-violet-800',
};

const modeDescriptions = {
    dependencies: 'Qué necesita este componente para funcionar',
    data: 'Dónde se origina, persiste y consume la información',
    sync: 'Qué se conserva localmente y cuándo viaja al backend',
};

const currentModeDescription = computed(() => (
    modeDescriptions[props.mode] ?? modeDescriptions.dependencies
));

const statusPatterns = {
    implemented: { borderStyle: 'solid', transform: 'rotate(45deg)' },
    partial: { borderStyle: 'dashed', transform: 'rotate(45deg)' },
    pending: { borderStyle: 'dashed', transform: 'none' },
    'in-review': { borderStyle: 'double', transform: 'none' },
    issues: { borderStyle: 'double', transform: 'rotate(45deg)' },
    'requires-review': { borderStyle: 'dotted', transform: 'none' },
    unknown: { borderStyle: 'dotted', transform: 'rotate(45deg)' },
    'not-responsible': { borderStyle: 'dashed', transform: 'skewX(-14deg)' },
};

const connectionStyles = {
    http: { color: '#64748b', dash: 'none' },
    websocket: { color: '#0284c7', dash: '18px 4px' },
    polling: { color: '#d97706', dash: '7px 9px' },
    ipc: { color: '#9333ea', dash: '2px 5px' },
    'usb-serial': { color: '#ea580c', dash: '14px 3px' },
    mdns: { color: '#0f766e', dash: '1px 8px' },
    database: { color: '#7c3aed', dash: '2px 2px' },
    'sync-outbox': { color: '#16a34a', dash: '12px 6px' },
    cache: { color: '#ca8a04', dash: '4px 5px' },
    'external-link': { color: '#64748b', dash: '8px 8px' },
};

function statusClass(id) {
    return statusClasses[id] ?? statusClasses.unknown;
}

function connectionLabel(kind) {
    return kind.replaceAll('-', ' ');
}

function statusPattern(id) {
    return statusPatterns[id] ?? statusPatterns.unknown;
}

function connectionLineStyle(kind) {
    const style = connectionStyles[kind] ?? connectionStyles['external-link'];

    if (style.dash === 'none') return { backgroundColor: style.color };

    const [dash, gap] = style.dash.split(' ');
    return {
        backgroundImage: `repeating-linear-gradient(90deg, ${style.color} 0 ${dash}, transparent ${dash} calc(${dash} + ${gap}))`,
    };
}
</script>

<template>
    <section aria-labelledby="architecture-legend-title" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="border-b border-slate-200 pb-3">
            <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-red-700">Clave de lectura</p>
            <h2 id="architecture-legend-title" class="mt-1 text-base font-black text-slate-950">Leyenda del manifiesto</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">
                <strong class="text-slate-900">Modo activo:</strong> {{ currentModeDescription }}.
                El borde y la trama conservan el significado cuando el color no está disponible.
            </p>
        </div>

        <div class="mt-4 grid gap-6 xl:grid-cols-2">
            <div>
                <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">Estados</h3>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <li v-for="status in statuses" :key="status.id" class="flex gap-3 rounded-lg border border-slate-100 bg-slate-50 p-2.5">
                        <span
                            aria-hidden="true"
                            class="mt-1 h-3 w-3 shrink-0 border-2"
                            :class="statusClass(status.id)"
                            :style="statusPattern(status.id)"
                        />
                        <span>
                            <span class="block text-xs font-black text-slate-900">{{ status.label }}</span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-slate-500">{{ status.description }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div>
                <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">Conexiones presentes</h3>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <li v-for="kind in connectionTypes" :key="kind" class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                        <span
                            aria-hidden="true"
                            class="h-0.5 w-10 shrink-0"
                            :style="connectionLineStyle(kind)"
                        />
                        <span class="font-mono text-xs font-bold uppercase text-slate-700">{{ connectionLabel(kind) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
