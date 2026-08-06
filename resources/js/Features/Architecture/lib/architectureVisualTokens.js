function freezeTokens(tokens) {
    return Object.freeze(Object.fromEntries(
        Object.entries(tokens).map(([id, token]) => [id, Object.freeze(token)]),
    ));
}

export const STATUS_VISUAL_TOKENS = freezeTokens({
    implemented: { fill: '#dcfce7', stroke: '#22c55e', pattern: 'solid', dash: '' },
    partial: { fill: '#fef3c7', stroke: '#f59e0b', pattern: 'diagonal', dash: '8 3' },
    pending: { fill: '#e2e8f0', stroke: '#94a3b8', pattern: 'horizontal', dash: '3 4' },
    'in-review': { fill: '#dbeafe', stroke: '#3b82f6', pattern: 'vertical', dash: '10 3' },
    issues: { fill: '#fee2e2', stroke: '#ef4444', pattern: 'cross', dash: '2 2' },
    'requires-review': { fill: '#ffedd5', stroke: '#f97316', pattern: 'dots', dash: '1 4' },
    unknown: { fill: '#e5e7eb', stroke: '#6b7280', pattern: 'dense', dash: '6 5' },
    'not-responsible': { fill: '#ede9fe', stroke: '#8b5cf6', pattern: 'wide', dash: '12 3 2 3' },
});

export const CONNECTION_VISUAL_TOKENS = freezeTokens({
    http: { dash: '', color: '#cbd5e1', linecap: 'square' },
    websocket: { dash: '18 4', color: '#38bdf8', linecap: 'round' },
    polling: { dash: '7 9', color: '#f59e0b', linecap: 'butt' },
    ipc: { dash: '2 5', color: '#c084fc', linecap: 'round' },
    'usb-serial': { dash: '14 3 2 3', color: '#fb923c', linecap: 'butt' },
    mdns: { dash: '1 8', color: '#2dd4bf', linecap: 'round' },
    database: { dash: '2 2', color: '#a78bfa', linecap: 'butt' },
    'sync-outbox': { dash: '12 6', color: '#22c55e', linecap: 'square' },
    cache: { dash: '4 5', color: '#eab308', linecap: 'butt' },
    'external-link': { dash: '8 8', color: '#94a3b8', linecap: 'square' },
});

export function statusVisualToken(statusId) {
    return STATUS_VISUAL_TOKENS[statusId] ?? STATUS_VISUAL_TOKENS.unknown;
}

export function connectionVisualToken(kind) {
    return CONNECTION_VISUAL_TOKENS[kind] ?? CONNECTION_VISUAL_TOKENS['external-link'];
}
