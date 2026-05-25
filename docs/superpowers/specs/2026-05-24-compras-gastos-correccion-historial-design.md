# Compras y Gastos: corregir desde caja, historial de cambios y cancelación que oculta — Diseño congelado

**Fecha:** 2026-05-24
**Estado:** Aprobado para implementación
**Autor:** colaboración con Claude (exploración + propuesta)

## Objetivo

Tres carencias que hoy frenan al usuario en los módulos de **Compras** y **Gastos**:

1. **El cajero queda atorado.** En `Caja → Compras / Gastos` solo puede
   *registrar* y *ver*. Si registra una compra sin indicar cuánto pagó (queda
   "DEBE $X") **no puede abrirla** para registrar el pago, ni corregir un dato,
   ni anularla. Las filas del listado no son clicables.
2. **No hay historial de cambios.** Editar una compra reemplaza sus datos sin
   dejar rastro de qué cambió, quién y cuándo.
3. **Cancelar no oculta.** Cancelar una compra le pone `status = cancelled`
   pero **sigue apareciendo** en el listado (el filtro por defecto es `all`).
   En Gastos la cancelación es *soft delete* y sí desaparece — la
   inconsistencia es visible para el usuario.

Además, un detalle de UX pedido explícitamente: el campo "Pagado en efectivo
ahora" debe tener un botón **"Exacto"** (como el de la pantalla de cobros) que
rellene el monto de un clic, en vez de teclearlo.

## Decisiones aprobadas

| Tema | Decisión |
| --- | --- |
| Módulos | **Compras y Gastos** (ambos). |
| Botón "registrar exacto" | Es el botón **"Exacto"** en el campo de pago en efectivo (no escribir el monto). |
| Editar/cancelar el cajero | **Sí, sobre sus propias compras/gastos y solo con su turno abierto.** Cerrado el turno, queda solo-lectura para el cajero. |
| Canceladas en el listado | **Ocultarlas por completo** (no aparecen; siguen en BD). |
| Historial | **Detallado, campo por campo** (incluye líneas de la compra). |
| Mecanismo del historial | **Tabla polimórfica `audit_logs` + servicio `AuditLogger`** (sin dependencias nuevas). |
| Cancelar con pago en turno **cerrado** | **Recalcular el corte cerrado** (vía `RecalculateClosedShifts::forShift`). |

## Alcance

- **A. Historial de cambios** campo por campo para `Purchase` y `Expense`:
  creada, editada, cancelada, pago registrado, pago cancelado. Visible en una
  sección "Historial" dentro de los modales de detalle.
- **B. Caja — corregir el propio registro con turno abierto:** filas clicables
  → modal de detalle con **Registrar pago / Editar / Cancelar** para compras, y
  **Editar / Cancelar** para gastos. Candado server-side: propio + turno abierto.
- **C. Ocultar canceladas** de los tres listados de Compras (Caja, Sucursal,
  Empresa). Gastos ya se ocultan por *soft delete*.
- **D. Cancelar devuelve el efectivo:** al cancelar una compra se cancelan sus
  pagos en efectivo ligados; si el pago era de un turno cerrado, se recalcula
  ese corte. Igual para un gasto en efectivo ligado a un turno.
- **E. Botón "Exacto"** en el campo de pago en efectivo del formulario de
  compra y en el modal de "Registrar pago".

### Fuera de alcance (YAGNI)

- No se versiona el contenido completo de cada estado (no es *event sourcing*):
  el historial guarda el **diff** del cambio, no un snapshot reconstruible.
- No hay UI para "reabrir" o restaurar una compra cancelada (sigue en BD; si más
  adelante hace falta auditarlas, se agrega un toggle "ver canceladas").
- El cajero no puede tocar registros de **otro** cajero ni de un **turno cerrado**.
- No se añade historial a otros módulos (ventas, clientes) en esta entrega; la
  tabla es polimórfica y queda lista para reutilizarse, pero no se cablea aquí.
- No se reescribe la lógica de pagos: se reutiliza `PurchasePaymentService`.

## Modelo de datos

Una sola tabla polimórfica sirve a Compras y Gastos (y a módulos futuros):

```
audit_logs
  id
  tenant_id        FK tenants (cascade)        -- scope multi-tenant
  auditable_type   string                      -- App\Models\Purchase | App\Models\Expense
  auditable_id     unsignedBigInteger
  user_id          FK users (nullOnDelete, nullable)  -- quién hizo el cambio
  event            string(20)                  -- created|updated|cancelled|payment_added|payment_cancelled
  changes          json (nullable)             -- diff legible (ver formato abajo)
  created_at       timestamp                   -- cuándo (no usa updated_at: las entradas son inmutables)

  index (auditable_type, auditable_id)
  index (tenant_id)
```

