/**
 * Formats a Date object as YYYY-MM-DD using the **local** timezone.
 *
 * Never use `date.toISOString().split('T')[0]` — that converts to UTC first,
 * which shifts the date forward after 6 PM in Mexico (UTC-6).
 */
export function toLocalDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** Today's date in YYYY-MM-DD, local timezone. */
export function localToday() {
    return toLocalDate(new Date());
}
