# Clientes — Cobro global de cliente

Feature que permite al cajero registrar un pago global del cliente distribuido FIFO entre sus ventas con saldo. El cobro se representa en dos niveles:

- **Padre**: `CustomerPayment` — el movimiento real (monto recibido, cambio, método, folio `CG-XXX`).
- **Hijos**: `Payment` tradicionales con FK `customer_payment_id` apuntando al padre.

Esto preserva la trazabilidad (en historial aparece un cobro agrupado, no N pagos sueltos) sin romper el corte de caja existente.

## Arquitectura

### Schema

```
customer_payments
  id · tenant_id · branch_id · customer_id · user_id (cajero)
  folio (CG-00042 único por branch)
  method (cash | card | transfer)
  amount_received · amount_applied · change_given (decimal 12,2)
  sales_affected_count
  notes (nullable)
  timestamps + soft-delete

payments (columna nueva)
  customer_payment_id BIGINT NULL FK → customer_payments
  - NULL = pago single-sale tradicional (backward compat)
  - NOT NULL = hijo de un cobro global
```

### Invariantes

- `amount_applied + change_given = amount_received`
- `SUM(payments.amount WHERE customer_payment_id = X) = customer_payments.amount_applied`
- Todos los children comparten `method` con el padre
- `ON DELETE SET NULL` en la FK es defensivo — en operación normal se soft-deletean padre e hijos juntos

### Cálculo del cambio

- Solo aplica en `method = 'cash'`
- `change_given = max(amount_received - total_pending_seleccionado, 0)`
- Con `card` o `transfer`: si `amount_received > total_pending` → 422 (no hay cambio)

### FIFO con exclusiones

- Se cargan las ventas no canceladas con `amount_pending > 0`, ordenadas `created_at ASC`
- El request puede incluir `excluded_sale_ids[]`: esas ventas no participan de la distribución
- La distribución reparte `amount_to_apply = min(amount_received, total_pending_seleccionado)` iterando FIFO hasta agotar

## Endpoints

| Método | Ruta | Nombre | Devuelve |
|---|---|---|---|
| POST | `/sucursal/clientes/{c}/cobro-global` | `sucursal.clientes.cobro-global` | `{customer_payment, applied[]}` — 201 |
| GET  | `/sucursal/clientes/{c}/cobros-globales/{cp}` | `sucursal.clientes.cobro-global.show` | Detalle para modal, con `applications[]` |
| DELETE | `/sucursal/clientes/{c}/cobros-globales/{cp}` | `sucursal.clientes.cobro-global.cancel` | Cancela cobro: soft-delete children + parent, recalcula sales y shifts afectados |
| GET  | `/sucursal/clientes/{c}/pagos` (modificado) | `sucursal.clientes.pagos` | Ahora devuelve `recent_movements[]` con `type: 'global' \| 'single'` |
| GET  | `/sucursal/clientes/{c}/stats` (modificado) | `sucursal.clientes.stats` | Agrega `current_user_shift_open: bool` |

### Request `POST cobro-global`

```json
{
  "amount_received": 1000.00,
  "method": "cash",
  "excluded_sale_ids": [5, 7],
  "notes": "opcional"
}
```

### Response (201)

```json
{
  "customer_payment": {
    "id": 42, "folio": "CG-00042", "method": "cash",
    "amount_received": 1000.00, "amount_applied": 870.00,
    "change_given": 130.00, "sales_affected_count": 3,
    "created_at": "..."
  },
  "applied": [
    {"sale_id": 5, "folio": "S-00101", "amount": 200.00, "completed": true, "new_pending": 0},
    {"sale_id": 8, "folio": "S-00103", "amount": 350.00, "completed": true, "new_pending": 0},
    {"sale_id": 11, "folio": "S-00107", "amount": 320.00, "completed": true, "new_pending": 0}
  ]
}
```

## Reglas de negocio