- Sin `updated_at`: una entrada de historial es **inmutable**.
- `user_id` nullable + `nullOnDelete`: el historial sobrevive aunque se borre el
  usuario (queda "Usuario eliminado").
- `tenant_id` se autollena vía `BelongsToTenant`.

### Formato de `changes`

Valores **legibles** (nombres y montos ya resueltos), no ids crudos, para que el
historial sea autocontenido y la UI no tenga que hacer joins.

```jsonc
// event = updated (compra)
{
  "fields": {
    "total":          [100.00, 120.00],
    "invoice_number": [null, "F-4521"],
    "purchased_at":   ["2026-05-24", "2026-05-23"],
    "provider":       ["Jarocho espejo", "Carnes del Valle"],
    "notes":          ["", "entrega tarde"]
  },
  "items": {
    "added":   [{ "concept": "Costilla", "quantity": 2, "unit": "kg", "unit_price": 90 }],
    "removed": [{ "concept": "Chuleta",  "quantity": 1, "unit": "kg", "unit_price": 100 }],
    "changed": [{ "concept": "Pierna", "from": { "quantity": 1, "unit_price": 80 },
                                       "to":   { "quantity": 2, "unit_price": 80 } }]
  }
}

// event = cancelled
{ "reason": "duplicada" }

// event = payment_added
{ "amount": 1663.90, "method": "Efectivo" }

// event = payment_cancelled
{ "amount": 1663.90, "method": "Efectivo", "reason": "monto equivocado" }

// event = created  → changes = null (el detalle ya es el registro mismo)
```

Para gastos, `items` no aplica; `fields` cubre `concept`, `amount`,
`subcategory`, `payment_method`, `expense_at`, `description`, `branch`.

## A. Historial de cambios

### Piezas

- **Enum `App\Enums\AuditEvent`** (string-backed): `Created`, `Updated`,
  `Cancelled`, `PaymentAdded`, `PaymentCancelled`, con `label()` en español
  ("Creó", "Editó", "Canceló", "Registró pago", "Canceló pago").
- **Modelo `App\Models\AuditLog`**: usa `BelongsToTenant`, `morphTo('auditable')`,
  `belongsTo(User)`, cast `event => AuditEvent`, `changes => array`. Solo
  `created_at` (`public $timestamps = false` + se setea a mano, o `UPDATED_AT = null`).
- **Trait `App\Models\Concerns\RecordsHistory`**: agrega
  `history(): MorphMany` (ordenada `created_at desc`) a `Purchase` y `Expense`.
- **Servicio `App\Services\AuditLogger`** — único punto que escribe historial:
  - `logCreated(Model $m): void`
  - `logUpdated(Model $m, array $changes): void` (no escribe si `changes` vacío)
  - `logCancelled(Model $m, string $reason): void`
  - `logPaymentAdded(Model $m, float $amount, string $method): void`
  - `logPaymentCancelled(Model $m, float $amount, string $method, string $reason): void`
  - Internamente arma el registro con `auditable`, `user_id = Auth::id()`,
    `event`, `changes`, `created_at = now()`.

### Cálculo del diff

El diff campo-por-campo lo arma quien tiene el "antes" y el "después". Para no
ensuciar los controladores, un helper en el servicio compara dos arreglos:

- **Compras (`HandlesPurchases::update` + Caja):** antes de mutar se captura un
  snapshot (`provider->name`, `invoice_number`, `purchased_at->toDateString()`,
  `total`, `notes`, y las líneas como `[{concept, quantity, unit, unit_price}]`).
  Tras guardar, se compara contra el nuevo snapshot. La comparación de líneas
  empareja por `concept` (case-insensitive) → `added` / `removed` / `changed`.
  - **`purchased_at` se compara como fecha** (`toDateString()` en ambos lados),
    no como timestamp, para no marcar "cambió" cuando solo difiere la hora.
  - El lado "después" usa el `concept` **ya resuelto** (el `name` del
    `PurchaseProduct`, que es lo que `resolvePurchaseProduct` persiste en la
    línea), no el texto crudo del form. Así el diff refleja lo realmente guardado.
  - El snapshot del "antes" se toma **antes** del `delete()` de líneas que hace
    el `update` (estrategia borrar-y-recrear actual).
