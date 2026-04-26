// Espejo de App\Services\SaleItemFormatter en JS. Mismo contrato.
//
// Usar en cualquier vista que renderice líneas de venta para asegurar
// coherencia entre filas nuevas (con presentation_snapshot, quantity_unit)
// y filas legacy (solo unit_type heredado).

const formatInt = (n) => Number.isInteger(n) ? String(n) : Number(n).toFixed(2);

const formatNumber = (n, unit) => {
    const x = Number(n);
    if (unit === 'kg' || unit === 'l') return x.toFixed(3);
    if (unit === 'g' || unit === 'ml') return Math.round(x).toString();
    return Number.isInteger(x) ? String(x) : x.toFixed(2);
};

const snapshot = (item) => {
    const raw = item?.presentation_snapshot;
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    if (typeof raw === 'string') {
        try { const p = JSON.parse(raw); return p && typeof p === 'object' ? p : null; }
        catch { return null; }
    }
    return null;
};

const effectiveUnit = (item) => String(item?.quantity_unit ?? item?.unit_type ?? '');

/** "Queso — medio queso (500 g)" o "Queso" según haya snapshot. */
export const displayName = (item) => {
    const s = snapshot(item);
    const name = String(item?.product_name ?? '');
    if (s && s.content && s.unit) {
        return `${name} (${formatNumber(s.content, s.unit)} ${s.unit})`;
    }
    return name;
};

/** "1.250 kg" / "× 2" / "3 pz" según semántica de la línea. */
export const displayQuantity = (item) => {
    const qty = Number(item?.quantity ?? 0);
    const unit = effectiveUnit(item);
    switch (unit) {
        case 'unit':           return `× ${formatInt(qty)}`;
        case 'kg':             return `${qty.toFixed(3)} kg`;
        case 'g':              return `${Math.round(qty)} g`;
        case 'piece':
        case 'cut':            return `${formatInt(qty)} pz`;
        default:               return `${formatNumber(qty, unit)} ${unit}`.trim();
    }
};

/** Modo efectivo: 'presentation' | 'weight' | 'piece' | 'unknown'. */
export const saleMode = (item) => {
    const explicit = item?.sale_mode_at_sale;
    if (explicit) return explicit;
    if (snapshot(item)) return 'presentation';
    const legacy = String(item?.unit_type ?? '');
    if (['kg', 'g', 'ml', 'l'].includes(legacy)) return 'weight';
    if (['piece', 'cut'].includes(legacy)) return 'piece';
    return 'unknown';
};

/** Para tickets compactos que no quieren paréntesis. "× 2 medio queso 500 g" */
export const compactLine = (item) => {
    const s = snapshot(item);
    const qty = displayQuantity(item);
    const name = String(item?.product_name ?? '');
    if (s && s.content && s.unit) {
        return `${qty} ${name} ${formatNumber(s.content, s.unit)} ${s.unit}`.trim();
    }
    return `${qty} ${name}`.trim();
};
