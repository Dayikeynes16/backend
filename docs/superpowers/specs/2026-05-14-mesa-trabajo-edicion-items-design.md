# Mesa de Trabajo — Edición de items + auditoría

**Fecha:** 2026-05-14
**Estado:** aprobado para implementar
**Alcance:** que admin-sucursal pueda editar/agregar/eliminar items de una venta Active/Pending directamente desde la Mesa de Trabajo, con auditoría completa de los cambios y configuración del flujo por sucursal.

## Motivación

Hoy los items de una venta son inmutables después de `WorkbenchController::store()`. El único camino para corregir un error de captura es cancelar la venta completa y rehacerla — pérdida de folio y trazabilidad. Se necesita una vía controlada para editar items conservando historia y responsabilidad.

## Reglas de negocio

- **Solo ventas `Active` o `Pending`** se pueden editar. `Completed` y `Cancelled` rechazan con 422. Para editar una venta cobrada, el admin primero elimina el pago (flujo existente) — esto la regresa a `Active` automáticamente vía `SalePaymentService::recalculate()`.
- **Solo admin-sucursal, admin-empresa, superadmin.** El cajero no toca items, igual que hoy.
- **Lock obligatorio**: el usuario que edita debe tener el `locked_by` activo de la venta.
- **Motivo obligatorio al eliminar** siempre, sin importar la config. Eliminar un item es la mutación de mayor impacto.
- **Edición a `quantity = 0`** rechazada; debe usarse el endpoint de eliminar.
- **No-op** (editar con valores idénticos) rechazado con 422.

## Datos

### Nueva columna en `branches`

`sale_item_edit_reason_mode` (string) — uno de `disabled` · `optional` · `required`. Default `optional`. Configurable por admin-empresa por sucursal.

### Nuevas columnas en `sale_items`

- `created_by` (FK users nullable) — quién agregó el item. Backfill: los items existentes reciben el `sales.user_id` de su venta.
- `updated_by` (FK users nullable) — última edición.
- `deleted_by` (FK users nullable).
- `deleted_at` (timestamp) — soft delete. Items eliminados quedan en BD para historia.

### Nueva tabla `sale_item_changes` (append-only)

```sql
id BIGINT PK
sale_id FK sales cascade
sale_item_id FK sale_items nullOnDelete   -- preservar referencia aún si el item se borra
event VARCHAR(20)                          -- 'added' | 'updated' | 'removed'
before JSONB nullable                      -- snapshot ANTES (null para added)
after JSONB nullable                       -- snapshot DESPUÉS (null para removed)
diff JSONB nullable                        -- {'field': [before, after]} (solo updated)
reason VARCHAR(500) nullable
user_id FK users
created_at, updated_at

INDEX (sale_id, created_at)
INDEX (user_id)
```

Snapshot incluye: `product_id`, `product_name`, `presentation_id`, `unit_type`, `quantity`, `unit_price`, `subtotal`. Es JSON congelado — no JOIN para reconstruir.

## Endpoints

```
POST   /{tenant}/sucursal/mesa-de-trabajo/ventas/{sale}/items          → store
PATCH  /{tenant}/sucursal/mesa-de-trabajo/ventas/{sale}/items/{item}   → update
DELETE /{tenant}/sucursal/mesa-de-trabajo/ventas/{sale}/items/{item}   → destroy
```

Nombres: `sucursal.workbench.items.{store|update|destroy}`. Todos exigen rol admin-sucursal+ por el grupo de middleware existente. **No se expone en `/caja`**.

### Validación (Form Requests)

**StoreSaleItemRequest**: `product_id` requerido y existe del tenant. `presentation_id` opcional. `quantity > 0`. `unit_price >= 0`. `reason` nullable string máx 500.

**UpdateSaleItemRequest**: `quantity > 0`. `unit_price >= 0`. Custom rule: al menos uno cambia respecto al actual; `quantity != 0` (sugerir remove). `reason` nullable.

**DestroySaleItemRequest**: `reason` requerido (regla de negocio dura).

Si `branch.sale_item_edit_reason_mode === 'required'`, el Form Request adicionalmente exige `reason` no vacío para store/update.

Si `=== 'disabled'`, el endpoint ignora `reason` aunque venga.

## Servicio: `SaleItemEditor`

`app/Services/SaleItemEditor.php`. Tres métodos públicos: `add`, `update`, `remove`. Cada uno corre dentro de una transacción y hace:

1. `Sale` cargado con `lockForUpdate()`.
2. Validar `status ∈ {Active, Pending}` → si no, lanzar `EditNotAllowedException` (mapea a 422 con mensaje específico).
3. Validar `locked_by === user.id` → si no, 423.
4. Capturar `before` (snapshot del item) o `null`.
5. Aplicar mutación (`create`, `update`, soft `delete`).
6. Recalcular `sale.total = SaleItem::where('sale_id', $sale->id)->whereNull('deleted_at')->sum('subtotal')`.
7. Delegar a `app(SalePaymentService::class)->recalculate($sale, $user)` para que ajuste `amount_paid`/`amount_pending` y mueva el `status` con la misma lógica que ya existe para pagos.
8. Capturar `after` y calcular `diff` (en `update`).
9. `SaleItemChange::create([...])`.
10. Dispatch `SaleUpdated` (try/catch — no bloquea si Reverb falla, igual que el resto del sistema).

`SalePaymentService::recalculate` ya existe — lo extraemos del `PaymentController::recalculate` si todavía no es público (verificar en implementación).

## Frontend

### Componentes nuevos