- **Gastos:** Eloquent ya expone `getOriginal()` / `getChanges()`. Se usa para
  los campos escalares; `subcategory` y `branch` se traducen a nombre.

> Si tras editar no cambió nada, **no se escribe** entrada `updated`.

### UI

`serializePurchase()` y la serialización de gasto incluyen `history` (últimas 50,
desc) con `{ event, event_label, user_name, created_at, changes }`. Para evitar
N+1 se eager-loadea `history.user`.

Un componente Vue reutilizable **`HistorialTimeline.vue`** (en
`resources/js/Components/Historial/`) renderiza la línea de tiempo a partir de
`changes` (mapea claves a etiquetas en español y formatea montos/fechas). Se
monta como una sección colapsable dentro de `CompraDetailModal` y
`GastoDetailModal`. Ejemplo de renta visible:

```
✏️  Editó · caja estrellas · 24 may 2026 14:32
      Total $0.00 → $1,663.90
      + línea "Costilla" 2 kg × $90.00
💵  Registró pago · caja estrellas · 24 may 2026 14:33
      Efectivo +$1,663.90
🟢  Creó · caja estrellas · 24 may 2026 14:30
```

## B. Caja — corregir el propio registro con turno abierto

### Candado (regla de autorización, server-side)

El cajero puede **pagar / editar / cancelar** un registro solo si:

1. es **suyo** (`created_by` / `user_id` = `Auth::id()`), **y**
2. pertenece a **su turno abierto** (`cash_register_shift_id` = id del turno
   abierto del cajero).

Si no hay turno abierto, o el registro es de un turno ya cerrado, o es de otro
usuario → `403`. Un helper privado `assertCajaCanMutate($model)` en cada
controlador de Caja centraliza la regla. La UI también recibe un flag
`can_manage` por fila para mostrar/ocultar los botones, pero **la verdad vive
en el backend**.

### Compras (`Caja\PurchaseController`)

Métodos nuevos:

- `update(Request, Purchase)`: reusa `HandlesPurchases::update` tras
  `assertCajaCanMutate`. **No** toca pagos (ver regla de edición abajo).
- `cancel(Request, Purchase)`: reusa `HandlesPurchases::cancel` tras el candado.
- `storePayment(Request, Purchase)`: registra un pago **en efectivo** ligado al
  turno abierto, vía `PurchasePaymentService::applyPayment` (que ya valida no
  exceder el total). Resuelve el caso de la captura (CMP-2026-00017 sin pago).
- `destroyPayment(Request, Purchase, ProviderPayment)`: cancela un pago propio
  del turno abierto, vía `PurchasePaymentService::cancelPayment` (devuelve el
  efectivo al cajón del turno actual).

### Gastos (`Caja\GastoController`)

Métodos nuevos:

- `update(Request, Expense)`: edita `concept`, `amount`, `expense_subcategory_id`,
  `description` (y adjuntos nuevos). `payment_method` sigue fijo en efectivo y
  `expense_at` no se reabre. Cambiar el monto reajusta el corte automáticamente
  (el cálculo del corte suma en vivo los gastos no cancelados del turno).
- `destroy(Request, Expense)`: *soft delete* + `cancelled_by` +
  `cancellation_reason` (mismo patrón que el admin). Candado de Caja.

### Regla de edición (Compras y Gastos, todos los roles)

La **edición no modifica pagos**. El nuevo total de una compra **no puede ser
menor a lo ya pagado** (suma de pagos vivos) → si lo fuera, `422` con mensaje
claro ("El total no puede ser menor a lo ya pagado; cancela un pago primero").
Cambiar el monto pagado se hace con *Registrar pago* / *Cancelar pago*. Esto
también cierra un hueco latente del flujo de admin (hoy `update` no valida esto).

### Frontend de Caja

- `Caja/Compras/Index.vue` y `Caja/Gastos/Index.vue`: las filas abren el modal
  de detalle (`@click`). Se reutilizan `CompraDetailModal` y `GastoDetailModal`
  pasando **rutas de Caja** y un prop nuevo `canManage` (booleano por registro).
- A los modales de detalle se les agrega el prop `canManage` (default `true`
  para no romper a los admins): cuando es `false`, oculta Registrar pago / Editar
  / Cancelar y queda solo-lectura.
- El backend de Caja serializa cada compra/gasto con `can_manage` calculado a
  partir del candado (propio + turno abierto), y expone `hasOpenShift`.

## C. Ocultar canceladas por completo

