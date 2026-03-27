# Multitenancy

Sistema multitenant por columna (single database). Cada registro de negocio tiene una columna `tenant_id` que lo asocia a una empresa.

## Responsabilidades

- Aislar datos entre tenants (empresas) a nivel de query.
- Resolver el tenant activo a partir del slug en la URL.
- Auto-asignar `tenant_id` al crear registros dentro de un contexto de tenant.

**No hace:** no maneja bases de datos separadas, ni subdominios, ni schemas de PostgreSQL.

## Componentes

### TenantScope (`app/Scopes/TenantScope.php`)

Global Scope de Eloquent que agrega `WHERE tenant_id = ?` a todos los queries de modelos que lo usen.

```php
public function apply(Builder $builder, Model $model): void
{
    if (! app()->bound('tenant')) {
        return; // CLI, tinker, seeders — sin filtro
    }

    $tenant = app('tenant');

    if ($tenant) {
        $builder->where($model->getTable() . '.tenant_id', $tenant->id);
    }
}
```

**Nota:** verifica `app()->bound('tenant')` antes de resolver para evitar `BindingResolutionException` en contextos sin HTTP (tinker, queue workers, tests).

### Trait BelongsToTenant (`app/Traits/BelongsToTenant.php`)

Trait que aplican todos los modelos de negocio. Hace dos cosas:

1. Registra el `TenantScope` como Global Scope.
2. En el evento `creating`, auto-asigna `tenant_id` si hay un tenant activo en el contenedor.

Define también la relación `tenant(): BelongsTo`.

**Modelos que lo usan:** `Branch`, `ApiKey`, `Product`, `Sale`, `CashRegisterShift`.

**Modelos que NO lo usan:** `Tenant` (es el tenant mismo), `User` (tiene `tenant_id` pero no usa el scope global — se filtra manualmente), `SaleItem` (se accede siempre a través de `Sale`).

### Middleware ResolveTenant (`app/Http/Middleware/ResolveTenant.php`)

Lee el parámetro `{tenant}` de la ruta, busca el `Tenant` por slug, verifica que esté activo, y lo inyecta en el contenedor.

```
app.com/{tenant}/empresa/...  →  ResolveTenant lee "el-toro"
                                  →  Tenant::where('slug', 'el-toro')
                                  →  app()->instance('tenant', $tenant)
                                  →  $request->route()->forgetParameter('tenant')
```

El `forgetParameter('tenant')` elimina el parámetro de la ruta para que los controllers no tengan que recibirlo como argumento.

**Errores:** retorna 404 si el slug no existe o el tenant está inactivo.

### Middleware EnsureUserBelongsToTenant (`app/Http/Middleware/EnsureUserBelongsToTenant.php`)

Verifica que el usuario autenticado pertenece al tenant resuelto. Los superadmin pasan sin restricción.

**Errores:** retorna 403 si `$user->tenant_id !== $tenant->id`.

## Registro de middleware

En `bootstrap/app.php`:

```php
$middleware->alias([
    'resolve.tenant' => ResolveTenant::class,
    'ensure.tenant'  => EnsureUserBelongsToTenant::class,
]);
```

## Rutas protegidas

```
Route::prefix('{tenant}')
    ->middleware(['web', 'resolve.tenant', 'auth', 'ensure.tenant'])
    ->group(...)
```