- **Rol requerido**: `admin-sucursal | admin-empresa | superadmin` (consistente con `PaymentController@store`).
- **Turno abierto obligatorio**: 403 si el user no tiene un `CashRegisterShift` con `closed_at = null`.
- **Branch scope**: `customer.branch_id === auth.user.branch_id`; 403 cross-branch.
- **Método habilitado**: debe estar en `branches.payment_methods_enabled`.
- **Folio monotónico**: generado con `withTrashed()->count() + 1` para evitar reúso cuando fase 2 traiga cancelaciones.
- **Concurrencia**: `pg_advisory_xact_lock(branch_id)` + `lockForUpdate()` sobre las sales.
- **Ventas canceladas**: excluidas silenciosamente aunque se pasen en `excluded_sale_ids`.
- **Ventas ya saldadas mid-transaction**: `sale->fresh()->amount_pending > 0` como defensa en profundidad.

## Bloqueo de edición de children

`PaymentController@update` y `@destroy` ahora verifican `$payment->customer_payment_id`:
- Si es NOT NULL → rechazan con mensaje: "Este pago es parte del cobro CG-00042 y no puede editarse/eliminarse individualmente."
- Esto preserva invariantes (method consistente, suma = amount_applied).

## UI

### Entry point
Botón **"Registrar pago"** en el header del tab **Finanzas** del módulo Clientes:
- Visible solo si `stats.pending_sales_count > 0`
- Disabled con tooltip si `!stats.current_user_shift_open`

### Modal de registro (`CustomerPaymentModal.vue`)
- Banner rojo con adeudado total + count de ventas
- Segmented control de métodos (solo los habilitados en branch)
- Input grande `h-14` con prefix `$` y botón rápido "Saldar todo"
- Lista de ventas con checkboxes (marcadas por default), preview FIFO en vivo:
  - Monto a aplicar por venta
  - Saldo resultante (verde si $0, amber si queda parcial, tachado si excluida)
- Banner amarillo de cambio (solo efectivo + excedente)
- Botón submit dinámico: `Cobrar $X`, `Cobrar $X · cambio $Y`, `Cobrar $X (parcial)`
- Validación en vivo: tarjeta/transferencia con excedente → input rojo + mensaje

### Modal de detalle (`GlobalPaymentDetailModal.vue`)
- Header con folio, método, fecha y cajero
- 3 cards: Recibido / Aplicado / Cambio
- Lista de ventas aplicadas con folio, monto aplicado, saldo resultante, badge de estado
- Click en venta → abre `SaleDetailModal` (ya existente)

### Feed en tab Finanzas
Lista unificada `recent_movements` con diferenciación visual por `type`:
- **`global`**: card con fondo degradado amber/rojo, icono de documento, chip con N ventas, monto aplicado + cambio si aplica. Click → `GlobalPaymentDetailModal`.
- **`single`**: card simple con icono por método. Click → `SaleDetailModal`.

## Impacto en sistema existente

### Cero cambio en:
- Corte de caja (ambas implementaciones siguen sumando `payments.method * amount`; los children aparecen como pagos normales)
- Reportes de ingresos diarios
- Flujo de cancelación de venta (`Payment::withTrashed()` sigue agarrando children)
- `WorkbenchController@assignCustomer` (protección via `lockForUpdate` durante transacción)
- `PaymentController@store` (intacto — sigue siendo para pagos single-sale)

### Cambio mínimo aditivo en:
- `PaymentController@update/@destroy` → guard extra contra children
- `CustomerStatsController@stats` → agrega `current_user_shift_open`
- `CustomerStatsController@payments` → devuelve `recent_movements` unificado en vez de `recent_payments`
- `CustomerController@index` → expone `allowedPaymentMethods` como prop

## Casos edge manejados