- **Compras:** las consultas de listado **siempre** excluyen
  `status = cancelled`:
  - `HandlesPurchases::applyIndexFilters`: se elimina la rama que mostraba
    canceladas; el listado nunca las incluye.
  - `Caja\PurchaseController::index`: agrega `->where('status', '!=', cancelled)`.
  - Se quita la opción "Canceladas" del filtro de estado en
    `Empresa/Compras/Index.vue` y `Sucursal/Compras/Index.vue` (el filtro de
    estado pierde sentido y se elimina; los KPIs ya excluían canceladas).
- **Gastos:** sin cambios (el *soft delete* ya las oculta en `index`).
- La compra cancelada sigue en BD (con su historial); simplemente no hay UI que
  la liste. *Route-model-binding* sigue resolviéndola para los endpoints internos
  (no es *soft delete*), pero ningún listado la muestra.

## D. Cancelar devuelve el efectivo

Hoy `HandlesPurchases::cancel` marca la compra como cancelada pero **no** cancela
sus `ProviderPayment` en efectivo → el corte seguiría restando ese dinero del
cajón. Se corrige para **todos** los roles:

1. Al cancelar una compra, por cada pago vivo en efectivo
   (`payment_method = cash`, `cancelled_at = null`): `PurchasePaymentService::cancelPayment`.
2. Se juntan los `cash_register_shift_id` afectados; por cada turno:
   - **abierto** → nada (el corte se calcula en vivo al cerrar).
   - **cerrado** → `RecalculateClosedShifts::forShift($shift)` reajusta su
     `expected_amount`/`difference`.
3. Igual para **gastos en efectivo** ligados a un turno: tras el *soft delete*,
   si el turno está cerrado se llama `RecalculateClosedShifts::forShift`.
4. **Editar el monto de un gasto** ligado a un turno **cerrado** (flujo admin de
   Sucursal/Empresa) también mueve el efectivo de ese corte → tras el `update`,
   si el turno está cerrado se llama `RecalculateClosedShifts::forShift`. (En
   turno abierto no hace falta: el corte suma en vivo al cerrar.) Las compras no
   necesitan esto al editar, porque la edición **no toca pagos** —y solo los
   pagos mueven el cajón.

> Nota: cancelar la compra invalida sus pagos; el saldo del proveedor se reduce
> de forma consistente porque `PurchasePaymentService` recalcula
> `amount_paid`/`amount_pending`.

## E. Botón "Exacto"

- `CompraFormModal.vue` (modo caja): se reemplaza el enlace
  "Pagar total ($X)" por un botón **"Exacto"** con el mismo estilo que el de
  cobro (`Caja/Workbench.vue:517-520`: pill gris, `@click` rellena el monto).
  Acción: `form.paid_amount = Number(total.toFixed(2))`.
- `PagoProveedorModal.vue` (usado por Registrar pago en Caja y admin): se agrega
  el mismo botón **"Exacto"** que rellena el monto con el pendiente.

## Autorización por rol (resumen)

| Acción | Cajero | admin-sucursal | admin-empresa |
| --- | --- | --- | --- |
| Registrar compra/gasto | sí (turno abierto) | sí | sí |
| Registrar/cancelar pago | sí, propio + turno abierto | sí (su sucursal) | sí |
| Editar | sí, propio + turno abierto | sí (su sucursal) | sí |
| Cancelar | sí, propio + turno abierto | sí (su sucursal) | sí |
| Ver historial | sí, los suyos | sí (su sucursal) | sí |

## Impacto en el corte

El corte calcula el efectivo esperado **en vivo** (`ShiftCashOutCalculator`),
sumando gastos y pagos a proveedor en efectivo **no cancelados** del turno. Por
eso:

- Editar el monto de un gasto / pago de un turno **abierto** se refleja solo al
  cerrar. ✓
- Cancelar pagos en efectivo de un turno **abierto** se refleja solo. ✓
- Cancelar pagos/gastos en efectivo de un turno **cerrado** requiere
  `RecalculateClosedShifts::forShift` (sección D). ✓

## Pruebas (feature tests, PHPUnit)

**Historial:**
- Crear, editar y cancelar una compra escribe entradas `created`/`updated`/`cancelled`.
- `updated` guarda el diff correcto de campos y de líneas (added/removed/changed).
- Editar sin cambios **no** escribe entrada.
- Registrar y cancelar un pago escribe `payment_added`/`payment_cancelled`.
- Igual para gastos (campos escalares + subcategoría/branch).

