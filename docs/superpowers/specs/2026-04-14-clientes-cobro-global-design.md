# Cobro global de cliente — diseño

**Fecha**: 2026-04-14
**Autor**: Brainstorming sesión (Sebastián + Claude)
**Estado**: Aprobado para implementación (rev 2 — opción B con entidad padre)
**Módulo afectado**: Clientes / Finanzas / Pagos

---

## Problema

Hoy el cajero registra pagos venta por venta (`PaymentController@store`). Cuando un cliente llega a pagar varias ventas acumuladas (típico en venta a crédito informal), el cajero debe abrir cada venta, ingresar el abono, repetir. Proceso lento, propenso a errores.

Si ingenuamente distribuimos el dinero en N `Payment` sueltos (una por venta), se resuelve la fricción del cajero pero **se pierde la trazabilidad del cobro real**: el historial muestra 10 pagos chiquitos en vez de un cobro grande.

## Objetivo

1. Permitir al cajero registrar **un pago global del cliente** con un monto y un método, distribuido FIFO entre ventas con saldo.
2. Representar el cobro en **dos niveles**: un `CustomerPayment` padre (movimiento real del cliente) + N `Payment` hijos (aplicaciones a cada venta).
3. El historial, reportes y UX muestran el padre como unidad primaria; el detalle de distribución está a 1 click.

## Decisiones tomadas (brainstorming)

| # | Decisión | Alternativa descartada |
|---|---|---|
| 1 | Overpayment en efectivo genera cambio visible; se registra solo el monto adeudado | Rechazar; acreditar a saldo a favor |
| 2 | FIFO con posibilidad de excluir ventas por checkbox | FIFO estricto; selección manual completa |
| 3 | Un solo método de pago por cobro global | Split multi-método |
| 4 | **Entidad padre `CustomerPayment` + `Payment` hijos vía FK `customer_payment_id`** (opción B) | A: solo Payments sueltos (pierde trazabilidad). C: solo `group_id` (pierde metadata persistida del padre) |

## Arquitectura

### Schema

**Nueva tabla**:
```sql
CREATE TABLE customer_payments (
    id                    BIGINT PK,
    tenant_id             BIGINT NOT NULL REFERENCES tenants(id),
    branch_id             BIGINT NOT NULL REFERENCES branches(id),
    customer_id           BIGINT NOT NULL REFERENCES customers(id),
    user_id               BIGINT NOT NULL REFERENCES users(id),     -- cajero
    folio                 VARCHAR NOT NULL,                          -- CG-00042 único por branch
    method                VARCHAR NOT NULL,                          -- cash | card | transfer
    amount_received       DECIMAL(12,2) NOT NULL,
    amount_applied        DECIMAL(12,2) NOT NULL,
    change_given          DECIMAL(12,2) NOT NULL DEFAULT 0,
    sales_affected_count  INT NOT NULL,
    notes                 TEXT NULL,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP,
    deleted_at            TIMESTAMP NULL,
    UNIQUE(branch_id, folio)
);
```

**Cambio mínimo a tabla existente**:
```sql
ALTER TABLE payments
  ADD COLUMN customer_payment_id BIGINT NULL
  REFERENCES customer_payments(id) ON DELETE SET NULL;
```

> `ON DELETE SET NULL` es defensivo. En el flujo normal los `Payment` children se soft-deletean (nunca hard-delete), y el padre también usa soft-delete. La cascada `SET NULL` no se ejerce en operaciones normales — está por si en el futuro algún teardown o tooling admin requiere hard-delete del padre, los children no quedan con FK huérfana.

**Invariantes** (validados en app):
- `amount_applied + change_given = amount_received`
- `SUM(payments.amount WHERE customer_payment_id = X) = customer_payments.amount_applied`
- `method` del padre consistente con todos los children
- `Payment` con `customer_payment_id IS NULL` = pago single-sale tradicional (backward compat)

### Backend

**Nuevos archivos**:
- `app/Models/CustomerPayment.php` — modelo con relaciones
- `app/Http/Controllers/Sucursal/CustomerPaymentController.php`
  - `store(RegisterCustomerPaymentRequest, Customer)` — crear cobro global
  - `show(Customer, CustomerPayment)` — detalle para modal
- `app/Http/Requests/RegisterCustomerPaymentRequest.php` — validación
- `app/Services/SalePaymentService.php` — copia verbatim del `recalculate` existente para reutilizar sin tocar `PaymentController`

