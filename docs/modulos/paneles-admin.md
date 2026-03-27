# Paneles de Administración

Dashboards con métricas y reportes para cada nivel de administración.

## Dashboard Superadmin (`/admin`)

**Controller:** `Admin\DashboardController`

Métricas globales:
- Total de empresas, sucursales, usuarios
- Ventas e ingresos del día (todas las empresas)
- Tabla de empresas con conteo de sucursales, usuarios y ventas del día
- Link a gestión de empresas y creación rápida

**Datos:** consulta todas las tablas sin TenantScope (usa `withoutGlobalScopes()`).

## Dashboard Admin Empresa (`/{tenant}/empresa`)

**Controller:** `Empresa\DashboardController`

Métricas consolidadas del tenant:
- Ventas totales del día (todas las sucursales)
- Conteo de transacciones
- Número de sucursales y usuarios
- Desglose por método de pago (efectivo, tarjeta, transferencia)
- Tabla comparativa de ventas por sucursal (nombre, estado, ventas, total)
- Links rápidos a gestión de sucursales y usuarios

**Datos:** filtrados automáticamente por TenantScope.

## Dashboard Admin Sucursal (`/{tenant}/sucursal`)

**Controller:** `Sucursal\DashboardController`

Métricas de la sucursal:
- Ventas del día (total y conteo)
- Ventas pendientes de cobro
- Productos activos y cajeros registrados
- Top 5 productos más vendidos (por revenue, hoy)
- Últimos 5 cortes de caja cerrados
- Links rápidos a: productos, cajeros, API keys, historial de cortes

**Datos:** filtrados por `branch_id` del usuario autenticado.

## Navegación por rol

El `AuthenticatedLayout` muestra links de navegación contextuales según el rol del usuario:

| Rol | Links |
|-----|-------|
| superadmin | Dashboard, Empresas |
| admin-empresa | Dashboard, Sucursales, Usuarios |
| admin-sucursal | Dashboard, Productos, Cajeros, API Keys, Cortes |
| cajero | Cola, Dashboard, Corte |

El rol se muestra como badge junto al nombre del usuario. Los datos de rol y tenant_slug se comparten via `HandleInertiaRequests`.

## HandleInertiaRequests

Comparte datos globales a todas las páginas:

```php
'auth' => [
    'user' => $user,
    'role' => $user->getRoleNames()->first(),
    'tenant_slug' => $user->tenant?->slug,
],
'flash' => [
    'success' => session('success'),
    'error' => session('error'),
],
```
