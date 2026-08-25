# Carnicería SaaS — Documentación

Sistema de gestión multitenant para carnicerías.
Stack: Laravel 13 · Vue 3 · Inertia v2 · PostgreSQL · Laravel Reverb · OpenAI.

> **Convención:** cada módulo implementado tiene su doc vivo en `modulos/` (o `api/`/`frontend/`). Los documentos de `arquitectura/` con propuestas y los specs de `superpowers/specs/` son históricos: su header **Estado** debe actualizarse al implementarse, apuntando al doc vivo. Este índice se actualiza con cada módulo nuevo.

---

## Arquitectura

- **[El ecosistema: las cinco aplicaciones](arquitectura/ecosistema.md)** — mapa de `carniceria-saas`, `carniceria-hub`, `hub-android`, `bascula` y `bascula-android`: cómo se comunican, las cuatro superficies de API, las reglas de compatibilidad y las decisiones estructurales. **Empieza por aquí.**
- [Modelo de datos](arquitectura/modelo-de-datos.md) — las 46 tablas por dominio y las convenciones que se repiten
- [Multitenancy](arquitectura/multitenant.md) — TenantScope, ResolveTenant, aislamiento por columna
- [Roles y permisos](arquitectura/roles-permisos.md) — 4 roles, 8 feature flags por sucursal, matriz por módulo
- [Reverb y WebSockets](arquitectura/reverb-websockets.md) — 5 eventos, 3 canales, bloqueo de venta y sus límites
- [Auditoría de cambios](arquitectura/auditoria.md) — `audit_logs`, el diff, y qué movimiento afecta al dinero
- [Propuesta: módulo Compras](arquitectura/compras-modulo.md) — diseño original (implementado; doc vivo en `modulos/compras.md`)
- [Propuesta: asistente IA](arquitectura/ia-asistente.md) — diseño original (implementado F0–F4; doc vivo en `modulos/asistente-ia.md`)

## API

- [Autenticación por API Key](api/autenticacion-apikey.md) — middleware, hashing SHA-256, rate limiting (básculas)
- [Endpoints de básculas v1](api/endpoints.md) — los 7 endpoints con ejemplos de request/response · ⚠️ **contrato que no admite cambios incompatibles**
- [Errores](api/errores.md) — códigos de la Scale API, qué reintentar y qué no
- [API del Hub](api/hub.md) — `/api/v1/hub/*` con Sanctum para la app de escritorio (Electron); idempotencia de pagos, realtime

## Módulos

### Núcleo de venta
- [Ventas](modulos/ventas.md) — flujo API → cajero, estados, folio, snapshots
- [Corte de Caja](modulos/corte-de-caja.md) — turnos, apertura/cierre, conciliación por método, historial
- [Productos](modulos/productos.md) — CRUD admin-sucursal, venta por peso/pieza/presentación
- [API Keys](modulos/api-keys.md) — generación, revocación, panel admin-sucursal
- [Vinculación por QR](modulos/qr-vinculacion.md) — provisión de básculas escaneando QR

### Pedidos online
- [Pedidos Web / Menú Online](modulos/pedidos-web.md) — SPA pública por QR, carrito, cotización de envío, honeypot
- [Emparejar Pedido ↔ Venta](modulos/emparejar-pedido-venta.md) — el pedido web no es venta contable; se empareja con la venta real de báscula

### Clientes y dinero
- [Clientes — Dashboard](modulos/clientes-dashboard.md) — perfil, estadísticas, precios preferenciales
- [Clientes — Cobro Global](modulos/clientes-cobro-global.md) — abonos distribuidos FIFO sobre ventas pendientes
- [Teléfonos y resolución de clientes](modulos/clientes-telefonos.md) — normalización E.164, creación automática desde ventas, comandos de mantenimiento
- [Clientes en Caja](modulos/clientes-caja.md) — módulo opcional del cajero (`cashier_customers_enabled`): cartera, alta/edición y cobro FIFO, sin descuentos
- [Comprobantes de pago](modulos/comprobantes-pago.md) — adjuntos de transferencias en pagos de venta y cobros globales, toggles por sucursal
- [Gastos](modulos/gastos.md) — categorías/subcategorías, captura con IA (foto+voz+texto), adjuntos, turno
- [Compras + Proveedores](modulos/compras.md) — CMV, cuentas por pagar, pagos FIFO, catálogo de insumos, captura IA
- [Movimientos](modulos/movimientos.md) — qué se le hizo a una venta después de cobrarla
- [Métricas](modulos/metricas.md) — glosario fuente de verdad: ventas, margen, utilidad, cobranza, cancelaciones

