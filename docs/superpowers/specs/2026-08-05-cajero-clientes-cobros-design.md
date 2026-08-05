# Módulo Clientes para el cajero (configurable por sucursal)

**Estado:** Implementado (2026-08-05) — doc viva: [clientes-caja.md](../../modulos/clientes-caja.md)
**Fecha:** 2026-08-05
**Módulos afectados:** [clientes-dashboard](../../modulos/clientes-dashboard.md), [clientes-cobro-global](../../modulos/clientes-cobro-global.md), [sucursales](../../modulos/sucursales.md), [asistente-ia](../../modulos/asistente-ia.md)

## Problema

El cajero atiende el mostrador y es quien recibe el dinero cuando un cliente
llega a abonar su cuenta ("fiado"). Hoy, en la web, **no puede registrar ese
cobro**: el cobro global FIFO vive solo bajo `/{tenant}/sucursal/clientes/...`
y no existe ninguna ruta de clientes bajo `/{tenant}/caja`. El cajero acaba
pidiéndole al admin de sucursal que lo capture, o lo hace por el asistente IA
(la única superficie donde hoy sí está autorizado).

Al mismo tiempo, **los precios preferenciales no deben estar en sus manos**:
son una decisión comercial (el "descuento" que se le da a un cliente) y
pertenecen al admin de sucursal.

## Decisión

Un **módulo Clientes propio para Caja**, habilitado por sucursal desde el panel
de empresa, que expone la cartera y el cobro FIFO pero **no** los precios
preferenciales.

### Alcance (decidido con el usuario, 2026-08-05)

| Capacidad | Cajero | Nota |
|---|---|---|
| Listado de clientes de su sucursal (búsqueda, filtro de deuda, orden) | ✅ | Mismo listado que sucursal |
| Ficha del cliente (hero, KPIs, historial de ventas, pagos) | ✅ | |
| Registrar cobro global FIFO | ✅ | Requiere turno abierto, igual que sucursal |
| Ver detalle de un cobro global | ✅ | |
| Adjuntar/descargar comprobantes de transferencia | ✅ | Ya existía (`caja.cobros.receipts.*`) |
| Crear cliente | ✅ | |
| Editar nombre / teléfono / notas | ✅ | |
| **Precios preferenciales (descuentos)** | ❌ | Sección oculta; rutas inexistentes bajo `/caja` |
| **Cancelar un cobro global** | ❌ | Ya bloqueado hoy en `CustomerPaymentController@destroy` |
| **Activar/desactivar o eliminar cliente** | ❌ | Sigue siendo de admin-sucursal |
| **Editar items de una venta** desde la ficha | ❌ | Consistente con Mesa de Trabajo: el cajero no toca items |

### Feature flag

Nuevo booleano en `branches`, siguiendo el patrón ya establecido por
`cashier_expenses_enabled` / `cashier_purchases_enabled`:

```
cashier_customers_enabled  boolean  NOT NULL  DEFAULT true
```

**Default `true`** — dos razones:

1. Coherencia con los otros dos toggles del cajero, que también nacen activos.
2. **No romper el asistente IA**: hoy `PrepareCustomerPaymentDraftTool` ya
   autoriza al cajero a registrar cobros FIFO sin ninguna bandera. Si el flag
   naciera en `false`, esa capacidad existente desaparecería en todas las
   sucursales al desplegar.

El flag gatea **todo el módulo Clientes del cajero**, no solo el cobro: sin él
no hay listado, ni ficha, ni alta/edición, ni cobro. Un único interruptor
"Clientes y cobros" en Empresa → Sucursales → Editar.

Se aplica en tres superficies, igual que `cashier_expenses_enabled`:

| Superficie | Mecanismo |
|---|---|
| Rutas web de Caja | `branch.feature:cashier_customers_enabled` (`EnsureBranchFeature`) |
| Sidebar de Caja | `auth.branch.cashier_customers_enabled` en `HandleInertiaRequests` |
| Asistente IA | `PrepareCustomerPaymentDraftTool::isAvailableFor()` + `CustomerGlobalPaymentDraftConfirmer` consultan el flag **solo para el rol cajero** |

## Diseño

### Backend

El cobro FIFO **no se toca**: sigue en `CustomerGlobalPaymentService`. Lo que se
añade es una superficie de Caja que consume la misma lógica.

Para no duplicar las ~350 líneas de queries de `Sucursal\CustomerController`
y `Sucursal\CustomerStatsController`, se extraen a traits en
`app/Http/Controllers/Concerns/` (patrón ya usado por `HandlesPurchases`,
`HandlesProviderWrites`, etc.):

