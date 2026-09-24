import { describe, it, expect } from 'vitest';
import { routeFor } from '@/lib/notificationRoutes.js';

/**
 * Adónde lleva cada aviso en la web. `route` se inyecta (no es la de Ziggy)
 * para probar la tabla sin navegador: devuelve `/{slug}/{nombre}` y así se ve
 * a la vez qué ruta se eligió y con qué slug.
 */
const route = (name, slug) => `/${slug}/${name}`;
const ctx = (role, slug = 'el-toro') => ({ role, slug, route });

describe('routeFor', () => {
    it('la solicitud de cancelación sólo lleva a algún lado al admin de sucursal', () => {
        const n = { type: 'sale.cancellation.requested' };
        expect(routeFor(n, ctx('admin-sucursal'))).toBe('/el-toro/sucursal.cancelaciones.index');
        expect(routeFor(n, ctx('cajero'))).toBeNull();
        expect(routeFor(n, ctx('admin-empresa'))).toBeNull();
    });

    it('aprobada / rechazada lleva a la mesa de trabajo de cada rol', () => {
        for (const type of ['sale.cancellation.approved', 'sale.cancellation.rejected']) {
            expect(routeFor({ type }, ctx('cajero'))).toBe('/el-toro/caja.workbench');
            expect(routeFor({ type }, ctx('admin-sucursal'))).toBe('/el-toro/sucursal.workbench');
            expect(routeFor({ type }, ctx('admin-empresa'))).toBeNull();
        }
    });

    it('los avisos de equipos llevan al panel de equipos de cada rol', () => {
        for (const type of ['device.battery.low', 'device.silent', 'device.outdated', 'device.registered']) {
            expect(routeFor({ type }, ctx('admin-empresa'))).toBe('/el-toro/empresa.devices.index');
            expect(routeFor({ type }, ctx('admin-sucursal'))).toBe('/el-toro/sucursal.devices.index');
            expect(routeFor({ type }, ctx('cajero'))).toBe('/el-toro/caja.devices.index');
            expect(routeFor({ type }, ctx('superadmin'))).toBeNull();
        }
    });

    it('los de agenda llevan a la agenda con cualquier rol', () => {
        for (const type of ['agenda.reminder.due', 'agenda.item.assigned']) {
            for (const role of ['admin-empresa', 'admin-sucursal', 'cajero']) {
                expect(routeFor({ type }, ctx(role))).toBe('/el-toro/agenda.index');
            }
        }
    });

    it('sin slug de tenant no hay destino (superadmin en su perfil)', () => {
        expect(routeFor({ type: 'agenda.reminder.due' }, ctx('superadmin', null))).toBeNull();
        expect(routeFor({ type: 'device.silent' }, { role: 'cajero', route })).toBeNull();
    });

    it('un tipo desconocido, o el nombre de clase que trae el socket, no tiene destino', () => {
        expect(routeFor({ type: 'algo.nuevo' }, ctx('cajero'))).toBeNull();
        expect(routeFor({ type: 'App\\Notifications\\SaleCancellationRequested' }, ctx('admin-sucursal'))).toBeNull();
        expect(routeFor(null, ctx('cajero'))).toBeNull();
        expect(routeFor({ type: 'agenda.reminder.due' }, null)).toBeNull();
    });
});
