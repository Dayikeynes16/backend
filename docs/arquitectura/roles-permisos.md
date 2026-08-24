# Roles y permisos

Cuatro roles con Spatie Laravel Permission. El acceso fino **no** se hace con permisos granulares, sino con *feature flags por sucursal*: es la decisión que más define este módulo.

## Responsabilidades

- Definir los cuatro niveles de acceso.
- Proteger las rutas por rol y por flag de sucursal.
- Llevar a cada usuario a su panel tras el login.

**No hace:** no aísla tenants — eso es [multitenant.md](multitenant.md). No define permisos por acción: no hay tabla de permisos poblada.

## Decisiones

| Decisión | Por qué |
|----------|---------|
| **Solo roles, sin permisos granulares** | Cuatro puestos de trabajo bien definidos. Una matriz de permisos sería complejidad sin uso. |
| **Feature flags por sucursal para lo variable** | Lo que cambia de una carnicería a otra es *qué módulos toca el cajero*, no qué puede hacer un rol. El dueño lo enciende sin tocar código. |
| **Un prefijo de ruta por rol** | `/{tenant}/caja`, `/{tenant}/sucursal`… hace evidente a quién pertenece cada pantalla. |
| **El superadmin entra a todo** | Soporte: poder ver lo que ve el cliente sin cambiar de cuenta. |

## Los cuatro roles

| Rol | Alcance | Prefijo | Qué hace |
|-----|---------|---------|----------|
| `superadmin` | Global, sin tenant | `/admin` | Gestiona las empresas. Entra a cualquier tenant. |
| `admin-empresa` | Empresa | `/{tenant}/empresa` | Sucursales, usuarios, marca, métricas, gastos, compras y proveedores. Enciende los flags de cada sucursal. |
| `admin-sucursal` | Sucursal | `/{tenant}/sucursal` | Productos, mesa de trabajo, edición de items, aprobación de cancelaciones, clientes, turnos, API keys, menú QR, métricas. |
| `cajero` | Sucursal | `/{tenant}/caja` | Cobra, abre y cierra turno. Según los flags: gastos, compras y clientes. **Solicita** cancelaciones, no las aprueba. |

Prefijos compartidos por los cuatro roles: `/{tenant}/agenda` y `/{tenant}/asistente`. El cajero entró al asistente en la fase F5, con un juego de herramientas operativo (cobros a clientes, sus turnos, retiros de caja).

Los roles se crean en `RoleSeeder` con `guard_name = 'web'`, de forma idempotente.

## Feature flags por sucursal

Columnas booleanas de `branches`, aplicadas con el middleware `branch.feature:{flag}`. Las enciende **admin-empresa** al editar la sucursal.

| Flag | Abre |
|------|------|
| `cashier_expenses_enabled` | Gastos para el cajero |
| `cashier_purchases_enabled` | Compras para el cajero |
| `cashier_customers_enabled` | Clientes y cobro FIFO para el cajero |
| `cashier_scale_sales_enabled` | Venta de mostrador con báscula desde el hub |
| `branch_admin_providers_enabled` | Crear y editar proveedores al admin-sucursal |
| `branch_admin_expense_categories_enabled` | Categorías de gasto al admin-sucursal |
| `branch_admin_purchase_products_enabled` | Catálogo de insumos de compra al admin-sucursal |
| `branch_admin_movements_enabled` | Bitácora de movimientos al admin-sucursal |

Con el flag apagado, el middleware responde `403` y la navegación oculta la entrada. Detalle en [modulos/sucursales.md](../modulos/sucursales.md).

Hay además un flag **global**, no por sucursal: `FEATURE_WEB_ORDERS` (`config/features.php`) apaga los pedidos web del sistema entero.

## Protección de rutas

```php
// Superadmin
Route::prefix('admin')->middleware(['auth', 'role:superadmin'])

// Por rol, con el superadmin siempre dentro
Route::middleware('role:admin-empresa|superadmin')
Route::middleware('role:admin-sucursal|superadmin')
Route::middleware('role:cajero|superadmin')

// Módulos opcionales del cajero
Route::middleware('branch.feature:cashier_customers_enabled')
```

A eso se suman, en el orden de la pila: `resolve.tenant` (resuelve el slug), `ensure.tenant` (que el usuario pertenezca a ese tenant) y `force.password.change` (obliga a cambiar la contraseña temporal antes de seguir).

## Fuera de la web

| Superficie | Cómo autoriza |
|------------|---------------|
| **API del hub** (`/api/v1/hub/*`) | Token Sanctum + `hub.role`: **solo `cajero` y `admin-sucursal`**. `admin-empresa` y `superadmin` reciben `403` — el hub es una herramienta de mostrador. |
| **Scale API** (`/api/v1/*`) | `X-Api-Key` por sucursal. **No hay usuario**: la key identifica a la sucursal, no a una persona. |
| **API pública** (menú QR) | Sin autenticación, con throttle y honeypot. |

## Tras el login

`AuthenticatedSessionController::redirectPath()` y la ruta `/dashboard` aplican la misma tabla:

| Rol | Destino |
|-----|---------|
| `superadmin` | `admin.dashboard` |
| `admin-empresa` | `empresa.dashboard` del tenant |
| `admin-sucursal` | `sucursal.dashboard` del tenant |
| `cajero` | **`caja.workbench`** del tenant |

> El cajero aterriza en la mesa de trabajo. La antigua `caja.queue` ya no existe.

## El modelo `User`

Usa el trait `HasRoles`. Tiene `tenant()` y `branch()`, **ambas nulas para el superadmin**, que no pertenece a ninguna empresa. `branch_id` es además lo que autoriza el canal de tiempo real `sucursal.{branchId}`.

## Matriz por módulo

| Módulo | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|
| Empresas (tenants) | ✅ | ❌ | ❌ | ❌ |
| Sucursales y sus flags | ✅ | ✅ | ❌ | ❌ |
| Usuarios | ✅ | ✅ | ❌ | ❌ |
| Productos y categorías | ✅ | ❌ | ✅ | ❌ |
| Mesa de trabajo | ✅ | ❌ | ✅ | ✅ |
| Editar items de una venta | ✅ | ❌ | ✅ | ❌ |
| Cancelar una venta | ✅ | ❌ | ✅ aprueba | solicita |
| Turnos y corte | ✅ | ver | ✅ | ✅ el suyo |
| Clientes y fiado | ✅ | ❌ | ✅ | según flag, sin precios preferenciales |
| Precios preferenciales | ✅ | ❌ | ✅ | ❌ |
| Gastos | ✅ | ✅ todas | ✅ la suya | según flag |
| Categorías de gasto | ✅ | ✅ | según flag | ❌ |
| Compras y proveedores | ✅ | ✅ | según flag | según flag |
| Métricas | ✅ | ✅ | ✅ la suya | ❌ |
| API keys y menú QR | ✅ | ❌ | ✅ | ❌ |
| Agenda | ✅ | ✅ | ✅ | ✅ |
| Asistente IA | ✅ | ✅ | ✅ | ✅ operativo |

Para el detalle de cada módulo, su doc en [modulos/](../modulos/).

## Tests

`tests/Feature/Auth/` cubre la redirección por rol y el cambio forzado de contraseña. Cada módulo prueba además sus propias restricciones de rol y de flag.