### Organización
- [Empresas (Tenants)](modulos/empresas.md) — CRUD superadmin
- [Sucursales (Branches)](modulos/sucursales.md) — CRUD admin-empresa, feature flags por sucursal
- [Paneles Admin](modulos/paneles-admin.md) — dashboards por rol, navegación contextual
- [Agenda](modulos/agenda.md) — pendientes/recordatorios por rol, recurrencia, ICS, captura IA

### IA
- [Asistente Conversacional](modulos/asistente-ia.md) — chat texto+voz, 9 tools de lectura, 6 de borrador con confirmación humana

## Frontend

- [Cola de ventas y bloqueo](frontend/cola-ventas.md) — `useSaleQueue` y `useSaleLock`: llegada en vivo y edición concurrente
- [Pantallas del cajero](frontend/pantallas-cajero.md) — mesa de trabajo, turno, corte, historial, pagos y los módulos opcionales

## Guías

- **[Levantar el entorno local](guias/entorno-local.md)** — del clon al primer login, con los tropiezos conocidos
- [Despliegue](guias/despliegue.md) — Laravel Cloud, publicación de las apps cliente y trampas conocidas
- [Vincular báscula por QR](guias/vincular-bascula-por-qr.md)

## Otros

- [Seeders y Datos Demo](seeders-demo.md) — usuarios de prueba y credenciales
- `superpowers/specs/` — specs de diseño por iniciativa (históricos; ver header Estado de cada uno)
- `superpowers/plans/` — planes de implementación (históricos)

## Mantener esta documentación honesta

```bash
npm run check:docs
```

Verifica que los enlaces entre documentos resuelvan, que las rutas de archivo citadas en los **docs vivos** existan, y que cada spec declare su header `Estado:`. Corre en CI en cada push y cada pull request. No comprueba si el contenido es correcto — eso sigue siendo trabajo de quien hace el cambio.

---

## Estado del sistema (2026-07-06)

| Área | Estado |
|------|--------|
| Núcleo (auth, roles, tenancy, CRUD, API básculas, tiempo real, cajero, paneles) | ✅ Completo (fases 1–7 originales) |
| Pedidos web / Menú online | ✅ Completo · **oculto tras `FEATURE_WEB_ORDERS` (off por default)** desde 2026-07-08 |
| Clientes (fiado, precios preferenciales, cobro global FIFO) | ✅ Completo · desde 2026-08-05 el cajero tiene su propio módulo opcional por sucursal (sin precios preferenciales) · desde 2026-08-11 los teléfonos son E.164 y capturar uno en una venta resuelve o crea el cliente ([doc](modulos/clientes-telefonos.md)) |
| Comprobantes de pago (transferencias) | ✅ Completo · web (Sucursal y Caja) · paridad con hub pendiente |
| Gastos (con captura IA) | ✅ Completo |
| Compras + Proveedores + CxP (con captura IA) | ✅ Completo (sin inventario activo, por diseño) |
| Métricas (9 ejes + Resumen con utilidad) | ✅ Completo |
| Agenda | ✅ Completo |
| Asistente IA conversacional | ✅ F0–F4 · ✅ mini-app móvil `/{tenant}/asistente` completa (F0–F5: cobro FIFO a clientes, pago a cuenta FIFO a proveedores, modo simple, cajero operativo, retiros y cambio de precios) · pendiente F5-config asistida y F6 parcial del spec original · TTS off en UI |
| API del Hub (Electron, Sanctum, idempotencia) | ✅ Fase 1 backend · offline con cola en el cliente pendiente (repo `carniceria-hub`) |
| Hub Android (`hub-android`) | 🟡 Núcleo completo · mismo protocolo que el hub Electron, verificado por la suite de conformidad · **pendiente de validación en tablet durante una jornada real** |
| Inventario / stock | ❌ No iniciado (fase futura F-Inv1+) |
