# Clientes — Vista detalle con dashboard

Extensión del módulo Clientes existente (ver `specs/2026-04-12-clientes-precios-preferenciales-design.md`). Convierte el panel derecho en un dashboard por pestañas con métricas, historial, productos top y finanzas.

## Arquitectura

- Layout existente intacto: lista izquierda + detalle derecha.
- El detalle ahora tiene 4 pestañas: **Resumen, Compras, Productos, Finanzas**.
- Cada pestaña hace lazy-load vía endpoints JSON dedicados (ver `CustomerStatsController`).
- La primera pestaña (Resumen) se carga al seleccionar cliente; las demás al cambiar de tab.
- Cambiar de cliente aborta requests en vuelo (`AbortController`) y resetea estado.

## Cálculo del ahorro (snapshot histórico)

Se añadió `sale_items.original_unit_price` (migración `2026_04_14_000001`) para snapshotear el precio del catálogo al momento de la venta.

- Se escribe al crear la venta (`WorkbenchController@store`, `Api/SaleController@store`).
- **No** se modifica al asignar/desasignar cliente (`assignCustomer`) — solo cambia `unit_price`.
- Ahorro por item = `max(original_unit_price - unit_price, 0) * quantity`.
- Ahorro total del cliente = suma sobre items de ventas **no canceladas**.
- `% descuento promedio = total_saved / sum(original_unit_price * quantity) * 100`.

Filas previas a la migración tienen `original_unit_price = products.price` actual como aproximación (o `unit_price` si el producto fue eliminado). Esto puede subestimar/sobrestimar ahorro según cambios posteriores de catálogo — precisión completa a partir de ventas creadas post-migración.

## Reglas de negocio

- **Ventas canceladas** se excluyen de todas las métricas (stats, historial, productos top, adeudado).
- **Frecuencia** se calcula con dos vistas:
  - `avg_days_between` = `span(first, last) / (sale_count - 1)` si hay ≥ 2 ventas.
  - `sales_per_month` = `sale_count / span_months`.
- **Adeudado** se deduce de `sales.amount_pending > 0` (no hay tabla de crédito separada).
- **Estado de pago por venta** (badge): pagada si `pending <= 0 && paid > 0`; parcial si `pending > 0 && paid > 0`; pendiente si `paid == 0`.

## Endpoints

Todos bajo `sucursal.clientes.*`, con scope `branch_id = auth.user.branch_id`.

| Método | Ruta | Nombre | Devuelve |
|---|---|---|---|
| GET | `/sucursal/clientes/{customer}/stats` | `clientes.stats` | Totales, ahorro, frecuencia, última venta, adeudado |
| GET | `/sucursal/clientes/{customer}/historial?from&to&per_page` | `clientes.historial` | Paginación Laravel con items + payments |
| GET | `/sucursal/clientes/{customer}/productos-top?limit` | `clientes.productos-top` | Agregación por producto con cantidad/gasto/ahorro |
| GET | `/sucursal/clientes/{customer}/pagos` | `clientes.pagos` | Ventas con saldo + últimos 100 pagos |
| GET | `/sucursal/clientes/{customer}/ventas/{sale}` | `clientes.venta-detalle` | Detalle de venta (items + pagos + cajero) para el modal |

Todos retornan JSON. El `Accept: application/json` se envía desde `useCustomerStats.js`.

## Archivos clave

### Backend
- `database/migrations/2026_04_14_000001_add_original_unit_price_to_sale_items_table.php`
- `app/Models/SaleItem.php` — fillable + cast de `original_unit_price`
- `app/Http/Controllers/Sucursal/CustomerStatsController.php`
- `app/Http/Controllers/Sucursal/WorkbenchController.php` — snapshot al crear
- `app/Http/Controllers/Api/SaleController.php` — snapshot al crear desde API
- `routes/web.php` — 4 rutas nuevas

### Frontend
- `resources/js/Pages/Sucursal/Clientes/Index.vue` — refactor con tabs
- `resources/js/Components/Clientes/StatCard.vue` — card métrica reutilizable
- `resources/js/Components/Clientes/SaleDetailModal.vue` — modal de venta (lazy fetch)
- `resources/js/Components/Clientes/DateRangePicker.vue` — chips preset + inputs grandes
- `resources/js/Components/Clientes/PriceEditor.vue` — edición inline grande con diff en vivo
- `resources/js/composables/useCustomerStats.js` — fetch + abort + cache por cliente

## UX notes

- Finanzas: cualquier fila de "Ventas con saldo" o "Últimos pagos" abre `SaleDetailModal` en 1 click (lazy load, sin recarga).
- Compras: filas clickeables → modal. Se eliminó el expand inline — la info va al modal con padding generoso y banner de ahorro total por venta.
- DateRangePicker: 7 presets (Hoy, Ayer, 7d, 30d, Este mes, Mes pasado, Todo) + inputs grandes (h-11). Valida que `from <= to`. Botón Aplicar muestra spinner mientras carga.
- PriceEditor: se expande a card grande con input h-12, muestra diff en vivo ("Ahorra $30 (23%)"), Guardar/Cancelar altos con Enter/Esc como atajos.
- Eliminación de precio: ahora pasa por confirm dialog en lugar de delete directo.

## Performance

- Agregaciones SQL puras, sin loops PHP.
- `topProducts` usa `GROUP BY product_id` con `MAX(...)` para los snapshots de nombre/unidad.
- Historial paginado (25 por página por defecto, máx 100).
- Sin N+1: `with(['items', 'payments'])` en historial.

## Casos edge manejados

- Cliente sin compras → stats en 0, tabs con empty state.
- Cliente sin precios preferenciales → columna "ahorro unitario" muestra `—`.
- Item cuyo producto fue eliminado (`product_id` null) → usa `product_name` snapshoteado; ahorro = 0 si no hay `original_unit_price`.
- Solo 1 venta → `avg_days_between = null`, `sales_per_month = 1`.
- División por cero → guards en controller y frontend.
