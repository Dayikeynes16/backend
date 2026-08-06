<script setup>
defineProps({
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

function statusClass(id) {
    return statusClasses[id] ?? statusClasses.unknown;
}

function connectionLabel(kind) {
    return kind.replaceAll('-', ' ');
}

function connectionLineClass(kind) {
    if (['polling', 'mdns'].includes(kind)) return 'border-dashed border-amber-600';
    if (kind === 'sync-outbox') return 'border-double border-blue-700';
    if (kind === 'websocket') return 'border-red-600';

    return 'border-slate-500';
}
</script>

<template>
    <section aria-labelledby="architecture-legend-title" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="border-b border-slate-200 pb-3">
            <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-red-700">Clave de lectura</p>
            <h2 id="architecture-legend-title" class="mt-1 text-base font-black text-slate-950">Leyenda del manifiesto</h2>
        </div>

        <div class="mt-4 grid gap-6 xl:grid-cols-2">
            <div>
                <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">Estados</h3>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <li v-for="status in statuses" :key="status.id" class="flex gap-3 rounded-lg border border-slate-100 bg-slate-50 p-2.5">
                        <span aria-hidden="true" class="mt-1 h-3 w-3 shrink-0 rotate-45 border" :class="statusClass(status.id)" />
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
                        <span aria-hidden="true" class="w-10 shrink-0 border-t-2" :class="connectionLineClass(kind)" />
                        <span class="font-mono text-xs font-bold uppercase text-slate-700">{{ connectionLabel(kind) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
