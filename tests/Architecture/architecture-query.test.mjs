import assert from 'node:assert/strict';
import test from 'node:test';
import { parseArchitectureQuery, serializeArchitectureQuery } from '../../resources/js/Features/Architecture/lib/architectureQuery.js';

const graph = {
    applicationsById: new Map([
        ['app.hub', { id: 'app.hub' }],
        ['app.saas', { id: 'app.saas' }],
    ]),
    modulesById: new Map([
        ['hub.sync.scale-sales', { id: 'hub.sync.scale-sales', applicationId: 'app.hub' }],
        ['saas.sales.workbench', { id: 'saas.sales.workbench', applicationId: 'app.saas' }],
    ]),
};

test('parses a valid deep link', () => {
    assert.deepEqual(
        parseArchitectureQuery('?app=app.hub&module=hub.sync.scale-sales&mode=sync&view=list', graph),
        { applicationId: 'app.hub', moduleId: 'hub.sync.scale-sales', mode: 'sync', view: 'list' },
    );
});

test('drops unknown ids, invalid enums and a module from another application', () => {
    assert.deepEqual(parseArchitectureQuery('?app=missing&module=hub.sync.scale-sales&mode=broken&view=x', graph), {
        applicationId: null,
        moduleId: null,
        mode: 'dependencies',
        view: 'map',
    });
    assert.deepEqual(parseArchitectureQuery('?app=app.hub&module=saas.sales.workbench', graph), {
        applicationId: 'app.hub',
        moduleId: null,
        mode: 'dependencies',
        view: 'map',
    });
});

test('serializes only non-default defined values in deterministic order', () => {
    assert.equal(serializeArchitectureQuery({
        applicationId: 'app.hub',
        moduleId: null,
        mode: 'dependencies',
        view: 'map',
    }), '?app=app.hub');
    assert.equal(serializeArchitectureQuery({
        applicationId: 'app.hub',
        moduleId: 'hub.sync.scale-sales',
        mode: 'sync',
        view: 'list',
        ignored: undefined,
    }), '?app=app.hub&module=hub.sync.scale-sales&mode=sync&view=list');
    assert.equal(serializeArchitectureQuery({ applicationId: null, moduleId: undefined, mode: 'dependencies', view: 'map' }), '');
});
