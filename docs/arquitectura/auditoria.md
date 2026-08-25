# Auditoría de cambios

Infraestructura transversal que registra quién cambió qué y cuándo, sobre las entidades donde el cambio importa: ventas, compras, gastos y el catálogo de insumos.

## Responsabilidades

- Registrar cada cambio relevante como un renglón inmutable en `audit_logs`.
- Calcular el diff campo por campo, incluidas las líneas de una compra.
- Guardar el **efecto en dinero** de un cambio, para poder ordenar y sumar por impacto.
- Anotar el contexto: qué usuario, desde qué IP y con qué equipo.

**No hace:** no versiona ni permite deshacer. No audita lecturas. No borra ni edita: la tabla **solo recibe inserciones**.

## Decisiones

| Decisión | Por qué |
|----------|---------|
| **Un único punto de escritura** (`AuditLogger`) | Si cada controlador escribiera su propio renglón, el formato divergiría en un mes. |
| **Relación polimórfica** (`auditable_type`/`auditable_id`) | La misma bitácora sirve a ventas, compras, gastos e insumos sin una tabla por tipo. |
| **`amount_effect` con signo** | Un negativo significa "reduce lo que hay que entregar". Es el número que la pantalla ordena y suma; calcularlo al leer sería frágil. |
| **El contexto se resuelve dentro del logger** | Para que ningún punto de escritura tenga que acordarse de pasarlo. |
| **Inmutable** | Una bitácora que se puede editar no sirve como bitácora. |

## La tabla `audit_logs`

| Columna | Para qué |
|---------|----------|
| `tenant_id`, `branch_id` | Aislamiento. `branch_id` es nulo en entidades tenant-wide |
| `auditable_type`, `auditable_id` | A qué entidad pertenece el cambio |
| `user_id` | Quién lo hizo. Nulo si fue automático |
| `event` | Cuál de los eventos del enum |
| `changes` | JSON con el detalle: el diff, o los datos del movimiento |
| `amount_effect` | El efecto en dinero, con signo. Nulo si el cambio no mueve dinero |
| `ip_address`, `user_agent` | Desde dónde se hizo |
| `created_at` | Cuándo. No hay `updated_at`: el renglón no se toca |

## Los eventos (`AuditEvent`)

**Genéricos** — sirven a compras, gastos e insumos:

`created` · `updated` · `cancelled` · `payment_added` · `payment_cancelled` · `merged`

**De venta** — el detalle fino de la mesa de trabajo:

`item_added` · `item_updated` · `item_removed` · `payment_updated` · `payment_deleted` · `reopened` · `customer_assigned` · `customer_removed`

## Qué mueve dinero y qué no

El propio enum lo resuelve, para que nadie tenga que recordarlo:

| Método | Devuelve |
|--------|----------|
| `AuditEvent::saleMovements()` | Los eventos que cuentan como movimiento sobre una venta ya cobrada |
| `$event->isMonetary()` | Si el evento entra en el neto del periodo |
| `$event->label()` | Su nombre legible, para la interfaz |

**Tres eventos no son monetarios**: `reopened`, `customer_assigned` y `customer_removed`. Se ven en la lista pero quedan fuera del total — reabrir *habilita* mover dinero sin moverlo, y pasar una venta a fiado saca el dinero del efectivo del día **sin perderlo**; sumarlo como pérdida daría un neto que no ocurrió.

**`payment_added` queda fuera de `saleMovements()` a propósito**: hoy solo lo escribe el módulo de compras, y registrar cada cobro llenaría la pantalla con todas las ventas del día.

El razonamiento completo, con la tabla evento por evento, está en **[modulos/movimientos.md](../modulos/movimientos.md)**, que es el consumidor principal de esta infraestructura.

## Usarlo

Inyecta `AuditLogger` y llama al método del caso. Para movimientos de venta hay un método por evento, que ya calcula el efecto:

```php
$this->auditLogger->logItemRemoved($sale, $productName, $subtotal);
$this->auditLogger->logSaleCancelled($sale, $reason);
```

Para entidades con formulario (compra, gasto, insumo), el patrón es **snapshot antes, snapshot después, y registrar solo si hubo cambio real**:

```php
$before = $this->auditLogger->purchaseSnapshot($purchase);
// ... aplicar la edición ...
$this->auditLogger->logUpdatedIfChanged($purchase, $before, $this->auditLogger->purchaseSnapshot($purchase->fresh()));
```

`logUpdatedIfChanged` no escribe nada si los dos snapshots son equivalentes: guardar renglones vacíos ensucia la bitácora sin aportar.

### Cómo compara el diff

- **Campos:** normaliza antes de comparar — los números a dos decimales en texto — para que `10` y `10.00` no cuenten como un cambio.
- **Líneas de compra:** se emparejan por su **concepto en minúsculas y sin espacios extra**, no por posición ni por id. Devuelve `added`, `removed` y `changed`, y en las cambiadas el antes y el después de cantidad y precio.

Los snapshots guardan valores **ya legibles** (el nombre de la categoría, el estado en español), para que la pantalla no tenga que traducir nada.

## Contexto de la petición

`ip_address` y `user_agent` salen de la petición si existe. En cola o consola quedan nulos, porque un cambio automático no salió de ningún equipo.

> Se resuelve por la **ausencia de petición**, no preguntando por el SAPI de PHP. Bajo PHPUnit el SAPI es CLI incluso cuando sí hay una petición HTTP, y preguntarlo dejaba el contexto vacío en todas las pruebas — es decir, imposible de comprobar.

`branch_id` se resuelve igual: lo saca de la entidad auditada, y queda nulo en las que son tenant-wide.

## Quién lo consume

| Consumidor | Qué lee |
|------------|---------|
| **[Movimientos](../modulos/movimientos.md)** | Qué se le hizo a una venta después de cobrarla. Es lo que motivó los ocho eventos de venta y la columna `amount_effect` |
| [Compras](../modulos/compras.md) | Historial de cambios de una compra y de un insumo |
| [Gastos](../modulos/gastos.md) | Historial de cambios de un gasto |

Movimientos la ve admin-empresa siempre, y admin-sucursal tras el flag `branch_admin_movements_enabled`; el cajero nunca.

## Al añadir un evento

1. Agrega el caso al enum `AuditEvent`.
2. Añade su método a `AuditLogger` — **no llames a `log()` desde un controlador**.
3. Decide su `amount_effect`: en la duda, si el cambio no altera lo que hay que entregar, va nulo — y añádelo a `isMonetary()` si no lo es.
4. Si es un movimiento sobre una venta ya cobrada, inclúyelo en `saleMovements()`, y dale su `label()`.
5. Escribe la prueba: `tests/Feature/` cubre que cada movimiento deja su renglón con el efecto correcto.
