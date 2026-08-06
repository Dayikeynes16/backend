import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

export const entityCollections = [
    'repositories', 'applications', 'modules', 'components', 'connections',
    'dataSources', 'endpoints', 'events', 'databaseTables', 'devices',
    'dependencies', 'permissions', 'featureFlags', 'sourceFiles', 'risks',
    'evidence',
];

const allowedConnectionKinds = new Set([
    'http', 'websocket', 'polling', 'ipc', 'usb-serial', 'mdns',
    'database', 'sync-outbox', 'cache', 'external-link',
]);

const inconclusiveStatuses = new Set(['requires-review', 'unknown']);

export function validateManifest(manifest) {
    const errors = [];
    const requiredRoots = ['metadata', 'statuses', ...entityCollections, 'visualLayout'];

    for (const key of requiredRoots) {
        if (!(key in manifest)) errors.push(`missing root collection: ${key}`);
    }

    if (manifest.metadata?.schemaVersion !== '1.0.0') {
        errors.push('metadata.schemaVersion must be 1.0.0');
    }

    const allEntities = new Map();
    for (const collection of entityCollections) {
        if (!Array.isArray(manifest[collection])) {
            errors.push(`${collection} must be an array`);
            continue;
        }

        for (const entity of manifest[collection]) {
            if (!entity?.id) {
                errors.push(`${collection} contains an entity without id`);
                continue;
            }
            if (allEntities.has(entity.id)) errors.push(`duplicate id: ${entity.id}`);
            allEntities.set(entity.id, { collection, entity });
        }
    }

    const statusIds = new Set((manifest.statuses ?? []).map((status) => status.id));
    const evidenceIds = new Set((manifest.evidence ?? []).map((entry) => entry.id));
    const requireEntity = (ownerId, field, referencedId) => {
        if (referencedId && !allEntities.has(referencedId)) {
            errors.push(`${ownerId}.${field} references missing id ${referencedId}`);
        }
    };

    for (const application of manifest.applications ?? []) {
        requireEntity(application.id, 'repositoryId', application.repositoryId);
    }

    for (const module of manifest.modules ?? []) {
        requireEntity(module.id, 'applicationId', module.applicationId);
        const statusId = module.status?.id;
        if (!statusIds.has(statusId)) errors.push(`${module.id} has unknown status ${statusId}`);

        const refs = module.status?.evidenceIds ?? [];
        if (!inconclusiveStatuses.has(statusId) && refs.length === 0) {
            errors.push(`${module.id} requires evidence for status ${statusId}`);
        }
        for (const evidenceId of refs) {
            if (!evidenceIds.has(evidenceId)) errors.push(`${module.id} references missing evidence ${evidenceId}`);
        }
        for (const field of [
            'sourceOfTruthIds', 'endpointIds', 'eventIds', 'dependencyIds',
            'sourceFileIds', 'riskIds', 'featureFlagIds', 'permissionIds',
            'databaseTableIds', 'componentIds', 'deviceIds',
        ]) {
            for (const id of module[field] ?? []) requireEntity(module.id, field, id);
        }
    }

    for (const connection of manifest.connections ?? []) {
        requireEntity(connection.id, 'fromId', connection.fromId);
        requireEntity(connection.id, 'toId', connection.toId);
        if (!allowedConnectionKinds.has(connection.kind)) {
            errors.push(`${connection.id} has unknown connection kind ${connection.kind}`);
        }
        for (const field of ['endpointIds', 'eventIds', 'evidenceIds']) {
            for (const id of connection[field] ?? []) requireEntity(connection.id, field, id);
        }
    }

    for (const endpoint of manifest.endpoints ?? []) {
        requireEntity(endpoint.id, 'applicationId', endpoint.applicationId);
        requireEntity(endpoint.id, 'sourceFileId', endpoint.sourceFileId);
    }

    for (const event of manifest.events ?? []) {
        requireEntity(event.id, 'applicationId', event.applicationId);
        for (const id of event.emitterIds ?? []) requireEntity(event.id, 'emitterIds', id);
        for (const id of event.listenerIds ?? []) requireEntity(event.id, 'listenerIds', id);
    }

    for (const file of manifest.sourceFiles ?? []) {
        requireEntity(file.id, 'repositoryId', file.repositoryId);
    }

    for (const entry of manifest.evidence ?? []) {
        requireEntity(entry.id, 'sourceFileId', entry.sourceFileId);
    }

    for (const risk of manifest.risks ?? []) {
        for (const id of risk.evidenceIds ?? []) requireEntity(risk.id, 'evidenceIds', id);
    }

    for (const building of manifest.visualLayout?.buildings ?? []) {
        requireEntity(`visual.building.${building.applicationId}`, 'applicationId', building.applicationId);
    }
    for (const room of manifest.visualLayout?.rooms ?? []) {
        requireEntity(`visual.room.${room.moduleId}`, 'moduleId', room.moduleId);
    }

    return errors;
}

async function main() {
    const manifestUrl = new URL('../resources/js/Features/Architecture/data/system-architecture.json', import.meta.url);
    const manifest = JSON.parse(await readFile(manifestUrl, 'utf8'));
    const errors = validateManifest(manifest);

    if (errors.length > 0) {
        console.error(errors.map((error) => `- ${error}`).join('\n'));
        process.exitCode = 1;
        return;
    }

    console.log(`Architecture manifest valid: ${manifest.applications.length} applications, ${manifest.modules.length} modules, ${manifest.connections.length} connections.`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await main();
}