**Modelo `CustomerPayment`**:
```php
class CustomerPayment extends Model {
    use SoftDeletes, BelongsToTenant;
    // Fillable: todos excepto id/timestamps
    // Relations:
    public function customer() { return $this->belongsTo(Customer::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    // Scope por branch, casts a decimal
}
```

**`Payment` agrega**:
```php
public function customerPayment() {
    return $this->belongsTo(CustomerPayment::class);
}
```

**Rutas** (`routes/web.php`, dentro del grupo `sucursal.clientes.*`):
```php
Route::post('clientes/{customer}/cobro-global',
    [CustomerPaymentController::class, 'store']
)->name('clientes.cobro-global');

Route::get('clientes/{customer}/cobros-globales/{customerPayment}',
    [CustomerPaymentController::class, 'show']
)->name('clientes.cobro-global.show');
```

### Flujo del endpoint `store`

```
store(request, customer):
    1. FormRequest valida role, datos, métodos habilitados
    2. Verificar customer.branch_id === auth.user.branch_id (403 si no)
    3. Verificar turno abierto del auth.user (403 si no)
    4. DB::transaction:
       a. pg_advisory_xact_lock(branch_id)
       b. sales = Sale::where(customer_id)
                       ->where(status != cancelled)
                       ->where(amount_pending > 0)
                       ->whereNotIn(id, excluded_sale_ids)
                       ->orderBy(created_at, 'asc')
                       ->lockForUpdate()->get()
       c. total_pending = sales.sum(amount_pending)
       d. if total_pending == 0 → 422 "No hay ventas con saldo seleccionadas"
       e. if method != 'cash' && amount_received > total_pending → 422
       f. amount_to_apply = min(amount_received, total_pending)
       g. change_given    = amount_received - amount_to_apply
       h. folio = 'CG-' + str_pad(
            CustomerPayment::withTrashed()
              ->withoutGlobalScopes()
              ->where('branch_id', branch_id)->count() + 1,
            5, '0'
          )
          # withTrashed() garantiza monotonicidad: un cobro cancelado en
          # fase 2 no libera su número para reúso.
       i. customerPayment = CustomerPayment::create({
            tenant_id, branch_id, customer_id, user_id, folio,
            method, amount_received, amount_applied: amount_to_apply,
            change_given, sales_affected_count: 0
          })
          # sales_affected_count se inicializa en 0 y se corrige al final.
          # Motivo: dentro del loop, algunas sales pueden skipearse por
          # current_pending == 0 (defensa en profundidad). El conteo
          # definitivo solo se conoce al terminar la distribución.
       j. remaining = amount_to_apply
       k. applied = []
       l. foreach sale in sales:
          - if remaining <= 0: break
          - current_pending = sale.fresh()->amount_pending
          - if current_pending == 0: continue
          - portion = min(remaining, current_pending)
          - Payment::create({
              sale_id, user_id, method, amount: portion,
              customer_payment_id: customerPayment.id
            })
          - SalePaymentService::recalculate(sale)
          - applied.push({sale_id, folio, amount: portion,
                           completed: sale.fresh()->status == Completed})
          - remaining -= portion
       m. customerPayment->update({sales_affected_count: count(applied)})
    5. After commit: foreach unique sale in applied: SaleUpdated::dispatch(sale.fresh())
    6. Return JSON: {
         customer_payment: {id, folio, method, amount_received,
                            amount_applied, change_given,
                            sales_affected_count, created_at},
         applied: [...]
       }
```

### Flujo del endpoint `show`

```
show(customer, customerPayment):
    1. Verificar customer.branch_id === auth.user.branch_id (403)
    2. Verificar customerPayment.customer_id == customer.id (404)
    3. Cargar customerPayment con: user (cajero), payments.sale (folio, status, total)
    4. Return JSON: {
         id, folio, method, amount_received, amount_applied, change_given,
         sales_affected_count, notes, created_at, cashier: {id, name},
         applications: [
           {sale_id, folio, sale_date, amount, sale_status_after,
            sale_total, sale_amount_pending_after}
         ]
       }
```

### Endpoint modificado `GET /clientes/{c}/pagos`

Response cambia de `{pending_sales, recent_payments, total_owed}` a:

