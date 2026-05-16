# Clientes — Rediseño: lista amplia + página detalle dedicada

**Fecha:** 2026-05-16
**Estado:** aprobado para implementar
**Alcance:** UX del módulo Clientes en Sucursal. Cero cambios al núcleo del API existente: los endpoints `stats`, `history`, `topProducts`, `payments`, `saleDetail`, `cobro-global` y de precios preferenciales se mantienen idénticos.

## Motivación

`Sucursal/Clientes/Index.vue` empotra hoy lista (380px) + detalle con 4 tabs (Resumen / Compras / Productos / Finanzas) + KPIs arriba en una sola pantalla. El detalle queda en ~900px y las secciones internas (grid de 6 KPIs en 3 columnas, tablas de 6 columnas, listas de movimientos, charts, edición inline de precios) compiten por ese ancho. La lista tampoco escala: el detalle no tiene URL propia, no es compartible y la navegación se siente forzada.

El módulo backend ya está completo y bien instrumentado — la inversión necesaria es de presentación.

## Decisiones tomadas

- **Dos páginas en lugar de una panel-en-panel**: `/clientes` (lista) y `/clientes/{customer}` (detalle).
- **Detalle con hero superior + tabs**: el hero gana visibilidad sin scroll, los precios preferenciales viven fuera de los tabs como sección propia, y los tabs se reducen de 4 a 3 (sin "Resumen" porque sus KPIs ya están en el hero).
- **Lista con tabla amplia tipo Cajeros**, no grid de tarjetas.
- **Reuso máximo**: composable `useCustomerStats`, componentes `CustomerPaymentModal`, `GlobalPaymentDetailModal`, `SaleDetailModal`, `PriceEditor`, `StatCard` se mantienen.

## Arquitectura

### Rutas

```
GET  /{tenant}/sucursal/clientes              → CustomerController@index   sucursal.clientes.index
GET  /{tenant}/sucursal/clientes/{customer}   → CustomerController@show    sucursal.clientes.show  (nueva)
```

El resto de rutas (`store`, `update`, `destroy`, stats/history/topProducts/payments/precios/cobro-global) sin cambios.

### Backend

**`CustomerController::show(Customer $customer): Response` (nueva)**
- `authorizeBranchAccess($customer)`.
- Carga eager `prices.product:id,name,price`.
- Inyecta `stats_seed`: un payload pequeño con los KPIs del hero (`total_spent`, `sale_count`, `avg_ticket`, `total_owed`, `last_sale_at`, `first_sale_at`) calculados con una sola query agregada para que la página cargue ya con los números clave sin esperar al AJAX. El composable `useCustomerStats` sigue cargando el detalle completo en segundo plano cuando hace falta.
- `Inertia::render('Sucursal/Clientes/Show', [...])`.

**`CustomerController::index`** — se simplifica:
- Deja de cargar `with(['prices.product:id,name,price'])` (la lista ya no muestra los precios, solo su conteo).
- Agrega `withCount('prices as preferential_prices_count')` y `withMax('sales as last_sale_at', 'created_at')`.

No se tocan los servicios, ni los demás controladores, ni las migraciones.

### Frontend

**Página nueva**: `resources/js/Pages/Sucursal/Clientes/Show.vue`. Composition API, `<script setup>`. Recibe `customer`, `tenant`, `canRegisterPayment` y `stats_seed`. Carga lazy `stats`, `history`, `topProducts`, `payments` con el composable existente.

**Página reescrita**: `resources/js/Pages/Sucursal/Clientes/Index.vue` — solo lista.

**Componentes nuevos** (`resources/js/Components/Clientes/`):
- `CustomerHero.vue` — identidad + acciones + 4 KPIs.
- `CustomerPreferentialPrices.vue` — sección fuera de tabs.
- `CustomerPurchasesTab.vue`.
- `CustomerProductsTab.vue`.
- `CustomerFinancesTab.vue`.

Cada tab vive en su propio archivo para mantenibilidad y para que cada uno sea simple de leer.

## Componentes

### Lista `/clientes`

