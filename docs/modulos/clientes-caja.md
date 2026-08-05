# Clientes en Caja — módulo opcional del cajero

El cajero es quien está en el mostrador cuando un cliente llega a abonar su
cuenta ("fiado"). Este módulo le da, **si la empresa lo habilita en su
sucursal**, la cartera de clientes y el cobro global FIFO desde `/{tenant}/caja`
— sin ponerle en las manos los precios preferenciales, que son una decisión
comercial del admin de sucursal.

Implementado el 2026-08-05. Spec de diseño:
[2026-08-05-cajero-clientes-cobros-design.md](../superpowers/specs/2026-08-05-cajero-clientes-cobros-design.md).

## Responsabilidades

- Exponer al cajero la cartera de clientes de **su** sucursal (listado con
  búsqueda, filtro de deuda y orden).
- Ficha del cliente con KPIs, historial de ventas y feed de pagos.
- Registrar cobros globales FIFO y consultar su detalle.
- Dar de alta y editar los datos de contacto de un cliente.

Todo lo demás sigue siendo del admin de sucursal.

## Decisiones

| Decisión | Razón |
|---|---|
| Un solo toggle (`cashier_customers_enabled`) para todo el módulo | El cajero no necesita "ver la cartera pero no cobrar": o atiende cobros o no. Un interruptor, no tres. |
| Default `true` | Consistencia con `cashier_expenses_enabled`/`cashier_purchases_enabled`, y porque el asistente IA **ya** autorizaba al cajero a registrar cobros: nacer en `false` le habría quitado una capacidad existente al desplegar. |
| Precios preferenciales ocultos, no en solo-lectura | Los descuentos por cliente son decisión comercial. Sin superficie que blindar: las rutas `clientes.precios.*` no existen bajo `/caja`. |
| Sin cancelar cobros | Ya era así antes de este módulo (`Sucursal\CustomerPaymentController@destroy` rechaza a quien no sea admin-sucursal o superior). Aquí simplemente no se expone la ruta. |
| Sin editar items de venta desde la ficha | Coherente con la Mesa de Trabajo: el cajero nunca toca items, los solicita cancelar. |
| Sin dar de baja ni cambiar el estado del cliente | Desactivar un cliente afecta a toda la sucursal. |
| Los comprobantes de cobro (`caja.cobros.receipts.*`) quedan **fuera** del gate | Si la empresa apaga el módulo, el cajero debe poder seguir adjuntando el comprobante de un cobro que ya registró. |
| El toggle también gatea las tools de escritura del asistente IA | Una sola fuente de verdad: si el módulo está apagado, tampoco se cobra ni se dan de alta clientes por voz/chat. Las tools de **lectura** (`consultar_clientes`) no se gatean: el flag controla lo que el cajero *hace*, no lo que *consulta*. |

## Modelo de datos

Sin tablas nuevas. Una columna:

```
branches
  cashier_customers_enabled  boolean  NOT NULL  DEFAULT true
```

Migración: `2026_08_05_090000_add_cashier_customers_enabled_to_branches.php`.

## Roles y permisos

| Capacidad | admin-sucursal | cajero (con el toggle) |
|---|:--:|:--:|
| Listado de la cartera | ✅ | ✅ |
| Ficha, historial y pagos | ✅ | ✅ |
| Registrar cobro global FIFO | ✅ | ✅ |
| Ver detalle de un cobro | ✅ | ✅ |
| Adjuntar comprobantes de transferencia | ✅ | ✅ |
| Eliminar comprobantes | ✅ | ❌ |
| Crear cliente | ✅ | ✅ |
| Editar nombre / teléfono / notas | ✅ | ✅ |
| Precios preferenciales (descuentos) | ✅ | ❌ |
| Cancelar un cobro global | ✅ | ❌ |
| Activar/desactivar o eliminar cliente | ✅ | ❌ |
| Editar items de una venta desde la ficha | ✅ | ❌ |

El gate son tres capas, la misma receta que Gastos y Compras del cajero:

| Superficie | Mecanismo |
|---|---|
| Rutas web de Caja | `branch.feature:cashier_customers_enabled` (`EnsureBranchFeature`) → 403 |
| Sidebar de Caja | `auth.branch.cashier_customers_enabled` (`HandleInertiaRequests`) |
| Asistente IA | `PrepareCustomerPaymentDraftTool` / `PrepareCustomerDraftTool` `::authorize()` y sus confirmers |

El cobro sigue exigiendo **turno abierto** (403 sin él), igual que para el
admin de sucursal.

## Arquitectura

La lógica no se duplicó: los controllers de ambos roles comparten traits en
`app/Http/Controllers/Concerns/`.

