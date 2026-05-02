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

/**
 * Peso/contenido real efectivamente vendido. Espejo de
 * App\Support\SaleItemMath::realContent.
 *
 *   1 presentación de 500 g  → { amount: 0.5, unit: 'kg', kind: 'weight' }
 *   2 presentaciones de 5 kg → { amount: 10, unit: 'kg', kind: 'weight' }
 *   1.250 kg libres          → { amount: 1.25, unit: 'kg', kind: 'weight' }
 *   3 piezas / paquete       → { amount: 3, unit: 'piece', kind: 'piece' }
 *   null si la línea legacy no es interpretable.
 */
export const realContent = (item) => {
    const qty = Number(item?.quantity ?? 0);
    const s = snapshot(item);
    if (s && s.unit) {
        const content = Number(s.content ?? 0);
        switch (s.unit) {
            case 'kg':
            case 'l':
                return { amount: round3(qty * content), unit: s.unit, kind: 'weight' };
            case 'g':
                return { amount: round3((qty * content) / 1000), unit: 'kg', kind: 'weight' };
            case 'ml':
                return { amount: round3((qty * content) / 1000), unit: 'l', kind: 'weight' };
            default:
                return { amount: qty, unit: 'piece', kind: 'piece' };
        }
    }
    const unit = effectiveUnit(item);
    switch (unit) {
        case 'kg':
        case 'l':
            return { amount: round3(qty), unit, kind: 'weight' };
        case 'g':
            return { amount: round3(qty / 1000), unit: 'kg', kind: 'weight' };
        case 'ml':
            return { amount: round3(qty / 1000), unit: 'l', kind: 'weight' };
        case 'piece':
        case 'cut':
        case 'unit':
            return { amount: qty, unit: 'piece', kind: 'piece' };
        default:
            return null;
    }
};

const round3 = (n) => Math.round(Number(n) * 1000) / 1000;

/**
 * Texto legible del peso real vendido. Vacío si la línea no es presentación
 * con peso/volumen (no hay nada extra que mostrar).
 *
 *   2 medios quesos → "1.000 kg"
 *   1 presentación de 5 kg → "5.000 kg"
 *   2 piezas → ""  (en piezas no hay equivalencia útil)
 *   1.250 kg libres → ""  (la cantidad ya es legible directamente)
 */
export const realContentDisplay = (item) => {
    if (!snapshot(item)) return '';
    const r = realContent(item);
    if (!r || r.kind !== 'weight') return '';
    return `${formatNumber(r.amount, r.unit)} ${r.unit}`;
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
