<script setup>
import { computed } from 'vue';

const props = defineProps({
    applications: {
        type: Array,
        required: true,
        validator: (value) => value.every((application) => (
            typeof application.id === 'string' && typeof application.name === 'string'
        )),
    },
    modules: {
        type: Array,
        required: true,
        validator: (value) => value.every((module) => (
            typeof module.id === 'string' && typeof module.applicationId === 'string'
        )),
    },
    statuses: {
        type: Array,
        required: true,
        validator: (value) => value.every((status) => (
            typeof status.id === 'string' && typeof status.label === 'string'
        )),
    },
    query: {
        type: String,
        default: '',
    },
});

const emit = defineEmits({
    'select-application': (id) => typeof id === 'string',
    'select-module': (id) => typeof id === 'string',
});

const statusesById = computed(() => new Map(
    props.statuses.map((status) => [status.id, status]),
));

const groups = computed(() => props.applications
    .map((application) => ({
        application,
        modules: props.modules.filter((module) => module.applicationId === application.id),
    }))
    .filter((group) => group.modules.length > 0));

const statusClasses = {
    implemented: 'border-emerald-300 bg-emerald-50 text-emerald-800',
    partial: 'border-amber-300 bg-amber-50 text-amber-900',
    pending: 'border-slate-300 bg-slate-100 text-slate-700',
    'in-review': 'border-blue-300 bg-blue-50 text-blue-800',
    issues: 'border-red-300 bg-red-50 text-red-800',
    'requires-review': 'border-orange-300 bg-orange-50 text-orange-900',
    unknown: 'border-gray-300 bg-gray-100 text-gray-700',
    'not-responsible': 'border-violet-300 bg-violet-50 text-violet-800',
};

function statusLabel(module) {
    return statusesById.value.get(module.status?.id)?.label ?? 'Sin estado';
}

function statusClass(module) {
    return statusClasses[module.status?.id] ?? statusClasses.unknown;
}

function internetLabel(module) {
    const requirements = module.internetRequirement ?? {};

    if (requirements.requiredForUse === true || requirements.requiredForCapture === true) {
        return 'Requiere conexión';
    }

    if (requirements.requiredForSync === true || requirements.requiredForFinalSync === true) {
        return 'Internet para sincronizar';
    }

    return 'Uso local confirmado';
}

function offlineLabel(module) {
    return module.offlineCapability?.supported
        ? `Offline: ${module.offlineCapability.scope ?? 'disponible'}`
        : 'Sin operación offline';
}
</script>

<template>
    <div class="space-y-5" aria-live="polite">
        <div v-if="groups.length === 0" role="status" class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-12 text-center">
            <p class="font-bold text-gray-900">No encontramos componentes</p>
            <p class="mt-1 text-sm text-gray-500">Prueba otra búsqueda o limpia los filtros.</p>
        </div>

        <template v-else>
            <section
                v-for="group in groups"
                :key="group.application.id"
                :aria-labelledby="`architecture-list-${group.application.id}`"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
            <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-950 px-5 py-4 text-white sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-red-300">{{ group.application.id }}</p>
                    <h2 :id="`architecture-list-${group.application.id}`" class="mt-1 text-lg font-black">
                        {{ group.application.name }}
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-300">{{ group.application.description }}</p>
                </div>
                <button
                    type="button"
                    class="min-h-11 shrink-0 rounded-lg border border-white/25 px-4 text-sm font-bold text-white outline-none transition hover:border-white hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                    :aria-label="`Explorar solo ${group.application.name}`"
                    @click="emit('select-application', group.application.id)"
                >
                    Abrir aplicación
                </button>
            </div>

            <ul class="divide-y divide-slate-200">
                <li v-for="module in group.modules" :key="module.id">
                    <button
                        type="button"
                        class="group grid min-h-28 w-full gap-3 px-5 py-4 text-left outline-none transition hover:bg-red-50/50 focus-visible:bg-red-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-red-600 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                        :aria-label="`Abrir detalle técnico de ${module.name}`"
                        @click="emit('select-module', module.id)"
                    >
                        <span class="min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ module.id }}</span>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-[11px] font-black uppercase tracking-wide"
                                    :class="statusClass(module)"
                                >
                                    <span aria-hidden="true" class="h-2 w-2 rotate-45 border border-current bg-current/20" />
                                    {{ statusLabel(module) }}
                                </span>
                            </span>
                            <span class="mt-2 block text-base font-black text-slate-950 group-hover:text-red-800">{{ module.name }}</span>
                            <span class="mt-1 block max-w-3xl text-sm leading-6 text-slate-600">{{ module.description }}</span>
                        </span>

                        <span class="flex flex-wrap gap-2 text-xs font-semibold text-slate-600 sm:max-w-56 sm:justify-end">
                            <span class="rounded-md border border-slate-200 bg-white px-2 py-1.5">{{ internetLabel(module) }}</span>
                            <span class="rounded-md border border-slate-200 bg-white px-2 py-1.5">{{ offlineLabel(module) }}</span>
                            <span aria-hidden="true" class="ml-1 self-center text-lg text-red-700">→</span>
                        </span>
                    </button>
                </li>
            </ul>
            </section>
        </template>

        <p v-if="query && groups.length > 0" class="text-right font-mono text-xs text-slate-500">
            {{ props.modules.length }} resultados para “{{ query }}”
        </p>
    </div>
</template>
