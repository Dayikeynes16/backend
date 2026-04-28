# Roles y Permisos

Implementados con Spatie Laravel Permission. Los roles están scoped al tenant — un usuario de Empresa A nunca puede acceder a datos de Empresa B.

## Responsabilidades

- Definir los 4 niveles de acceso del sistema.
- Proteger rutas web por rol via middleware.
- Redirigir al usuario tras login según su rol.

## Roles

| Rol | Alcance | Capacidades |
|-----|---------|-------------|
| `superadmin` | Global (sin tenant) | Gestiona todas las empresas. Accede a `/admin`. Puede entrar a cualquier tenant. |
| `admin-empresa` | Tenant | Gestiona sucursales y usuarios de su empresa. Accede a `/{tenant}/empresa`. |
| `admin-sucursal` | Sucursal | Gestiona productos y cajeros de su sucursal. Accede a `/{tenant}/sucursal`. |
| `cajero` | Sucursal | Solo ve la cola de ventas pendientes y cobra. Accede a `/{tenant}/caja`. |

## Seeder de roles (`database/seeders/RoleSeeder.php`)

Crea los 4 roles con `guard_name = 'web'` usando `firstOrCreate` (idempotente).

## Redirección post-login

En `AuthenticatedSessionController::redirectPath()`:

```
superadmin     → route('admin.dashboard')        → /admin
admin-empresa  → route('empresa.dashboard')      → /{tenant}/empresa
admin-sucursal → route('sucursal.dashboard')      → /{tenant}/sucursal
cajero         → route('caja.queue')              → /{tenant}/caja
```

## Protección de rutas

```php
// Superadmin
Route::prefix('admin')->middleware(['auth', 'role:superadmin'])

// Tenant-scoped (permite superadmin en todas)
Route::middleware('role:admin-empresa|superadmin')
Route::middleware('role:admin-sucursal|superadmin')
Route::middleware('role:cajero|superadmin')
```

## Modelo User

El modelo `User` usa el trait `HasRoles` de Spatie. Tiene relaciones `tenant()` y `branch()` (ambas nullable para superadmin).

## Matriz por módulo (resumen)

| Módulo | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Gastos (CRUD, ver) | ✅ | ✅ todas las sucursales | ✅ sólo su sucursal | ❌ |
| Gastos · categorías/subcategorías | ✅ | ✅ | sólo lectura | ❌ |
| Gastos · adjuntos (descarga/borrado) | ✅ | ✅ | ✅ sólo de su sucursal | ❌ |

Detalle del módulo: ver `docs/modulos/gastos.md`.
