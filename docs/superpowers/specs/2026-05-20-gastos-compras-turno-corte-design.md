# Gastos y compras en efectivo ligados al turno (corte exacto) — Diseño congelado

**Fecha:** 2026-05-20
**Estado:** Aprobado para implementación
**Autor:** colaboración con Claude (exploración + propuesta)

## Objetivo

Que el **corte de caja cuadre con el efectivo físico** del cajón.
Hoy el corte solo considera el efectivo que **entra** (cobros de ventas) y
los **retiros**. Las salidas de efectivo por **gastos** (p. ej. bolsas, gas)
y por **pagos a proveedor en efectivo** no se descuentan, así que el efectivo
esperado queda inflado y el corte nunca cuadra fino.

Este diseño hace que las salidas de efectivo capturadas **desde la caja
durante un turno abierto** descuenten del efectivo esperado del corte.

## Decisión de fondo: FK explícita, no scope temporal

El corte de ventas se calcula por **scope temporal** (pagos cuyo `created_at`
cae en `[opened_at, closed_at]`). Para gastos y compras eso es frágil: pueden
tener fecha de negocio distinta a "ahora" (`expense_at`, `purchased_at`,
`paid_at` pueden ser retroactivos) y un registro hecho un minuto después del
cierre se mal-atribuye.

Como el cajero las captura desde la caja con su turno abierto, el turno se
**conoce en el momento**. Por eso atamos cada salida al turno con una **FK
explícita** (`cash_register_shift_id`), no por tiempo. El lado de ventas
**no se refactoriza** y sigue siendo temporal.

> **Clave:** lo que resta del cajón se ata al **pago** (`ProviderPayment`),
> no a la compra. Así una compra pagada parte en un turno y parte en otro
> reparte cada pago a su turno correcto. La compra en sí no mueve efectivo.

## Alcance

Capturar desde la caja, ligado al turno abierto del cajero:

- **Gasto en efectivo**: subcategoría, concepto, monto, foto opcional.
- **Compra en efectivo**: compra completa (proveedor + artículos, reusa el
  flujo de compra y el modal cámara/IA existente) **+** un pago a proveedor
  por lo pagado en efectivo en ese momento.

Solo los registros con `payment_method = cash` afectan el efectivo esperado.
Tarjeta/transferencia quedan ligados al turno por trazabilidad pero **no**
mueven el cajón.

### Fases (comparten la plomería de esquema y corte)

- **Fase 1:** esquema + gasto en efectivo en caja + cálculo y UI del corte +
  tests.
- **Fase 2:** compra + pago a proveedor en efectivo desde caja (reusa el
  flujo de compras y el modal cámara/IA) + tests.

### Fuera de alcance (YAGNI)

- Los flujos de admin-empresa y admin-sucursal de gastos/compras **no
  cambian**: siguen creando registros sin vínculo a turno.
- No se liga `sales` al turno (sigue por scope temporal).
- No hay reporte de utilidad / estado de resultados por turno; el objetivo es
  el corte exacto, no la analítica (el objetivo elegido fue "cuadrar el cajón").
- No se reasignan a turno los registros históricos (la FK nace nullable).
- No se permite asignar a turnos abiertos desde los flujos de admin.

## Modelo de datos

Nuevas columnas, todas **nullable** y `nullOnDelete` (no rompen lo existente):

```
expenses           + cash_register_shift_id  (FK cash_register_shifts)
provider_payments  + cash_register_shift_id  (FK cash_register_shifts)
purchases          + cash_register_shift_id  (FK cash_register_shifts)  // trazabilidad
cash_register_shifts + total_cash_expenses (decimal 12,2 default 0)
                     + total_cash_provider_payments (decimal 12,2 default 0)
```

| Tabla | FK para qué | ¿Afecta el cajón? |
|---|---|---|
| `expenses` | gasto capturado en caja | **Sí** si `payment_method = cash` y no cancelado |
| `provider_payments` | pago a proveedor capturado en caja | **Sí** si `payment_method = cash` y no cancelado |
| `purchases` | "compras capturadas en este turno" (listado) | No — el efectivo lo mueve el pago |