**Caja (candado por turno):**
- El cajero puede pagar/editar/cancelar su compra del turno abierto.
- 403 al intentar tocar una compra de otro cajero, de un turno cerrado, o sin turno.
- `storePayment` con "Exacto" deja la compra en `paid` y el pago ligado al turno.
- Cancelar una compra del turno abierto devuelve el efectivo (el corte recalcula).
- Editar un gasto del turno cambia el efectivo esperado del corte.

**Ocultar canceladas:**
- Una compra cancelada no aparece en los listados de Caja, Sucursal ni Empresa.
- Los KPIs no la cuentan (ya era el caso).

**Cancelar / corte cerrado:**
- Cancelar una compra con pago en un turno **cerrado** recalcula `expected_amount`.
- Cancelar un gasto de un turno cerrado recalcula el corte.

**Regla de edición:**
- Editar para dejar el total por debajo de lo pagado devuelve `422`.

## Archivos afectados (mapa)

**Nuevos**
- `database/migrations/2026_05_24_000001_create_audit_logs_table.php`
- `app/Enums/AuditEvent.php`
- `app/Models/AuditLog.php`
- `app/Models/Concerns/RecordsHistory.php`
- `app/Services/AuditLogger.php`
- `resources/js/Components/Historial/HistorialTimeline.vue`
- `tests/Feature/.../` (historial, caja, ocultar canceladas, corte cerrado)

**Modificados (backend)**
- `app/Models/Purchase.php`, `app/Models/Expense.php` (trait `RecordsHistory`)
- `app/Http/Controllers/Concerns/HandlesPurchases.php` (historial en store/update/cancel; cancelar pagos en cancel; regla de edición; quitar canceladas del filtro)
- `app/Http/Controllers/Caja/PurchaseController.php` (update/cancel/storePayment/destroyPayment + candado + ocultar canceladas + `can_manage`)
- `app/Http/Controllers/Caja/GastoController.php` (update/destroy + candado + `can_manage`)
- `app/Http/Controllers/Empresa/GastoController.php`, `app/Http/Controllers/Sucursal/GastoController.php` (historial + recalcular corte cerrado en destroy)
- `app/Services/PurchasePaymentService.php` (enganchar `AuditLogger` en applyPayment/cancelPayment, o llamarlo desde los controladores)
- `routes/web.php` (rutas nuevas de Caja: `caja.compras.update/cancel/pagos.store/pagos.destroy`, `caja.gastos.update/destroy`)

**Modificados (frontend)**
- `resources/js/Components/Compras/CompraDetailModal.vue`, `resources/js/Components/Gastos/GastoDetailModal.vue` (prop `canManage`, sección historial)
- `resources/js/Components/Compras/CompraFormModal.vue` (botón "Exacto")
- `resources/js/Components/Compras/PagoProveedorModal.vue` (botón "Exacto")
- `resources/js/Pages/Caja/Compras/Index.vue`, `resources/js/Pages/Caja/Gastos/Index.vue` (filas clicables → detalle)
- `resources/js/Pages/Empresa/Compras/Index.vue`, `resources/js/Pages/Sucursal/Compras/Index.vue` (quitar opción "Canceladas")

## Fases de implementación

- **Fase 1 — Historial:** esquema `audit_logs` + enum/modelo/trait/servicio +
  enganche en los flujos de admin (Compras y Gastos) + `HistorialTimeline.vue` +
  tests. Entrega valor solo (auditoría) sin tocar Caja.
- **Fase 2 — Caja corrige:** endpoints update/cancel/pago en Caja (compras y
  gastos) + candado por turno + reutilizar modales de detalle con `canManage` +
  cancelar devuelve efectivo + recalcular corte cerrado + tests.
- **Fase 3 — Pulido:** ocultar canceladas en los tres listados + botón "Exacto"
  + tests. (Pequeña; puede ir junto con la Fase 2.)

## Riesgos y bordes

- **Edición que baja el total por debajo de lo pagado:** se bloquea con `422`
  (sección B). Evita saldos negativos.
- **Diff de líneas por `concept`:** si dos líneas comparten concepto exacto, el
  emparejamiento las agrupa; aceptable para el historial legible (no es
  contabilidad de inventario).
- **Recalcular un corte cerrado** cambia un corte ya firmado; es la decisión
  aprobada. Queda registrado en el historial de la compra (cancelación) quién y
  cuándo, para trazabilidad.
- **Volumen de `audit_logs`:** crece con cada edición; el índice por
  `(auditable_type, auditable_id)` mantiene la lectura barata y la UI limita a 50.
```