```json
{
  "pending_sales": [...],       // sin cambios
  "total_owed": 0.00,           // sin cambios
  "recent_movements": [         // reemplaza recent_payments
    {
      "type": "global",
      "id": 42,
      "folio": "CG-00042",
      "method": "cash",
      "amount_received": 1000.00,
      "amount_applied": 870.00,
      "change_given": 130.00,
      "sales_affected_count": 3,
      "cashier_name": "Juan",
      "created_at": "2026-04-11T10:30:00Z"
    },
    {
      "type": "single",
      "id": 501,
      "sale_id": 88,
      "sale_folio": "S-00099",
      "method": "cash",
      "amount": 150.00,
      "cashier_name": "María",
      "created_at": "2026-04-10T14:12:00Z"
    }
  ]
}
```

Backend arma `recent_movements` ordenado cronológicamente descendente, limitado a 100:
- Query 1: `CustomerPayment::where(customer_id, branch_id)->with('user:id,name')->get()`
- Query 2: `Payment::where(...)->whereNull('customer_payment_id')->join(sale)->with('user:id,name')->get()`
- Merge + sort por `created_at` desc en PHP → limit 100

### Frontend

**Nuevo componente**: `resources/js/Components/Clientes/CustomerPaymentModal.vue`
(modal para REGISTRAR el cobro, igual que spec v1)

**Nuevo componente**: `resources/js/Components/Clientes/GlobalPaymentDetailModal.vue`
(modal para VER el detalle de un cobro global ya registrado)
- Usa `Modal.vue` existente max-width `2xl`
- Props: `show`, `tenantSlug`, `customerId`, `customerPaymentId`
- Carga lazy vía `GET /cobros-globales/{cp}`
- Muestra: 3 tarjetas (Recibido / Aplicado / Cambio), método, cajero, fecha, tabla de ventas aplicadas clickeable (cada fila abre el `SaleDetailModal` ya existente)

**Modificado**: `resources/js/Pages/Sucursal/Clientes/Index.vue` tab Finanzas
- "Últimos pagos" → "Últimos movimientos"
- Renderizado diferenciado según `movement.type`:
  - `global`: card con icono 🧾, folio CG-XXX, chip "N ventas", monto aplicado grande, cambio si > 0. Click → `GlobalPaymentDetailModal`.
  - `single`: card con icono de método, folio de venta, monto. Click → `SaleDetailModal` existente.
- Ambos tipos muestran cajero y fecha

**Modificado**: `resources/js/composables/useCustomerStats.js`
- `loadPayments()` lee el nuevo shape (`recent_movements` en vez de `recent_payments`)
- Nuevo método `registerGlobalPayment(payload)` que hace POST, invalida `stats`, `payments` e `history`, dispara `loadStats()` + `loadPayments()`

### Bloqueo de edición de children

`PaymentController@update` y `PaymentController@destroy` agregan una guard al inicio:

```php
if ($payment->customer_payment_id !== null) {
    return back()->with('error',
        "Este pago es parte del cobro global {$payment->customerPayment->folio}. " .
        "Para modificarlo, cancela el cobro global completo (próximamente)."
    );
}
```

Esto preserva invariantes (todos los children con mismo method, suma = amount_applied). La cancelación del padre es **fase 2**.

### Fuente del flag `shift_open`

Al response de `GET /clientes/{c}/stats` se agrega `current_user_shift_open: bool`, calculado con:

```php
CashRegisterShift::where('user_id', auth()->id())
    ->whereNull('closed_at')
    ->exists()
```

El frontend lee `stats.current_user_shift_open` y decide habilitar el botón "Registrar pago" con tooltip si es false.

---

## Análisis de impacto

### Código existente NO modificado
- `PaymentController@store` — intacto
- `WorkbenchController` — intacto
- `ShiftController@close` / `CashShiftController@close` — intactos (children siguen siendo Payments normales con su `method`)
- `Payment` model — solo agrega relación, sin migración que rompa
- Reportes de ingresos — siguen sumando `payments.amount` (children incluidos, total correcto)

### Código existente modificado (mínimo, aditivo)
- `PaymentController@update/@destroy` — añade guard contra children (no rompe pagos single-sale)
- `CustomerStatsController@payments` — response shape cambia. Frontend se actualiza simultáneamente.
- `CustomerStatsController@stats` — agrega campo `current_user_shift_open`

### Corte de caja: CERO impacto
Los N `Payment` children tienen `method` idéntico al del padre y aparecen igual que pagos normales en el corte. La columna `customer_payment_id` es ignorada por los controladores de corte. Los bugs pre-existentes (`sales.payment_method` vs `payments.method` en `Caja/ShiftController@close`) no se introducen ni empeoran.