- Cliente sin pending → botón oculto; 422 si llamada directa
- Sin shift abierto → botón disabled + 403 backend
- Excluir todas las ventas → submit disabled + 422 backend
- Monto = 0 → submit disabled + 422
- Efectivo con excedente → registra solo pending, devuelve change_given
- Tarjeta/transferencia con excedente → 422
- Venta cancelada mid-transaction → excluida por query
- Venta saldada mid-transaction → `fresh()->amount_pending > 0` skip defensivo
- Dos cajeros simultáneos → advisory lock serializa
- Rounding decimales → `decimal(12,2)` + `min()` natural
- Error mid-distribution → rollback completo de padre + children

## Cancelación de un cobro global

Implementado. Endpoint `DELETE /sucursal/clientes/{c}/cobros-globales/{cp}`.

**Flujo transaccional**:
1. `pg_advisory_xact_lock(branch_id)`
2. Soft-delete de cada `Payment` hijo (`customer_payment_id = {cp}`)
3. `lockForUpdate` sobre las ventas afectadas + recalc vía `SalePaymentService::recalculate`
4. Update del padre con `cancelled_at`, `cancelled_by`, `cancel_reason`; soft-delete
5. Post-commit: `recalculateAffectedShifts(sale)` por cada venta (copia de la lógica del Workbench — reabre el cálculo de cortes cerrados); broadcast `SaleUpdated` por venta

**Reglas**:
- Requiere rol admin-sucursal/empresa/superadmin
- NO requiere turno abierto (el cobro pudo haber ocurrido días antes)
- Requiere `cancel_reason` (obligatorio, max 500 chars)
- Un cobro ya cancelado no se puede cancelar dos veces (422)
- Las ventas que quedan con saldo positivo vuelven de `Completed` → `Active` automáticamente (vía `recalculate`)
- Soft-deleted ya no aparecen en el feed `recent_movements` (scope automático de SoftDeletes)

**UI**: botón "Cancelar cobro" en el footer de `GlobalPaymentDetailModal` → overlay inline con textarea de motivo + confirm.

## Fase 2 (diferida)

- **Ticket imprimible**: PDF/escpos con folio CG-XXX y desglose
- **Reporte de cobros globales del día**: filtrable por cajero, rango, método
- **Feature tests (19)**: requiere scaffolding de factories (Tenant, Branch, Customer, Sale, Payment, Role) — ticket aparte

## Bugs pre-existentes (no tocados por esta feature)

- `Caja/ShiftController@close` suma por `sales.payment_method` en vez de `payments.method` — preexistente, no empeora con esta feature
- `WorkbenchController@assignCustomer` solo advierte con warning si venta tiene pagos — preexistente; nuestro `lockForUpdate` lo evita durante transacción

## Archivos

### Nuevos
- `database/migrations/2026_04_14_000002_create_customer_payments_table.php`
- `database/migrations/2026_04_14_000003_add_customer_payment_id_to_payments_table.php`
- `app/Models/CustomerPayment.php`
- `app/Services/SalePaymentService.php`
- `app/Http/Requests/RegisterCustomerPaymentRequest.php`
- `app/Http/Controllers/Sucursal/CustomerPaymentController.php`
- `resources/js/Components/Clientes/CustomerPaymentModal.vue`
- `resources/js/Components/Clientes/GlobalPaymentDetailModal.vue`

### Modificados
- `routes/web.php` — 2 rutas nuevas
- `app/Models/Payment.php` — relación `customerPayment()` + fillable
- `app/Http/Controllers/Sucursal/PaymentController.php` — guards en `update`/`destroy`
- `app/Http/Controllers/Sucursal/CustomerStatsController.php` — `stats` + `payments` actualizados
- `app/Http/Controllers/Sucursal/CustomerController.php` — expone `allowedPaymentMethods`
- `resources/js/composables/useCustomerStats.js` — método `registerGlobalPayment`
- `resources/js/Pages/Sucursal/Clientes/Index.vue` — botón + feed mixto + modales
