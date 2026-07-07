# Carnicería SaaS — Documentación

Sistema de gestión multitenant para carnicerías.
Stack: Laravel 13 · Vue 3 · Inertia v2 · PostgreSQL · Laravel Reverb · OpenAI.

> **Convención:** cada módulo implementado tiene su doc vivo en `modulos/` (o `api/`/`frontend/`). Los documentos de `arquitectura/` con propuestas y los specs de `superpowers/specs/` son históricos: su header **Estado** debe actualizarse al implementarse, apuntando al doc vivo. Este índice se actualiza con cada módulo nuevo.

---

## Arquitectura

- [Multitenancy](arquitectura/multitenant.md) — TenantScope, ResolveTenant, aislamiento por columna
- [Roles y Permisos](arquitectura/roles-permisos.md) — 4 roles, Spatie Permission, redirección por rol
- [Reverb y WebSockets](arquitectura/reverb-websockets.md) — canal privado por sucursal, evento NewExternalSale
- [Propuesta: módulo Compras](arquitectura/compras-modulo.md) — diseño original (implementado; doc vivo en `modulos/compras.md`)
- [Propuesta: asistente IA](arquitectura/ia-asistente.md) — diseño original (implementado F0–F4; doc vivo en `modulos/asistente-ia.md`)

## API

- [Autenticación por API Key](api/autenticacion-apikey.md) — middleware, hashing SHA-256, rate limiting (básculas)
- [Endpoints públicos v1](api/endpoints.md) — API de básculas con ejemplos de request/response
- [Errores](api/errores.md) — códigos de error y causas
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
- [Gastos](modulos/gastos.md) — categorías/subcategorías, captura con IA (foto+voz+texto), adjuntos, turno
- [Compras + Proveedores](modulos/compras.md) — CMV, cuentas por pagar, pagos FIFO, catálogo de insumos, captura IA
- [Métricas](modulos/metricas.md) — glosario fuente de verdad: ventas, margen, utilidad, cobranza, cancelaciones

### Organización
- [Empresas (Tenants)](modulos/empresas.md) — CRUD superadmin
- [Sucursales (Branches)](modulos/sucursales.md) — CRUD admin-empresa, feature flags por sucursal
- [Paneles Admin](modulos/paneles-admin.md) — dashboards por rol, navegación contextual
- [Agenda](modulos/agenda.md) — pendientes/recordatorios por rol, recurrencia, ICS, captura IA

### IA
- [Asistente Conversacional](modulos/asistente-ia.md) — chat texto+voz, 9 tools de lectura, 6 de borrador con confirmación humana

## Frontend

- [Cola de Ventas](frontend/cola-ventas.md) — composable useSaleQueue, suscripción Echo, UI de cobro
- [Pantallas del Cajero](frontend/pantallas-cajero.md) — OpenShift, Queue, Dashboard, Shift

## Guías

- [Vincular báscula por QR](guias/vincular-bascula-por-qr.md)

## Otros

- [Seeders y Datos Demo](seeders-demo.md) — usuarios de prueba y credenciales
- `superpowers/specs/` — specs de diseño por iniciativa (históricos; ver header Estado de cada uno)
- `superpowers/plans/` — planes de implementación (históricos)

---

## Estado del sistema (2026-07-06)

| Área | Estado |
|------|--------|
| Núcleo (auth, roles, tenancy, CRUD, API básculas, tiempo real, cajero, paneles) | ✅ Completo (fases 1–7 originales) |
| Pedidos web + emparejamiento con venta de báscula | ✅ Completo |
| Clientes (fiado, precios preferenciales, cobro global FIFO) | ✅ Completo |
| Gastos (con captura IA) | ✅ Completo |
| Compras + Proveedores + CxP (con captura IA) | ✅ Completo (sin inventario activo, por diseño) |
| Métricas (9 ejes + Resumen con utilidad) | ✅ Completo |
| Agenda | ✅ Completo |
| Asistente IA conversacional | ✅ F0–F4 · ✅ mini-app móvil `/{tenant}/asistente` (spec 2026-07-06: F0+F1 y F2 cobro FIFO a clientes; pendiente F3 pago proveedores, F4 modo simple) · pendiente F5 (config asistida) y F6 parcial (purga >90 días, suite adversarial, observabilidad) · TTS off en UI |
| API del Hub (Electron, Sanctum, idempotencia) | ✅ Fase 1 backend · offline con cola en el cliente pendiente (repo `carniceria-hub`) |
| Inventario / stock | ❌ No iniciado (fase futura F-Inv1+) |