Las dos columnas en `cash_register_shifts` **persisten el desglose** al cerrar,
igual que hoy se persisten `total_cash`, `total_card`, etc., para que el
historial del turno muestre el detalle sin recalcular.

### Índices

- `expenses (cash_register_shift_id)`
- `provider_payments (cash_register_shift_id)`
- `purchases (cash_register_shift_id)`

## Flujo en caja (exige turno abierto)

Dos acciones nuevas en el workbench de caja. **Precondición:** el cajero tiene
un turno abierto (`closed_at IS NULL`). Si no, se bloquea con mensaje "abre tu
turno primero".

### Gasto en efectivo

Form: subcategoría (dropdown de las activas del tenant), concepto, monto, foto
opcional. Crea un `Expense` con:

- `payment_method = cash`
- `branch_id` = branch del turno
- `cash_register_shift_id` = id del turno abierto
- `user_id` = cajero, `expense_at = now()`

### Compra en efectivo

Reusa el flujo de compra (proveedor, artículos; admite captura con el modal
cámara/IA ya existente, pasándole la ruta `iaStore` de caja). Al guardar, en
una transacción:

1. Crea la `Purchase` (sellada con `cash_register_shift_id` del turno).
   `purchased_at` toma `now()` por defecto (el cajero captura al momento); la
   atribución al turno es por la FK, no por esta fecha.
2. Crea un `ProviderPayment` con `payment_method` (default `cash`), `amount` =
   lo pagado ahora (default = total, permite parcial), `branch_id`,
   `cash_register_shift_id` del turno, `user_id`.
3. `PurchasePaymentService::recalculate()` actualiza `amount_paid` /
   `amount_pending` (lo no pagado queda pendiente como hoy).

Solo el `ProviderPayment` en efectivo descuenta del cajón.

## Cálculo del corte

Servicio nuevo `ShiftCashOutCalculator` (hermano de `ShiftTotalsCalculator`,
para no mezclar el cálculo temporal de ventas con el de FK explícita). Dado
`shiftId`, devuelve:

```
cash_expenses           = Σ expenses.amount
                          WHERE cash_register_shift_id = shiftId
                            AND payment_method = 'cash'
                            AND deleted_at IS NULL
cash_provider_payments  = Σ provider_payments.amount
                          WHERE cash_register_shift_id = shiftId
                            AND payment_method = 'cash'
                            AND cancelled_at IS NULL
                            AND deleted_at IS NULL
```

Nueva fórmula del efectivo esperado (en `Caja\TurnoController::close`):

```
expected_amount = opening_amount
                + total_cash            (cobros en efectivo de ventas, igual que hoy)
                − withdrawals_sum       (retiros, igual que hoy)
                − total_cash_expenses
                − total_cash_provider_payments
```

Al cerrar se persisten `total_cash_expenses` y `total_cash_provider_payments`
en el turno, y `expected_amount` ya incorpora la resta.

## Cancelación y recálculo

Reusa el patrón existente:

- **Turno abierto:** la siguiente lectura excluye los cancelados/soft-deleted
  (los `WHERE` ya lo filtran). El esperado se recalcula en vivo.
- **Turno cerrado:** se extiende `RecalculateClosedShifts` para que, además de
  los pagos de ventas, recompute `total_cash_expenses`,
  `total_cash_provider_payments` y `expected_amount` cuando un gasto/pago
  ligado a un turno cerrado se cancela o edita.

## Casos borde

- **Pago parcial en efectivo:** solo el monto pagado en efectivo descuenta;
  el resto de la compra queda como `amount_pending`.
- **Compra pagada en varios turnos:** cada `ProviderPayment` cuelga de su
  propio turno vía su FK; por eso la fuente de verdad del efectivo es el pago,
  no la compra.
- **Editar un cash-out ligado a turno cerrado:** cualquier cambio que altere
  el monto o el método (p. ej. cash → card, o ajustar el importe) dispara
  recálculo del turno cerrado vía `RecalculateClosedShifts`; en turno abierto
  se refleja en la siguiente lectura.
- **Sin turno abierto:** las acciones de caja se bloquean.

## Rutas (nuevas, bajo `/{tenant}/caja`)

