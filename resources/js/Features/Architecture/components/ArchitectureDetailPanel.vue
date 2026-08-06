<script setup>
import { computed } from 'vue';
import { buildSourceUrl } from '../lib/architectureGraph.js';

const props = defineProps({
    entity: {
        type: Object,
        default: null,
        validator: (value) => value === null || typeof value.id === 'string',
    },
    related: {
        type: Array,
        default: () => [],
        validator: (value) => value.every((connection) => (
            typeof connection.id === 'string'
            && typeof connection.fromId === 'string'
            && typeof connection.toId === 'string'
        )),
    },
    manifest: {
        type: Object,
        required: true,
        validator: (value) => Array.isArray(value.applications) && Array.isArray(value.modules),
    },
    open: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits({
    close: () => true,
    'select-related': (id) => typeof id === 'string',
});

function byId(collection = []) {
    return new Map(collection.map((item) => [item.id, item]));
}

const applicationsById = computed(() => byId(props.manifest.applications));
const statusesById = computed(() => byId(props.manifest.statuses));
const repositoriesById = computed(() => byId(props.manifest.repositories));
const dataSourcesById = computed(() => byId(props.manifest.dataSources));
const endpointsById = computed(() => byId(props.manifest.endpoints));
const eventsById = computed(() => byId(props.manifest.events));
const tablesById = computed(() => byId(props.manifest.databaseTables));
const sourceFilesById = computed(() => byId(props.manifest.sourceFiles));
const risksById = computed(() => byId(props.manifest.risks));
const dependenciesById = computed(() => byId(props.manifest.dependencies));
const permissionsById = computed(() => byId(props.manifest.permissions));
const featureFlagsById = computed(() => byId(props.manifest.featureFlags));
const componentsById = computed(() => byId(props.manifest.components));
const devicesById = computed(() => byId(props.manifest.devices));
const evidenceById = computed(() => byId(props.manifest.evidence));

const allEntitiesById = computed(() => byId([
    ...props.manifest.applications,
    ...props.manifest.modules,
    ...props.manifest.components,
    ...props.manifest.dataSources,
    ...props.manifest.endpoints,
    ...props.manifest.events,
    ...props.manifest.databaseTables,
    ...props.manifest.devices,
    ...props.manifest.dependencies,
    ...props.manifest.permissions,
    ...props.manifest.featureFlags,
    ...props.manifest.sourceFiles,
    ...props.manifest.risks,
]));

const selectableEntityIds = computed(() => new Set([
    ...props.manifest.applications.map((application) => application.id),
    ...props.manifest.modules.map((module) => module.id),
]));

function resolve(ids, index) {
    return (ids ?? []).map((id) => index.value.get(id)).filter(Boolean);
}

const responsibleApplication = computed(() => (
    applicationsById.value.get(props.entity?.responsibleApplicationId) ?? null
));
const status = computed(() => statusesById.value.get(props.entity?.status?.id) ?? null);
const dataSources = computed(() => resolve(props.entity?.sourceOfTruthIds, dataSourcesById));
const endpoints = computed(() => resolve(props.entity?.endpointIds, endpointsById));
const events = computed(() => resolve(props.entity?.eventIds, eventsById));
const databaseTables = computed(() => resolve(props.entity?.databaseTableIds, tablesById));
const risks = computed(() => resolve(props.entity?.riskIds, risksById));
const dependencies = computed(() => resolve(props.entity?.dependencyIds, dependenciesById));
const permissions = computed(() => resolve(props.entity?.permissionIds, permissionsById));
const featureFlags = computed(() => resolve(props.entity?.featureFlagIds, featureFlagsById));
const components = computed(() => resolve(props.entity?.componentIds, componentsById));
const devices = computed(() => resolve(props.entity?.deviceIds, devicesById));

const sourceFiles = computed(() => resolve(props.entity?.sourceFileIds, sourceFilesById).map((sourceFile) => {
    const repository = repositoriesById.value.get(sourceFile.repositoryId);

    return {
        ...sourceFile,
        repository,
        url: repository ? buildSourceUrl(repository, sourceFile) : null,
    };
}));

const evidence = computed(() => {
    const evidenceIds = new Set(props.entity?.status?.evidenceIds ?? []);
    const sourceIds = new Set(props.entity?.sourceFileIds ?? []);

    for (const risk of risks.value) {
        for (const id of risk.evidenceIds ?? []) evidenceIds.add(id);
    }

    for (const item of props.manifest.evidence) {
        if (sourceIds.has(item.sourceFileId)) evidenceIds.add(item.id);
    }

    return [...evidenceIds].map((id) => evidenceById.value.get(id)).filter(Boolean);
});

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

const currentStatusClass = computed(() => (
    statusClasses[props.entity?.status?.id] ?? statusClasses.unknown
));

function readableKey(value) {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('-', ' ')
        .toLowerCase();
}

function readableValue(value) {
    if (typeof value === 'boolean') return value ? 'Sí' : 'No';

    return String(value);
}

const relatedConnections = computed(() => props.related.map((connection) => {
    const targetId = connection.fromId === props.entity?.id
        ? connection.toId
        : connection.fromId;
    const target = allEntitiesById.value.get(targetId) ?? { id: targetId, name: targetId };

    return {
        connection,
        target,
        navigable: selectableEntityIds.value.has(targetId),
    };
}));

function entityLabels(ids) {
    return (ids ?? [])
        .map((id) => allEntitiesById.value.get(id)?.name ?? id)
        .join(' · ');
}
</script>

<template>
    <aside
        v-if="open && entity"
        aria-labelledby="architecture-detail-title"
        class="architecture-detail-panel overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-xl shadow-slate-900/10"
    >
        <header class="relative border-b border-slate-700 bg-slate-950 px-5 py-5 text-white">
            <div aria-hidden="true" class="absolute inset-y-0 left-0 w-1 bg-red-600" />
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-red-300">{{ entity.id }}</p>
                    <h2 id="architecture-detail-title" class="mt-2 text-xl font-black leading-tight">{{ entity.name }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ entity.description }}</p>
                </div>
                <button
                    type="button"
                    aria-label="Cerrar detalle"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/25 text-xl font-light text-white outline-none transition hover:border-white hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-white"
                    @click="emit('close')"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </header>

        <div class="architecture-detail-panel__body space-y-6 px-5 py-5">
            <section aria-labelledby="detail-verification-title">
                <h3 id="detail-verification-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Verificación</h3>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div class="col-span-2">
                        <span class="inline-flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs font-black uppercase tracking-wide" :class="currentStatusClass">
                            <span aria-hidden="true" class="h-2 w-2 rotate-45 border border-current bg-current/20" />
                            {{ status?.label ?? 'Sin estado' }}
                        </span>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-bold text-slate-500">Determinación</p>
                        <p class="mt-1 font-semibold capitalize text-slate-900">{{ readableKey(entity.status?.determination ?? 'unknown') }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-bold text-slate-500">Confianza</p>
                        <p class="mt-1 font-semibold capitalize text-slate-900">{{ readableKey(entity.status?.confidence ?? 'unknown') }}</p>
                    </div>
                    <div class="col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs font-bold text-slate-500">Verificado el</p>
                        <p class="mt-1 font-mono text-sm text-slate-900">{{ manifest.metadata.verifiedAt }}</p>
                    </div>
                </div>
            </section>

            <section v-if="responsibleApplication || dataSources.length" aria-labelledby="detail-responsibility-title">
                <h3 id="detail-responsibility-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Responsabilidad y autoridad</h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div v-if="responsibleApplication">
                        <dt class="font-bold text-slate-500">Aplicación responsable</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ responsibleApplication.name }}</dd>
                    </div>
                    <div v-if="dataSources.length">
                        <dt class="font-bold text-slate-500">Fuentes de verdad</dt>
                        <dd class="mt-2 space-y-2">
                            <div v-for="source in dataSources" :key="source.id" class="rounded-lg border-l-2 border-red-600 bg-slate-50 px-3 py-2">
                                <p class="font-semibold text-slate-950">{{ source.name }}</p>
                                <p v-if="source.authorityScope" class="mt-1 text-xs leading-5 text-slate-600">{{ source.authorityScope }}</p>
                            </div>
                        </dd>
                    </div>
                </dl>
            </section>

            <section v-if="entity.internetRequirement || entity.offlineCapability || entity.syncProfile" aria-labelledby="detail-connectivity-title">
                <h3 id="detail-connectivity-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Internet, offline y sincronización</h3>
                <dl class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200 text-sm">
                    <div v-for="(value, key) in entity.internetRequirement" :key="`internet-${key}`" class="flex justify-between gap-4 px-3 py-2.5">
                        <dt class="text-slate-600">{{ readableKey(key) }}</dt>
                        <dd class="font-bold text-slate-950">{{ readableValue(value) }}</dd>
                    </div>
                    <div v-for="(value, key) in entity.offlineCapability" :key="`offline-${key}`" class="flex justify-between gap-4 px-3 py-2.5">
                        <dt class="text-slate-600">Offline: {{ readableKey(key) }}</dt>
                        <dd class="font-bold text-slate-950">{{ readableValue(value) }}</dd>
                    </div>
                    <div v-for="(value, key) in entity.syncProfile" :key="`sync-${key}`" class="flex justify-between gap-4 px-3 py-2.5">
                        <dt class="text-slate-600">Sync: {{ readableKey(key) }}</dt>
                        <dd class="font-bold text-slate-950">{{ readableValue(value) }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="entity.implementedCapabilities?.length" aria-labelledby="detail-implemented-title">
                <h3 id="detail-implemented-title" class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">Capacidades implementadas</h3>
                <ul class="mt-3 space-y-2 text-sm text-slate-700">
                    <li v-for="capability in entity.implementedCapabilities" :key="capability" class="flex gap-2">
                        <span aria-hidden="true" class="mt-1 h-2.5 w-2.5 shrink-0 rotate-45 border border-emerald-700 bg-emerald-100" />
                        {{ capability }}
                    </li>
                </ul>
            </section>

            <section v-if="entity.pendingCapabilities?.length" aria-labelledby="detail-pending-title">
                <h3 id="detail-pending-title" class="text-xs font-black uppercase tracking-[0.16em] text-amber-700">Pendiente o incompleto</h3>
                <ul class="mt-3 space-y-2 text-sm text-slate-700">
                    <li v-for="capability in entity.pendingCapabilities" :key="capability" class="flex gap-2">
                        <span aria-hidden="true" class="mt-1 h-2.5 w-2.5 shrink-0 border border-amber-700 bg-amber-100" />
                        {{ capability }}
                    </li>
                </ul>
            </section>

            <section v-if="endpoints.length" aria-labelledby="detail-endpoints-title">
                <h3 id="detail-endpoints-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Endpoints</h3>
                <ul class="mt-3 space-y-2">
                    <li v-for="endpoint in endpoints" :key="endpoint.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-sm font-bold text-slate-950">{{ endpoint.name }}</p>
                        <p class="mt-1 break-all font-mono text-xs text-red-800">{{ endpoint.method }} {{ endpoint.path }}</p>
                        <p v-if="endpoint.auth" class="mt-1 text-xs text-slate-500">{{ endpoint.auth }}</p>
                    </li>
                </ul>
            </section>

            <section v-if="events.length" aria-labelledby="detail-events-title">
                <h3 id="detail-events-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Eventos</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-for="event in events" :key="event.id" class="rounded-lg border border-slate-200 px-3 py-2.5">
                        <p class="font-bold text-slate-950">{{ event.name }}</p>
                        <p v-if="event.channel" class="mt-1 break-all font-mono text-xs text-slate-500">{{ event.channel }}</p>
                        <p v-if="event.delivery" class="mt-1 text-xs text-slate-500">Entrega: {{ event.delivery }}</p>
                        <p v-if="event.emitterIds?.length" class="mt-1 text-xs text-slate-500">Emiten: {{ entityLabels(event.emitterIds) }}</p>
                        <p v-if="event.listenerIds?.length" class="mt-1 text-xs text-slate-500">Escuchan: {{ entityLabels(event.listenerIds) }}</p>
                    </li>
                </ul>
            </section>

            <section v-if="databaseTables.length" aria-labelledby="detail-tables-title">
                <h3 id="detail-tables-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Tablas y datos</h3>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li v-for="table in databaseTables" :key="table.id" class="rounded-md border border-slate-300 bg-slate-50 px-2 py-1.5 font-mono text-xs text-slate-700">
                        {{ table.name }}
                    </li>
                </ul>
            </section>

            <section v-if="components.length || dependencies.length || devices.length" aria-labelledby="detail-dependencies-title">
                <h3 id="detail-dependencies-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Componentes y dependencias</h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div v-if="components.length">
                        <dt class="font-bold text-slate-500">Componentes</dt>
                        <dd class="mt-1 text-slate-800">{{ components.map((item) => item.name).join(' · ') }}</dd>
                    </div>
                    <div v-if="dependencies.length">
                        <dt class="font-bold text-slate-500">Dependencias</dt>
                        <dd class="mt-1 text-slate-800">{{ dependencies.map((item) => item.name).join(' · ') }}</dd>
                    </div>
                    <div v-if="devices.length">
                        <dt class="font-bold text-slate-500">Dispositivos</dt>
                        <dd class="mt-1 text-slate-800">{{ devices.map((item) => item.name).join(' · ') }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="permissions.length || featureFlags.length" aria-labelledby="detail-controls-title">
                <h3 id="detail-controls-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Controles de disponibilidad</h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div v-if="permissions.length">
                        <dt class="font-bold text-slate-500">Roles y permisos</dt>
                        <dd class="mt-1 text-slate-800">{{ permissions.map((item) => item.name).join(' · ') }}</dd>
                    </div>
                    <div v-if="featureFlags.length">
                        <dt class="font-bold text-slate-500">Feature flags</dt>
                        <dd class="mt-2 space-y-1">
                            <div v-for="flag in featureFlags" :key="flag.id" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <p class="text-sm font-semibold text-slate-900">{{ flag.name }}</p>
                                <p class="mt-1 font-mono text-xs text-slate-600">
                                    {{ flag.key }} · default {{ flag.enabledByDefault ? 'activo' : 'inactivo' }}
                                </p>
                            </div>
                        </dd>
                    </div>
                </dl>
            </section>

            <section v-if="sourceFiles.length" aria-labelledby="detail-files-title">
                <h3 id="detail-files-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Archivos relevantes</h3>
                <ul class="mt-3 space-y-2">
                    <li v-for="file in sourceFiles" :key="file.id">
                        <a
                            v-if="file.url"
                            :href="file.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block min-h-11 rounded-lg border border-slate-200 px-3 py-2 outline-none transition hover:border-red-300 hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                        >
                            <span class="block break-all font-mono text-xs font-bold text-red-800">{{ file.path }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ file.repository?.name }} · {{ file.role }}</span>
                        </a>
                    </li>
                </ul>
            </section>

            <section v-if="relatedConnections.length" aria-labelledby="detail-related-title">
                <h3 id="detail-related-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Conexiones visibles</h3>
                <ul class="mt-3 space-y-2">
                    <li v-for="item in relatedConnections" :key="item.connection.id">
                        <button
                            v-if="item.navigable"
                            type="button"
                            class="min-h-11 w-full rounded-lg border border-slate-200 px-3 py-2 text-left outline-none transition hover:border-red-300 hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                            @click="emit('select-related', item.target.id)"
                        >
                            <span class="block text-sm font-bold text-slate-900">{{ item.target.name }}</span>
                            <span class="mt-1 block font-mono text-xs text-slate-500">{{ item.connection.kind }} · {{ item.connection.direction }}</span>
                        </button>
                        <div v-else class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <p class="text-sm font-bold text-slate-900">{{ item.target.name }}</p>
                            <p class="mt-1 font-mono text-xs text-slate-500">{{ item.connection.kind }} · {{ item.connection.direction }}</p>
                            <p class="mt-1 text-xs text-slate-500">Referencia técnica no navegable en esta vista.</p>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="risks.length" aria-labelledby="detail-risks-title">
                <h3 id="detail-risks-title" class="text-xs font-black uppercase tracking-[0.16em] text-red-700">Riesgos detectados</h3>
                <ul class="mt-3 space-y-2">
                    <li v-for="risk in risks" :key="risk.id" class="rounded-lg border border-red-200 bg-red-50 p-3">
                        <p class="text-sm font-bold text-red-950">{{ risk.name }}</p>
                        <p class="mt-1 text-xs leading-5 text-red-900/80">{{ risk.description }}</p>
                    </li>
                </ul>
            </section>

            <section v-if="evidence.length" aria-labelledby="detail-evidence-title">
                <h3 id="detail-evidence-title" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Evidencia disponible</h3>
                <ol class="mt-3 space-y-3 border-l border-slate-300 pl-4">
                    <li v-for="item in evidence" :key="item.id" class="relative text-sm">
                        <span aria-hidden="true" class="absolute -left-[1.19rem] top-1.5 h-2 w-2 rotate-45 bg-red-600" />
                        <p class="font-bold text-slate-900">{{ item.symbol }}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ item.summary }}</p>
                        <p class="mt-1 break-all font-mono text-[11px] text-slate-400">{{ item.commit }}</p>
                    </li>
                </ol>
            </section>
        </div>
    </aside>
</template>

<style scoped>
@media (min-width: 112rem) {
    .architecture-detail-panel {
        position: sticky;
        top: 1rem;
    }

    .architecture-detail-panel__body {
        max-height: calc(100vh - 8rem);
        overflow-y: auto;
    }
}
</style>