### Cancelación de venta (`WorkbenchController@cancel`)
`Payment::withTrashed()->where('sale_id', ...)` sigue agarrando children igual que pagos normales. Al soft-delete children por cancelación de su venta, el padre `CustomerPayment` permanece como snapshot histórico (el cliente sí entregó ese dinero; la cancelación de la venta es otro evento). Consistente con cómo se maneja hoy.

### Bugs pre-existentes (fuera de scope)
- **Bug A**: `Caja/ShiftController@close` suma por `sales.payment_method`. Nuestro feature no lo empeora (un solo método por cobro).
- **Bug B**: `WorkbenchController@assignCustomer` solo warn con pagos. Preexistente; nuestro `lockForUpdate` lo evita durante la transacción.

Ambos → ticket separado.

---

## Casos edge (cubiertos)

| Caso | Comportamiento |
|---|---|
| Cliente sin ventas pendientes | Botón oculto; 422 backend si se llama directo |
| Sin turno abierto | Botón disabled con tooltip; 403 backend |
| Excluir todas las ventas | Submit disabled; 422 backend |
| Monto = 0 | Submit disabled; 422 backend |
| Efectivo con excedente | Permitido; registra solo `amount_to_apply`, devuelve `change_given` |
| Tarjeta/transferencia con excedente | Rechazado (422 + mensaje claro) |
| Monto < total pendiente | Distribución parcial FIFO correcta |
| Venta cancelada a mitad de cobro | Excluida por `where(status != cancelled)` + lock |
| Venta ya saldada a mitad de cobro | Excluida por `where(amount_pending > 0)` + `fresh()` defensivo |
| Dos cajeros simultáneos al mismo cliente | Advisory lock serializa; segundo ve estado actualizado |
| Sale marcada `completed` al saldar | `SalePaymentService::recalculate` la marca con `completed_at = now()` |
| Rounding centavos | `min()` natural resuelve; `decimal(12,2)` |
| Error en medio de distribución | `DB::transaction` rollback completo (padre + children juntos) |
| Tenant/branch ajeno | 403 por check explícito |
| Edición de child desde `PaymentController@update` | Bloqueado con mensaje claro |
| Eliminación de child desde `PaymentController@destroy` | Bloqueado con mensaje claro |
| Folio CG-XXX duplicado bajo concurrencia | Prevenido por `pg_advisory_xact_lock(branch_id)` + UNIQUE constraint |
| Venta que recibió child se cancela después | Child soft-deleted via `payments.sale_id` cascade (ya existente); padre intacto como snapshot |

---

## Testing

### Feature tests
Archivo: `tests/Feature/Sucursal/CustomerGlobalPaymentTest.php` — 19 tests:

1. `it distributes payment to pending sales FIFO` — 3 ventas pending, pago exacto, 3 Payments children, 3 sales `completed`.
2. `it partial pays oldest sales when amount is less than total` — parcial FIFO correcto.
3. `it returns change when cash amount exceeds total owed` — `change_given` correcto.
4. `it rejects card/transfer payment exceeding total` — 422.
5. `it excludes user-selected sales from FIFO` — distribución salta exclusiones.
6. `it rejects when all sales are excluded` — 422.
7. `it rejects without open shift` — 403.
8. `it rejects cross-branch customer` — 403.
9. `it ignores cancelled sales even if passed in excluded_sale_ids` — nunca recibe Payment.
10. `it is atomic — rolls back on failure` — mock DB exception, ni padre ni children persisten.
11. `it handles concurrent payments on same customer` — advisory lock serializa.
12. `it marks completed sale with timestamp` — `completed_at` seteado.
13. `it does not affect other customers sales` — aislamiento por `customer_id`.
14. `it sets correct user_id on Payment and CustomerPayment` — auth.user.id en padre y todos los children.
15. `it respects branch payment_methods_enabled` — método no habilitado → 422.
16. **`it creates a customer_payment parent with correct totals`** — `amount_received=1000, amount_applied=870, change_given=130, sales_affected_count=3`.
17. **`it links all children payments to the parent via customer_payment_id`** — FK set en los N.
18. **`it generates unique folio CG-XXX per branch under concurrency`** — prueba con `pcntl_fork` o mock: dos llamadas simultáneas → folios distintos, sin violación de UNIQUE.
19. **`it blocks editing or deleting a child payment from PaymentController`** — PUT/DELETE a child → error, cambios no persistidos.

