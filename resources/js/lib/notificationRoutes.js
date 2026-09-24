/**
 * Adónde lleva cada aviso en la web. Una tabla y no un `if` por componente,
 * igual que en el hub: cuando la nube añada un aviso nuevo, se añade una fila
 * aquí y la isla ya sabe qué hacer.
 *
 * El `type` es el del payload guardado (`sale.cancellation.requested`), NO el
 * que trae el evento de Reverb: ese viene pisado por el nombre de clase, y por
 * eso un `App\Notifications\...` no tiene destino.
 *
 * Tres formas de fila:
 *  - `admin` + `roles`: sólo esos roles tienen adónde ir (la solicitud de
 *    cancelación sólo la resuelve el admin de sucursal).
 *  - `byRole`: cada rol tiene su propia pantalla; un rol sin fila, ninguna.
 *  - `any`: la misma para cualquier rol.
 */
const DEVICES = {
    'admin-empresa': 'empresa.devices.index',
    'admin-sucursal': 'sucursal.devices.index',
    cajero: 'caja.devices.index',
};

const WORKBENCH = { cajero: 'caja.workbench', 'admin-sucursal': 'sucursal.workbench' };

const TABLE = {
    'sale.cancellation.requested': { admin: 'sucursal.cancelaciones.index', roles: ['admin-sucursal'] },
    'sale.cancellation.approved': { byRole: WORKBENCH },
    'sale.cancellation.rejected': { byRole: WORKBENCH },
    'device.battery.low': { byRole: DEVICES },
    'device.silent': { byRole: DEVICES },
    'device.outdated': { byRole: DEVICES },
    'device.registered': { byRole: DEVICES },
    'agenda.reminder.due': { any: 'agenda.index' },
    'agenda.item.assigned': { any: 'agenda.index' },
};

function routeNameFor(row, role) {
    if (row.admin) return row.roles.includes(role) ? row.admin : null;
    if (row.byRole) return row.byRole[role] ?? null;
    return row.any ?? null;
}

/**
 * @param {{ type?: string }|null} n
 * @param {{ role?: string, slug?: string|null, route: (name: string, slug: string) => string }|null} ctx
 *   `route` es la de Ziggy, inyectada para poder probar sin navegador.
 * @returns {string|null} la URL, o null si el aviso no lleva a ningún lado
 */
export function routeFor(n, ctx) {
    const row = TABLE[n?.type];
    // Sin slug (el superadmin en su perfil, fuera de todo tenant) ninguna de
    // estas rutas se puede construir: todas cuelgan de `/{tenant}`.
    if (!row || !ctx?.slug || typeof ctx.route !== 'function') return null;
    const name = routeNameFor(row, ctx.role);
    return name ? ctx.route(name, ctx.slug) : null;
}
