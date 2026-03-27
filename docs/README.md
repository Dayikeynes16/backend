# Carnicería SaaS — Documentación

Sistema de gestión multitenant para carnicerías.
Stack: Laravel 13 · Vue 3 · Inertia · PostgreSQL · Laravel Reverb.

---

## Arquitectura

- [Multitenancy](arquitectura/multitenant.md) — TenantScope, ResolveTenant, aislamiento por columna
- [Roles y Permisos](arquitectura/roles-permisos.md) — 4 roles, Spatie Permission, redirección por rol
- [Reverb y WebSockets](arquitectura/reverb-websockets.md) — canal privado por sucursal, evento NewExternalSale

## API Pública

- [Autenticación por API Key](api/autenticacion-apikey.md) — middleware, hashing SHA-256, rate limiting
- [Endpoints](api/endpoints.md) — 5 endpoints con ejemplos de request/response
- [Errores](api/errores.md) — códigos de error y causas

## Módulos

- [Empresas (Tenants)](modulos/empresas.md) — CRUD superadmin
- [Sucursales (Branches)](modulos/sucursales.md) — CRUD admin-empresa
- [Productos](modulos/productos.md) — CRUD admin-sucursal, tipos kg/piece/cut
- [API Keys](modulos/api-keys.md) — generación, revocación, panel admin-sucursal
- [Ventas](modulos/ventas.md) — flujo API → cajero, estados, folio, snapshots
- Corte de Caja — *pendiente (Fase 6)*

## Frontend

- Cola de Ventas — *pendiente (Fase 6)*
- Pantallas del Cajero — *pendiente (Fase 6)*

## Otros

- [Seeders y Datos Demo](seeders-demo.md) — usuarios de prueba y credenciales

---

## Estado por fase

| Fase | Contenido | Estado |
|------|-----------|--------|
| 1 — Scaffold base | Laravel + Inertia + Vue, PG, Sail, Reverb, migraciones | Completada |
| 2 — Auth + roles | Spatie Permission, login multitenant, redirección por rol | Completada |
| 3 — CRUD core | Empresas, Sucursales, Productos, Usuarios | Completada |
| 4 — API pública | AuthenticateApiKey, 5 endpoints, API Keys, rate limiting | Completada |
| 5 — Reverb + tiempo real | Evento broadcast, Echo en Vue, cola en tiempo real | Pendiente |
| 6 — Pantallas cajero | Cola de ventas, dashboard del día, corte de caja | Pendiente |
| 7 — Paneles admin | Dashboard admin sucursal, reportes, superadmin | Pendiente |
