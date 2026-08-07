<script setup>
const props = defineProps({
    statuses: {
        type: Array,
        required: true,
        validator: (value) => value.every((status) => (
            typeof status.id === 'string' && typeof status.label === 'string'
        )),
    },
    connectionKinds: {
        type: Array,
        required: true,
        validator: (value) => value.every((kind) => typeof kind === 'string'),
    },
});

const query = defineModel('query', { type: String, default: '' });
const statusId = defineModel('statusId', { type: String, default: '' });
const connectionKind = defineModel('connectionKind', { type: String, default: '' });
const mode = defineModel('mode', {
    type: String,
    default: 'dependencies',
    validator: (value) => ['dependencies', 'data', 'sync'].includes(value),
});
const view = defineModel('view', {
    type: String,
    default: 'map',
    validator: (value) => ['map', 'list'].includes(value),
});

const emit = defineEmits({
    clear: () => true,
});

const modes = [
    { id: 'dependencies', label: 'Dependencias' },
    { id: 'data', label: 'Flujo de datos' },
    { id: 'sync', label: 'Sincronización' },
];

function formatConnectionKind(kind) {
    return kind.replaceAll('-', ' ');
}
</script>

<template>
    <section aria-labelledby="architecture-tools-title" class="border-b border-slate-200 bg-slate-50/90 px-4 py-4 sm:px-6">
        <div class="mb-3 flex items-center justify-between gap-4">
            <div>
                <h2 id="architecture-tools-title" class="text-xs font-black uppercase tracking-[0.18em] text-red-700">
                    Mesa de inspección
                </h2>
                <p class="mt-1 text-sm text-slate-700">Busca evidencia y acota el plano técnico.</p>
            </div>
            <button
                type="button"
                class="min-h-11 shrink-0 rounded-lg border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 outline-none transition hover:border-red-300 hover:text-red-700 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                @click="emit('clear')"
            >
                Limpiar filtros
            </button>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1.5fr)_minmax(10rem,1fr)_minmax(10rem,1fr)]">
            <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                Buscar en la arquitectura
                <input
                    v-model="query"
                    type="search"
                    placeholder="Módulo, endpoint, evento o archivo"
                    class="mt-1 min-h-11 w-full rounded-lg border-slate-300 bg-white text-sm font-normal normal-case tracking-normal text-slate-950 placeholder:text-slate-700 focus:border-red-500 focus:ring-red-500"
                >
            </label>

            <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                Estado
                <select
                    v-model="statusId"
                    class="mt-1 min-h-11 w-full rounded-lg border-slate-300 bg-white text-sm font-normal normal-case tracking-normal text-slate-950 focus:border-red-500 focus:ring-red-500"
                >
                    <option value="">Todos los estados</option>
                    <option v-for="status in props.statuses" :key="status.id" :value="status.id">
                        {{ status.label }}
                    </option>
                </select>
            </label>

            <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                Tipo de conexión
                <select
                    v-model="connectionKind"
                    class="mt-1 min-h-11 w-full rounded-lg border-slate-300 bg-white text-sm font-normal normal-case tracking-normal text-slate-950 focus:border-red-500 focus:ring-red-500"
                >
                    <option value="">Todas las conexiones</option>
                    <option v-for="kind in props.connectionKinds" :key="kind" :value="kind">
                        {{ formatConnectionKind(kind) }}
                    </option>
                </select>
            </label>
        </div>

        <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 lg:flex-row lg:items-center lg:justify-between">
            <div role="group" aria-labelledby="architecture-mode-label">
                <p id="architecture-mode-label" class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-700">Lectura de conexiones</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="item in modes"
                        :key="item.id"
                        type="button"
                        class="min-h-11 rounded-lg border px-3 text-sm font-bold outline-none transition focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                        :class="mode === item.id ? 'border-red-700 bg-red-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-red-300 hover:text-red-700'"
                        :aria-pressed="mode === item.id"
                        @click="mode = item.id"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </div>

            <div role="group" aria-labelledby="architecture-view-label">
                <p id="architecture-view-label" class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-700">Representación</p>
                <div class="inline-flex rounded-lg border border-slate-300 bg-white p-1">
                    <button
                        type="button"
                        class="min-h-11 rounded-md px-4 text-sm font-bold outline-none transition focus-visible:ring-2 focus-visible:ring-red-600"
                        :class="view === 'map' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                        :aria-pressed="view === 'map'"
                        @click="view = 'map'"
                    >
                        Mapa
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-md px-4 text-sm font-bold outline-none transition focus-visible:ring-2 focus-visible:ring-red-600"
                        :class="view === 'list' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                        :aria-pressed="view === 'list'"
                        @click="view = 'list'"
                    >
                        Lista
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