### Testing manual
1. Golden path: cliente con 3 pending → cobro $1000 efectivo → ver cambio → cobrar.
2. Historial muestra cobro global como entrada única, no como 3 pagos sueltos.
3. Click en cobro global → modal detalle con 3 ventas, click en venta → SaleDetailModal.
4. Pago individual previo (pre-feature) sigue apareciendo como entrada `single`.
5. Sin shift abierto → botón disabled con tooltip.
6. Excluir ventas y ver preview actualizado en vivo.
7. Tarjeta con excedente → input en rojo, submit disabled.
8. Dos pestañas mismo cliente → websocket refresh.
9. Intentar editar un child desde Workbench → bloqueado con mensaje.

---

## Scope explícitamente EXCLUIDO (fase 2)

- **Cancelación de un cobro global completo**: endpoint `DELETE /clientes/{c}/cobros-globales/{cp}` que soft-deletea padre + children + recalcula sales + recalcula shifts afectados (lógica de `recalculateAffectedShifts` ya existente).
- **Ticket imprimible del cobro global**: con `folio CG-XXX` + datos del padre + ventas aplicadas.
- **Reporte agrupado de cobros globales del día**: listado filtrable por cajero, rango de fechas, método.
- Fix de Bug A y Bug B: tickets separados.
- Split multi-método en un cobro: no se pidió; modelo permite agregarlo después cambiando `customer_payments.method` a nullable y agregando un campo por child.
- Saldo a favor / crédito: feature propia futura.
- Notas editables del cobro (`notes` ya existe en schema pero MVP lo deja vacío, se usa en fase 2).

---

## Archivos nuevos

| Path | Tipo |
|---|---|
| `database/migrations/YYYY_MM_DD_create_customer_payments_table.php` | Migración |
| `database/migrations/YYYY_MM_DD_add_customer_payment_id_to_payments_table.php` | Migración |
| `app/Models/CustomerPayment.php` | Model |
| `app/Http/Controllers/Sucursal/CustomerPaymentController.php` | Controller |
| `app/Http/Requests/RegisterCustomerPaymentRequest.php` | FormRequest |
| `app/Services/SalePaymentService.php` | Service |
| `resources/js/Components/Clientes/CustomerPaymentModal.vue` | Vue component (registrar) |
| `resources/js/Components/Clientes/GlobalPaymentDetailModal.vue` | Vue component (detalle) |
| `tests/Feature/Sucursal/CustomerGlobalPaymentTest.php` | Tests |
| `docs/modulos/clientes-cobro-global.md` | Doc |

## Archivos modificados

| Path | Cambio |
|---|---|
| `routes/web.php` | Agregar 2 rutas: `clientes.cobro-global` (POST) y `clientes.cobro-global.show` (GET) |
| `app/Models/Payment.php` | Agregar relación `customerPayment()` |
| `app/Http/Controllers/Sucursal/PaymentController.php` | Guard en `update`/`destroy` contra children (`customer_payment_id IS NOT NULL`) |
| `app/Http/Controllers/Sucursal/CustomerStatsController.php` | `stats` agrega `current_user_shift_open`; `payments` devuelve `recent_movements` unificado |
| `resources/js/Pages/Sucursal/Clientes/Index.vue` | Botón "Registrar pago" en tab Finanzas; tab Finanzas renderiza `recent_movements` con diferenciación `type`; integración de ambos modales |
| `resources/js/composables/useCustomerStats.js` | Método `registerGlobalPayment`; lectura del nuevo shape |

---

## Criterios de aceptación

- [ ] Cajero con turno abierto puede cobrar un pago global a un cliente con saldo
- [ ] Distribución FIFO funciona correctamente
- [ ] Checkboxes permiten excluir ventas específicas
- [ ] Efectivo con excedente muestra cambio y permite cobrar
- [ ] Tarjeta/transferencia rechaza excedente con mensaje claro
- [ ] Cobro global aparece en historial como UNA entrada con folio CG-XXX
- [ ] Click en cobro global abre modal con detalle de distribución
- [ ] Pagos individuales previos siguen apareciendo correctamente
- [ ] Child payments no pueden editarse/eliminarse desde el flujo de pagos de venta
- [ ] Todos los 19 feature tests pasan
- [ ] Cortes de caja no cambian su comportamiento (mismos totales que antes)
- [ ] Reportes de ingresos no cambian su comportamiento
- [ ] Docs del módulo incluyen schema, endpoints, reglas y casos edge
- [ ] Ningún test existente se rompe
