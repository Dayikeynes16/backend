// Helpers de presentación de rangos de fecha. Reutilizable en cualquier
// vista que muestre un rango aplicado: Métricas, historial, cortes, etc.

const PRESET_LABELS = {
    today: 'Hoy',
    yesterday: 'Ayer',
    last_7_days: 'Últimos 7 días',
    this_month: 'Este mes',
    last_month: 'Mes pasado',
    this_year: 'Este año',
};

const PRESET_LABELS_SHORT = {
    today: 'Hoy',
    yesterday: 'Ayer',
    last_7_days: '7 días',
    this_month: 'Este mes',
    last_month: 'Mes pasado',
    this_year: 'Este año',
};

const formatShortDate = (d) =>
    d instanceof Date && !isNaN(d)
        ? d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })
        : '';

const formatLongDate = (d) =>
    d instanceof Date && !isNaN(d)
        ? d.toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
        : '';

/**
 * Etiqueta legible para "Mostrando: X". Se alimenta del composable
 * `useMetricsFilters` (filters.preset, filters.isCustom, filters.from, filters.to).
 */
export function formatRangeLabel(filters) {
    if (!filters?.isCustom?.value) {
        const preset = filters?.preset?.value;
        const presetLabel = PRESET_LABELS[preset] || preset || '—';
        // Para "Hoy" y "Ayer" agregamos la fecha exacta para no dejar al usuario adivinando.
        if (preset === 'today') {
            return `${presetLabel} · ${formatLongDate(new Date())}`;
        }
        if (preset === 'yesterday') {
            const y = new Date();
            y.setDate(y.getDate() - 1);
            return `${presetLabel} · ${formatLongDate(y)}`;
        }
        return presetLabel;
    }

    const fromVal = filters.from?.value;
    const toVal = filters.to?.value;
    if (!fromVal || !toVal) return 'Personalizado';

    const from = new Date(fromVal);
    const to = new Date(toVal);
    if (isNaN(from) || isNaN(to)) return 'Personalizado';

    const days = Math.floor((to.getTime() - from.getTime()) / (24 * 60 * 60 * 1000)) + 1;
    const dayWord = days === 1 ? 'día' : 'días';

    return `Personalizado · ${formatShortDate(from)} → ${formatShortDate(to)} · ${days} ${dayWord}`;
}

export const presetLabels = PRESET_LABELS;
export const presetLabelsShort = PRESET_LABELS_SHORT;
export { formatShortDate, formatLongDate };

/**
 * Formatea un rango {from, to} a "1–28 abr 2026" o "1 abr – 3 may 2026".
 * Acepta strings ISO YYYY-MM-DD. Útil para el header y subtítulos de Métricas.
 */
export function formatAbsoluteRange(from, to) {
    if (!from || !to) return '—';
    const a = new Date(from + 'T12:00:00');
    const b = new Date(to + 'T12:00:00');
    if (isNaN(a) || isNaN(b)) return '—';

    if (from === to) {
        return a.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    const sameMonth = a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
    const sameYear = a.getFullYear() === b.getFullYear();

    if (sameMonth) {
        // "1–28 abr 2026"
        const monthYear = a.toLocaleDateString('es-MX', { month: 'short', year: 'numeric' });
        return `${a.getDate()}–${b.getDate()} ${monthYear}`;
    }
    if (sameYear) {
        // "1 abr – 3 may 2026"
        const startStr = a.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
        const endStr = b.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
        return `${startStr} – ${endStr}`;
    }
    // "28 dic 2025 – 5 ene 2026"
    return `${formatShortDate(a)} – ${formatShortDate(b)}`;
}