```
POST   /{tenant}/caja/gastos                 store gasto en efectivo (Fase 1)
POST   /{tenant}/caja/compras                store compra + pago en efectivo (Fase 2)
POST   /{tenant}/caja/compras/ia             draft IA de compra (Fase 2, reusa flujo IA)
GET    /{tenant}/caja/proveedores            lectura de proveedores activos (Fase 2)
GET    /{tenant}/caja/gastos/subcategorias   lectura de subcategorías activas (Fase 1)
GET    /{tenant}/caja/productos              lectura de productos (Fase 2, para artículos)
```

Todas bajo el middleware de rol `cajero` ya existente del prefijo `caja`,
con scope forzado a `branch_id` del turno y `cash_register_shift_id` del turno
abierto (defensa en el controlador, no confiar en el form).

## Permisos (expansión del rol cajero)

Hoy el cajero **no** accede a proveedores, productos ni subcategorías de gasto.
Esta feature le da:

- **Lectura** de proveedores activos, productos y subcategorías de gasto de su
  sucursal/tenant (solo lo necesario para los forms).
- **Creación** de gasto / compra / pago a proveedor **acotada** a su sucursal y
  a su turno abierto.

No se le da edición/borrado de catálogos ni acceso a los listados de
admin. Los flujos de admin quedan intactos.

## Validaciones

### Gasto en efectivo (caja)

- `concept`: required, string, max 160.
- `amount`: required, numeric, min 0.01, max 99,999,999.99.
- `expense_subcategory_id`: required, exists con `tenant_id` actual y
  `status='active'`.
- `payment_method`: forzado a `cash` en el controlador.
- Turno abierto requerido (si no, 422 con mensaje).
- Foto opcional: mismas reglas que adjuntos de gasto (jpg/png/webp, 5 MB).

### Compra en efectivo (caja)

- Reusa la validación de `HandlesPurchases::validatedPurchasePayload`
  (proveedor del tenant, artículos, etc.), con `branch_id` forzado al del
  turno.
- `paid_amount`: required, numeric, min 0. La cota superior es el `total` de la
  compra **calculado en el servidor** a partir de los artículos validados, no
  un total enviado por el cliente.
- `payment_method` del pago: default `cash`; si es `cash` afecta el corte.
- Turno abierto requerido.

## UI del corte

Bloque **"Salidas en efectivo"** en la pantalla de corte de caja
(`Caja\TurnoController::showCorte`) y en el historial del admin-sucursal
(`Sucursal\ShiftController::show`):

- Retiros: `$X`
- Gastos en efectivo: `$Y`
- Pagos a proveedor en efectivo: `$Z`
- **Total salidas: `$X+Y+Z`**

La línea de "efectivo esperado" refleja el descuento. Debajo, la lista de
ítems (gastos y pagos) capturados en el turno, enlazados a su detalle.

En el workbench de caja: entradas para "Registrar gasto" y "Registrar compra"
visibles solo con turno abierto.

## Tests (PHPUnit, feature)

- Gasto en efectivo en caja: queda con `cash_register_shift_id`, baja el
  `expected_amount` del corte por su monto.
- Compra + pago en efectivo: el `ProviderPayment` queda con el turno, baja el
  esperado; la `Purchase` queda sellada con el turno.
- Pago por tarjeta/transferencia: se liga al turno pero **no** baja el esperado.
- Cancelar un gasto en efectivo: recalcula (turno abierto en vivo y turno
  cerrado vía `RecalculateClosedShifts`).
- Cambiar método de pago de un gasto (cash → card) en turno cerrado: recalcula.
- Sin turno abierto: las rutas de caja devuelven 422/bloqueo.
- Aislamiento: tenant A no afecta turnos/gastos de B; cajero acotado a su
  sucursal.
- Permisos: admin-empresa/sucursal siguen sin `cash_register_shift_id` en sus
  flujos (no regresión).

## Roadmap posterior

- Vista de "movimientos de efectivo del turno" como mini estado de entradas/
  salidas (la visibilidad que quedó fuera del objetivo de corte exacto).
- Permitir a admin-sucursal asignar un gasto/pago a un turno abierto.