- `HandlesCustomerListing` — query del índice + `buildCustomersSummary()`
- `HandlesCustomerDetail` — `buildStatsSeed()` + autorización por sucursal
- `HandlesCustomerStats` — stats / historial / top productos / pagos / detalle de venta
- `HandlesCustomerGlobalPayments` — `store()` y `show()` del cobro global

Controllers nuevos, todos en `App\Http\Controllers\Caja\`:

| Controller | Métodos | Diferencia vs. sucursal |
|---|---|---|
| `CustomerController` | `index`, `show`, `store`, `update` | Renderiza `Caja/Clientes/*`; **no** carga `prices` ni `products`; sin `destroy` |
| `CustomerStatsController` | `stats`, `history`, `topProducts`, `payments`, `saleDetail` | Idéntico (solo lectura, ya filtra por sucursal) |
| `CustomerPaymentController` | `store`, `show` | Sin `destroy` |

Los controllers de `Sucursal\` se refactorizan para usar los mismos traits: una
sola fuente de verdad, sin cambio de comportamiento (los tests existentes lo
garantizan).

### Rutas

Dentro del grupo `role:cajero|superadmin` + prefijo `caja`, en un subgrupo
`->middleware('branch.feature:cashier_customers_enabled')`:

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

Deliberadamente **ausentes**: `clientes.precios.*` (descuentos),
`clientes.destroy`, `clientes.cobro-global.cancel`.

Las rutas `caja.cobros.receipts.*` (comprobantes) ya existen y **quedan fuera
del gate**: el cajero puede seguir adjuntando comprobantes a cobros que ya
registró aunque la empresa apague el módulo después.

### Frontend

Páginas nuevas `resources/js/Pages/Caja/Clientes/{Index,Show}.vue`, calcadas de
las de sucursal con `CajeroLayout` y sin la sección de precios preferenciales.

Los componentes compartidos de `Components/Clientes/` reciben una prop
`routePrefix` (default `'sucursal'`), el patrón ya usado por
`PaymentReceiptsPanel`, `PurchaseProductsManager`, `ProveedorFormModal`:

| Componente | Cambio |
|---|---|
| `CustomerHero` | `routePrefix` para el breadcrumb; ocultar acciones de eliminar/estado cuando `caja` |
| `CustomerFinancesTab` | `routePrefix` en el historial y en `PaymentReceiptsPanel` |
| `CustomerPaymentModal` | `routePrefix` en `cobro-global` |
| `GlobalPaymentDetailModal` | `routePrefix` + prop `canCancel` (false en caja) |
| `SaleDetailModal` | `routePrefix` + prop `allowItemEdits` (false en caja → sin lock, sin botones de item) |
| `useCustomerStats` | argumento `routePrefix` |
| `CustomerPreferentialPrices` | **sin cambios** — simplemente no se monta en caja |

Sidebar (`CajeroLayout`): entrada "Clientes" condicionada a
`branch.cashier_customers_enabled`, junto a Gastos y Compras.

Panel de empresa (`Empresa/Sucursales/Edit.vue`): tercer toggle en el bloque de
módulos del cajero — "Clientes y cobros".

## Riesgos

- **Refactor de los controllers de sucursal a traits**: es el punto de mayor
  riesgo. Mitigación: los tests de `tests/Feature/Sucursal/` y
  `tests/Feature/Clientes*` deben pasar sin modificarse.
- **Cambio de comportamiento en el asistente IA**: si una empresa apaga el flag,
  el cajero pierde la tool `preparar_cobro_cliente` que hoy tiene siempre. Es
  intencional (el flag debe ser una sola verdad), y el default `true` evita que
  ocurra sin una acción explícita del admin.
- **Turno abierto**: sigue siendo requisito del servicio. Un cajero sin turno ve
  el módulo pero el botón de cobro aparece deshabilitado con su razón, igual que
  en sucursal.

## Fuera de alcance

Paridad en `carniceria-hub` (Electron) y en la báscula Android. El endpoint
`/api/v1/hub/customers/*` ya existe y **no** consultará este flag en esta
entrega; se trata por separado.

## Tests

- `tests/Feature/Caja/ClientesModuleTest.php` — acceso con flag on/off (200 vs 403), listado filtrado por sucursal, alta y edición.
- `tests/Feature/Caja/CobroGlobalCajeroTest.php` — cobro FIFO exitoso, 403 sin turno abierto, 403 al intentar cancelar, 404/403 en `clientes.precios.*`.
- `tests/Feature/Empresa/BranchCashierModulesTest.php` — extender con el toggle nuevo.
- Suites existentes de clientes/cobro global: deben pasar sin cambios tras el refactor a traits.