- `Components/Sucursal/SaleItemReasonField.vue` — chips de motivos preset (Error de captura · Cliente cambió · Producto agotado · Ajuste de precio · Devolución parcial) + "Otro motivo" con textarea. Prop `mode: 'disabled' | 'optional' | 'required'` controla si se muestra y si es obligatorio. Estilo calcado de `CancelSaleDialog.vue`.

- `Components/Sucursal/SaleItemAddModal.vue` — reutiliza el picker de productos existente. Campos: producto/presentación, cantidad, precio (sugerido con `customerPreferentialPrice` si la venta tiene cliente, override permitido). Incluye `SaleItemReasonField`.

- `Components/Sucursal/SaleItemEditModal.vue` — cantidad + precio con subtotal calculado en vivo abajo. Incluye `SaleItemReasonField`.

- `Components/Sucursal/SaleItemDeleteDialog.vue` — confirmación con tarjeta del item arriba (nombre, cantidad, subtotal). `SaleItemReasonField` siempre `required` aunque la sucursal esté en `optional` (regla dura).

- `Components/Sucursal/SaleItemHistoryModal.vue` — lista cronológica de `sale_item_changes` de la venta. Cada evento: icono según tipo, diff legible (`Cantidad: 1 → 2`), usuario, tiempo relativo, motivo en cursiva.

### `Workbench.vue` (modificado)

- En la tabla de items, columna nueva al final con icon-buttons `editar`/`eliminar` (visibles solo si admin + venta editable + tiene lock).
- Botón `+ Agregar producto` arriba de la tabla, mismo condicional.
- Chip `Editado` con tooltip al lado del nombre si el item tiene `updated_at != created_at` o `updated_by != null`.
- Items con `deleted_at` se renderizan al final, tachados, en gris, con badge `Eliminado por X · 2h`. Filtrable con un toggle "Ocultar eliminados" (default oculto en cajero, visible en admin).
- Link "Ver historial de cambios" debajo de la tabla → abre `SaleItemHistoryModal`.
- Si la venta está `Completed`, banner sutil: *"Para modificar items, elimina el pago desde la sección Pagos →"* con link directo.
- Badge `Sobrepagada $X` si `amount_paid > total` tras una edición.

### `Empresa/Sucursales/Edit.vue` (modificado)

Sección nueva "Edición de items en Mesa de Trabajo" con segmented control de tres opciones:
- **Deshabilitado** — no se pide motivo, solo se registra quién y cuándo.
- **Motivo opcional** (default) — chips de motivos visibles, textarea, pero se puede dejar vacío.
- **Motivo obligatorio** — chips visibles, motivo no vacío requerido.

Helper text: *"Aplica a agregar y editar items. Eliminar siempre pide motivo, sin importar esta configuración."*

## Permisos UI

| Acción | admin-sucursal+ | cajero |
|---|---|---|
| Ver tabla de items | ✓ | ✓ |
| Ver chip "Editado" | ✓ | ✓ |
| Ver items eliminados | ✓ | ✗ |
| Ver "Historial completo" | ✓ | ✗ |
| Botones add/edit/remove | ✓ (solo con lock) | ✗ |

## Tests

- `tests/Feature/Sucursal/SaleItemAddTest.php` — happy path, validaciones, estado de venta, rol, modo de motivo.
- `tests/Feature/Sucursal/SaleItemUpdateTest.php` — cambio de cantidad/precio/ambos, no-op rechazado, recálculo de total y amount_pending con pagos previos.
- `tests/Feature/Sucursal/SaleItemRemoveTest.php` — soft-delete, deleted_by, motivo obligatorio, último item permitido, evento `removed`.
- `tests/Feature/Sucursal/SaleItemAuditTrailTest.php` — múltiples eventos, orden, before/after correctos, persistencia tras delete del item.
- `tests/Feature/Empresa/BranchEditReasonModeTest.php` — admin-empresa cambia el modo, persiste, endpoints lo respetan.
- `tests/Feature/Sucursal/SaleItemBroadcastTest.php` — `SaleUpdated` dispara en las tres mutaciones (asserts contra Event::fake()).

Frontend: verificación manual con `npm run build` + recorrido en `/sucursal/mesa-de-trabajo` con DevTools.

## Decisiones explícitas

- `total < amount_paid` tras editar **no bloquea** — la venta queda con `amount_pending = 0` y un badge "Sobrepagada $X" visible. La devolución se gestiona fuera del sistema. (Patrón consistente con cómo se manejan hoy los pagos en exceso.)
- Items eliminados **no se purgan** — se mantienen soft-deleted para que la auditoría siempre tenga el contexto del item al que se refiere.
- **No incluimos items soft-deleted en el payload broadcast** (`SaleUpdated`) — para no romper la UI del cajero. El admin que abre el historial hace query aparte.

## Fuera de alcance

- Edición desde Caja (cajero sigue sin tocar items).
- Edición de ventas `Completed` o `Cancelled`.
- Bulk operations (editar varios items a la vez).
- Notificaciones a otros usuarios cuando un item se edita (solo refresh por broadcast).
- Reportes/analítica sobre quién más edita items — vive en la tabla, se consulta cuando haga falta.

## Orden de implementación (commits)

1. Migraciones + modelo `SaleItemChange`.
2. `SaleItemEditor` + tests unitarios del servicio.
3. Controlador `SaleItemController` + Form Requests + rutas + tests de feature.
4. Config en `Branch` + UI `Empresa/Sucursales/Edit.vue` + test.
5. Componentes Vue + modales + integración en `Workbench.vue`.
6. Modal de historial.
7. `npm run build` + recorrido.