- Header: título, subtítulo, botón rojo "Nuevo cliente".
- Fila de 4 KPIs (`Total · Activos · Con deuda · Deuda total`).
- Toolbar: buscador con debounce, chips `Todos / Activos / Inactivos / Con deuda`, select de orden, contador.
- Tabla con columnas: Cliente (avatar+inicial+badge), Teléfono, Deuda, Última compra, # compras, Precios pref. Icon-buttons editar/eliminar al final con `opacity-0 group-hover:opacity-100`.
- Filas como `<Link :href="route('sucursal.clientes.show', ...)">`.
- Empty state diferenciado: sin resultados de búsqueda vs sin clientes registrados (este último con CTA).
- Paginación al pie.
- Modales `CustomerCreateModal` y `CustomerEditModal` (puede reusarse uno solo con prop `mode`).

### Detalle `/clientes/{customer}`

**Hero** (`CustomerHero.vue`):
- Back-button + breadcrumb `Clientes / {Nombre}`.
- Identidad: avatar 56px gradient + nombre `text-2xl font-bold` + teléfono link wa.me + badges (`Activo/Inactivo`, `Con deuda $X`) + "Cliente desde …" y "Última compra hace …".
- Acciones: botón rojo "Registrar cobro" (deshabilitado si no hay deuda o turno cerrado, con hint), botón WhatsApp con ícono, menú kebab (Editar · Marcar inactivo · Eliminar).
- 4 KPIs en grid (`Deuda actual`, `Total gastado`, `Compras`, `Ticket promedio`) con `delta`/hint pequeño.

**Precios preferenciales** (`CustomerPreferentialPrices.vue`):
- Card propia con título + contador + botón `+ Agregar precio`.
- Grid 2 columnas en desktop, 1 en mobile. Cada tarjeta: nombre del producto, "Precio catálogo $X" tachado, "Tu precio $Y" en grande, badge verde con `% ahorro`. Icon-buttons editar/eliminar (PriceEditor existente).
- Empty state con CTA.

**Tabs** — 3 (sin "Resumen"):

1. **Compras** (`CustomerPurchasesTab.vue`): filtros rango de fechas + estado, tabla amplia con `Fecha · Folio · # items · Total · Pagado · Estado · →`. Click abre `SaleDetailModal`. Cursor pagination.

2. **Productos** (`CustomerProductsTab.vue`): KPI "Top producto" + "Ahorro acumulado". Tabla ordenable: producto, veces comprado, cantidad total, gastado, ahorro. Botón secundario "Ver como gráfico" abre vista de barras con `ChartCard`.

3. **Finanzas** (`CustomerFinancesTab.vue`): 3 mini-KPIs (Total pagado, # pagos individuales, # cobros globales). Sección "Ventas pendientes" con tabla (folio, fecha, total, pagado, pendiente, días desde — ámbar/rojo según antigüedad). Sección "Movimientos recientes" como timeline vertical (icono según tipo, monto, método, cajero, fecha relativa). Click sobre cobro global abre `GlobalPaymentDetailModal`.

### Responsive

- `≥ lg`: hero ancho completo, precios 2 columnas, tabs con tabla cómoda.
- `< lg`: hero apila identidad/acciones, KPIs 2x2, precios 1 columna, tabs con header scroll horizontal si no caben.

## Tests

**Backend nuevo**:
- `tests/Feature/Sucursal/CustomerShowControllerTest.php`:
  - `show` carga cliente con precios + stats_seed.
  - 403 cuando el cliente pertenece a otra sucursal.
  - `index` ya no carga precios pero sí `preferential_prices_count` y `last_sale_at` (test mínimo de presencia en props).

**Sin cambios**: tests existentes de stats/history/topProducts/payments/precios/cobro-global siguen pasando porque esos endpoints no se tocan.

**Frontend**: verificación manual con `npm run build` + recorrido en `/sucursal/clientes` y `/sucursal/clientes/{id}` (móvil y desktop).

## Fuera de alcance

- Métricas globales de clientes a nivel empresa (existen en `Empresa/Metricas/Clientes`, no se tocan).
- Notificaciones automáticas a clientes (WhatsApp, email).
- Importación masiva.
- Cambios al flujo de cobro global (la página solo lo invoca).

## Orden de implementación (commits)

1. Backend: ruta `show` + simplificación de `index` + test.
2. `Show.vue` con `CustomerHero.vue` (sin tabs, con precios).
3. `CustomerPreferentialPrices.vue` integrado.
4. Tabs: `Compras`, `Productos`, `Finanzas` (un commit cada uno o todos juntos según tamaño).
5. Reescritura de `Index.vue` como tabla amplia.
6. `npm run build` + recorrido.
