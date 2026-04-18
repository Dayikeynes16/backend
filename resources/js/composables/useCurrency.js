export function formatCurrency(value, currency = 'MXN') {
    if (value === null || value === undefined || isNaN(value)) return '—';
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(Number(value));
}

export function formatNumber(value, decimals = 0) {
    if (value === null || value === undefined || isNaN(value)) return '—';
    return new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Number(value));
}

export function formatPercent(value, decimals = 1) {
    if (value === null || value === undefined || isNaN(value)) return '—';
    return `${Number(value).toFixed(decimals)}%`;
}

export function formatDelta(delta) {
    if (delta === null || delta === undefined || isNaN(delta)) return null;
    const sign = delta > 0 ? '+' : '';
    return `${sign}${Number(delta).toFixed(1)}%`;
}