| Trait | Qué aporta |
|---|---|
| `AuthorizesCustomerAccess` | `authorizeCustomerBranchAccess()` — 403 cross-branch. Vive aparte porque los otros tres lo necesitan y PHP no permite el método duplicado en traits combinados. |
| `HandlesCustomers` | Listado filtrable, resumen agregado de la cartera, seed de KPIs de la ficha, `store()` y `update()`. `allowsCustomerStatusChange()` es el punto de extensión: `false` en Caja. |
| `HandlesCustomerStats` | Los cinco endpoints JSON de la ficha (stats, historial, top productos, detalle de venta, pagos). Idénticos para ambos roles. |
| `HandlesCustomerGlobalPayments` | `store()` y `show()` del cobro global. La **cancelación no está aquí** a propósito. |

Controllers:

| Controller | Diferencia |
|---|---|
| `Sucursal\CustomerController` | Carga `prices` y el catálogo de productos; conserva `destroy()` |
| `Caja\CustomerController` | No carga ninguno de los dos; sin `destroy()`; `allowsCustomerStatusChange() === false` |
| `Sucursal\CustomerPaymentController` | Añade `destroy()` (cancelación) sobre el trait |
| `Caja\CustomerPaymentController` | Solo el trait |
| `Sucursal\CustomerStatsController` / `Caja\CustomerStatsController` | Ambos son el trait puro |

## Rutas

Bajo `/{tenant}/caja`, dentro de `role:cajero|superadmin` +
`branch.feature:cashier_customers_enabled`:

```
GET    clientes                                    caja.clientes.index
POST   clientes                                    caja.clientes.store
GET    clientes/{customer}                         caja.clientes.show
PUT    clientes/{customer}                         caja.clientes.update
GET    clientes/{customer}/stats                   caja.clientes.stats
GET    clientes/{customer}/historial               caja.clientes.historial
GET    clientes/{customer}/productos-top           caja.clientes.productos-top
GET    clientes/{customer}/pagos                   caja.clientes.pagos
GET    clientes/{customer}/ventas/{sale}           caja.clientes.venta-detalle
POST   clientes/{customer}/cobro-global            caja.clientes.cobro-global
GET    clientes/{customer}/cobros-globales/{cp}    caja.clientes.cobro-global.show
```

Deliberadamente **inexistentes** (hay tests que lo verifican):
`caja.clientes.precios.*`, `caja.clientes.destroy`,
`caja.clientes.cobro-global.cancel`.

Fuera del gate, ya existentes: `caja.cobros.receipts.{store,download,preview}`.

## Frontend

Páginas nuevas: `Pages/Caja/Clientes/{Index,Show}.vue`, con `CajeroLayout`.

Los componentes de `Components/Clientes/` se comparten mediante una prop
`routePrefix` (default `'sucursal'`), el mismo patrón de `PaymentReceiptsPanel`
y `PurchaseProductsManager`:

| Componente | Props nuevas |
|---|---|
| `CustomerHero` | `routePrefix`, `canManageStatus` (false en Caja → menú solo con "Editar datos") |
| `CustomerFinancesTab` | `routePrefix`, `allowItemEdits`, `canCancelPayment` |
| `CustomerPaymentModal` | `routePrefix` |
| `GlobalPaymentDetailModal` | `routePrefix`, `canCancel` |
| `SaleDetailModal` | `routePrefix`, `allowItemEdits` (false → sin lock, sin botones ni sub-modales de item) |
| `useCustomerStats` | tercer argumento `routePrefix` |
| `CustomerPreferentialPrices` | **sin cambios** — no se monta en Caja |

`CajeroLayout` añade la entrada "Clientes" cuando
`auth.branch.cashier_customers_enabled`.

El toggle vive en Empresa → Sucursales → Editar, bloque "Permisos del cajero",
como "Clientes y cobros".

## Riesgos y limitaciones

- **Sin paridad en el hub ni en Android.** `/api/v1/hub/customers/*` sigue
  restringido a admin-sucursal y **no** consulta este flag. Es una diferencia
  conocida entre web y hub, pendiente de tratarse por separado.
- **La tool del asistente sigue listada aunque el flag esté apagado.**
  `ToolRegistry::forUser()` filtra por rol, no por `authorize()`; el rechazo
  ocurre al invocarla. Es el comportamiento que ya tenían Gastos y Compras.
- **Apagar el módulo no revierte nada.** Los cobros ya registrados siguen
  válidos y el cajero conserva el acceso a sus comprobantes.

## Tests

- `tests/Feature/Caja/CajaClientesModuleTest.php` — gate on/off en todas las
  rutas, aislamiento por sucursal, alta y edición (con `status` ignorado),
  cobro FIFO, turno obligatorio, y la ausencia comprobada de las rutas de
  precios/baja/cancelación.
- `tests/Feature/Empresa/BranchCashierModulesTest.php` — el toggle nuevo.
- `tests/Feature/Ai/AssistantCajeroAccessTest.php` — `authorize()` de las tools
  de cliente según el flag.
