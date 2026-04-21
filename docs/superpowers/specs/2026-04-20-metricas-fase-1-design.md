# Métricas — Fase 1: Fundamentos semánticos

**Fecha:** 2026-04-20
**Alcance:** Backend de `app/Services/Metrics/*`, controllers de Sales, documentación y ajuste mínimo en `VentasContent.vue` (solo strings de labels). Ninguna pantalla nueva; ningún rediseño visual.
**Autor:** Sebas + Claude (brainstorming colaborativo).
**Estado:** Diseño aprobado por el usuario. Pendiente spec-review y plan de implementación (writing-plans).
**Relación con otros specs:**
- Complementa `2026-04-19-metricas-rediseno-design.md` (rediseño UI general de 8 ejes → 3 tabs).
- Esta Fase 1 son los **fundamentos semánticos** que deben existir antes de tocar la UI de las Fases 2–3.

---

## 1. Contexto y problema

La auditoría del 2026-04-20 (registrada en la conversación con el usuario) detectó que el módulo de Métricas tiene **arquitectura sólida** (services desacoplados, caché, tenant-scoping doble) pero **comunicación semántica débil**:

1. **Naming ambiguo**: "Ventas totales" en realidad es "ventas completadas y totalmente cobradas"; excluye silenciosamente ventas a crédito y con pago parcial.
2. **Métricas con bases distintas sin avisar**: el `revenue` de `MarginMetrics` no coincide con el `total_sales` de `SalesMetrics` (uno filtra items con costo, el otro no).
3. **"Cobrado" y "Ventas" conviven en la misma card grid mezclando fuentes** (`customer_payments` vs `sales.completed_at`) sin documentarlo.
4. **Métodos de pago hardcodeados** a 4 slugs (`cash/card/transfer/credit`); si mañana se añade otro, desaparece del desglose.
5. **Bug real en `ProductMetrics::withoutMovement()`**: no filtra `deleted_at`, así que productos eliminados aparecen como "sin movimiento".
6. **Aging de cobranza redondeado**: `diffInDays()` de Carbon produce boundaries imprecisos en los buckets 0-30 / 31-60 / 61+.

Estos puntos **no se resuelven rediseñando la UI** — primero hay que fijar definiciones, renombrar lo ambiguo, arreglar los bugs puntuales y volver dinámico el desglose de métodos. Sin esta base, cualquier rediseño visual (Fase 2+) arrastra la confusión.

**Alcance aprobado por el usuario**: opción B de tres planteadas — backend + documentación de glosario + anexo con design guidelines para Fase 2. **Cero trabajo de UI visual** en Fase 1.

---

## 2. Glosario canónico

Este glosario es la **fuente de verdad** del módulo. Se instala como sección al inicio de `docs/modulos/metricas.md` y se referencia desde la UI (en Fase 2+) vía tooltips en cada KPI.

### 2.1 Métricas del eje Flujo de dinero

| Métrica | Definición | Fuente SQL | Notas |
|---|---|---|---|
| **Ventas brutas** | Monto total de ventas entregadas en el rango, antes de restar cancelaciones. Incluye crédito y pagos parciales. | `SUM(sales.total)` donde `status IN (Completed, Pending)` AND `cancelled_at IS NULL` AND `deleted_at IS NULL`, agrupado por `COALESCE(completed_at, created_at)` dentro del rango. | Incluye ventas `Pending` entregadas pero sin cobrar. |
| **Ventas netas** | Ventas brutas menos monto cancelado dentro del rango. | Ventas brutas − `SUM(sales.total)` de ventas con `status=Cancelled` y `cancelled_at IN rango`. | **KPI principal de negocio.** En UI se muestra como "Ventas". |
| **Cobrado** | Dinero recibido en caja durante el rango, independiente de cuándo se vendió. | `SUM(payments.amount)` con `payments.created_at IN rango` AND `payments.deleted_at IS NULL`. | Incluye pagos de contado y abonos a crédito anterior. **Única fuente**: tabla `payments`. |
| **Saldo pendiente generado** | Crédito otorgado dentro del rango. | `SUM(sales.amount_pending)` para ventas con `completed_at IN rango` y `amount_pending > 0`. | Alimenta vista Cobranza, no se muestra en Resumen. |
| **# Tickets** | Número de ventas no canceladas en el rango. | `COUNT(sales.id)` con mismos filtros de Ventas brutas. | |
| **Ticket promedio** | Ventas netas ÷ # Tickets. | Derivada en código. | Si `# Tickets = 0` → UI muestra `—`, nunca `$0`. |
| **Cancelaciones** | Conteo y monto de ventas anuladas dentro del rango, agrupadas por `cancelled_at`. | `COUNT + SUM(total)` de `status=Cancelled` con `cancelled_at IN rango`. | Se muestran aparte. Ya están restadas de Ventas netas. |
| **Ganancia bruta** | Ingreso menos costo congelado al momento de venta, **solo sobre items con costo registrado**. | `SUM(subtotal − cost_price_at_sale × quantity)` sobre `sale_items` de ventas no canceladas, donde `cost_price_at_sale IS NOT NULL`. | Siempre reportar cobertura: "X de Y items con costo registrado". |
| **Margen %** | Ganancia bruta ÷ revenue (del mismo subconjunto con costo). | Derivada. | Base ≠ Ventas netas; documentado explícitamente en UI. |

### 2.2 Métricas del eje Productos

| Métrica | Definición | Fuente SQL | Notas |
|---|---|---|---|
| **Cantidad vendida por producto** | Suma de `quantity` por producto, respetando `unit_type`. | `SUM(sale_items.quantity)` agrupado por `product_id`. | Formato UI: `12.350 kg`, `8 pz`. |
| **Ingreso por producto** | Suma de `subtotal` por producto. | `SUM(sale_items.subtotal)`. | Cobertura 100%. |
| **Costo por producto** | Suma de `cost_price_at_sale × quantity` donde costo ≠ NULL. | `SUM(cost_price_at_sale × quantity) WHERE cost_price_at_sale IS NOT NULL`. | Cobertura reportada como `items_with_cost / items_total`. |
| **Ganancia por producto** | Ingreso − Costo del subconjunto con costo. | Derivada. | Badge "sin costo" cuando aplique. |
| **Margen % por producto** | Ganancia ÷ Ingreso del subconjunto con costo. | Derivada. | `—` si `items_with_cost = 0` para ese producto. |

### 2.3 Reglas transversales

- **Soft deletes**: siempre filtrar `deleted_at IS NULL` en `sales`, `sale_items`, `payments`, `products`, `customer_payments`. Fase 1 arregla los lugares donde falta (PR-3).
- **Timezone**: se mantiene `config('app.timezone')` actual. **Fuera de alcance** tocarlo en Fase 1 (riesgo: desfase de corte de día en sucursales con zona distinta).
- **Rango inclusive**: `DateRange::start = startOfDay()` y `end = endOfDay()`. Ambos extremos incluidos.
- **Cobertura de costo < 95%**: marcar la cifra de Ganancia bruta / Margen como "aproximada" en UI (Fase 2), nunca ocultar.
- **`items_with_cost = 0` en el rango**: Ganancia bruta y Margen se reportan como `—`, nunca `0`.

---

## 3. PR-1 · Glosario + refactor de `SalesMetrics`

### 3.1 Objetivos

- Fijar el glosario de la §2 en `docs/modulos/metricas.md`.
- Reemplazar `SalesMetrics::aggregateFor()` por un `summary()` que produce claves canónicas.
- Separar los cálculos internos en métodos privados con una sola responsabilidad.
- Eliminar el método viejo sin mantener alias (no hay consumidores externos).
- Actualizar el único componente frontend que consume las claves viejas (`VentasContent.vue`) — cambio de strings, sin rediseño.

### 3.2 Cambios en `app/Services/Metrics/SalesMetrics.php`

**API pública resultante**:

```php
public function summary(DateRange $range, ?int $branchId, int $tenantId): array;
public function dailySeries(DateRange $range, ?int $branchId, int $tenantId): array;  // sin cambios
public function hourDayHeatmap(DateRange $range, ?int $branchId, int $tenantId): array; // sin cambios
public function dailyTable(DateRange $range, ?int $branchId, int $tenantId): array;    // sin cambios
public function byPaymentMethod(DateRange $range, ?int $branchId, int $tenantId): array; // ← PR-2
```

**Estructura interna**:

```php
private function grossSales(DateRange $range, ?int $branchId, int $tenantId): float;
private function netSales(DateRange $range, ?int $branchId, int $tenantId): float;
private function collected(DateRange $range, ?int $branchId, int $tenantId): float;
private function ticketStats(DateRange $range, ?int $branchId, int $tenantId): array; // count + avg
private function cancelled(DateRange $range, ?int $branchId, int $tenantId): array;   // count + amount
```

**Payload que devuelve `summary()`** — claves canónicas:

```php
[
    'gross_sales'       => 12450.00,
    'net_sales'         => 11200.00,   // gross − cancelled_amount
    'collected'         => 10800.00,   // de tabla payments
    'ticket_count'      => 42,
    'avg_ticket'        => 266.67,     // net_sales / ticket_count, o null si 0 tickets
    'cancelled_count'   => 3,
    'cancelled_amount'  => 1250.00,
]
```

**Método público `summary()` (forma esperada)**:

```php
public function summary(DateRange $range, ?int $branchId, int $tenantId): array
{
    return [
        'current'  => $this->aggregate($range, $branchId, $tenantId),
        'previous' => $this->aggregate($range->previousComparable(), $branchId, $tenantId),
    ];
}

private function aggregate(DateRange $range, ?int $branchId, int $tenantId): array
{
    $gross     = $this->grossSales($range, $branchId, $tenantId);
    $cancelled = $this->cancelled($range, $branchId, $tenantId);
    $tickets   = $this->ticketStats($range, $branchId, $tenantId);

    return [
        'gross_sales'      => $gross,
        'net_sales'        => $gross - $cancelled['amount'],
        'collected'        => $this->collected($range, $branchId, $tenantId),
        'ticket_count'     => $tickets['count'],
        'avg_ticket'       => $tickets['count'] > 0 ? round(($gross - $cancelled['amount']) / $tickets['count'], 2) : null,
        'cancelled_count'  => $cancelled['count'],
        'cancelled_amount' => $cancelled['amount'],
    ];
}
```

**Método `grossSales()` — implementación de referencia**:

```php
private function grossSales(DateRange $range, ?int $branchId, int $tenantId): float
{
    return (float) DB::table('sales')
        ->where('tenant_id', $tenantId)
        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
        ->whereIn('status', [SaleStatus::Completed->value, SaleStatus::Pending->value])
        ->whereNull('cancelled_at')
        ->whereNull('deleted_at')
        ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$range->start, $range->end])
        ->sum('total');
}
```

**Método `collected()` — fuente `payments` (decisión A aprobada por el usuario)**:

```php
private function collected(DateRange $range, ?int $branchId, int $tenantId): float
{
    return (float) DB::table('payments as p')
        ->join('sales as s', 's.id', '=', 'p.sale_id')
        ->where('s.tenant_id', $tenantId)
        ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
        ->whereNull('p.deleted_at')
        ->whereBetween('p.created_at', [$range->start, $range->end])
        ->sum('p.amount');
}
```

**Clean break**: se **elimina** `aggregateFor()` completamente en este PR. Sin aliases. Los únicos consumidores son los dos `SalesMetricsController` (empresa + sucursal), que se actualizan en el mismo PR.

### 3.3 Cambios en controllers

Archivos:
- `app/Http/Controllers/Empresa/Metrics/SalesMetricsController.php`
- `app/Http/Controllers/Sucursal/Metrics/SalesMetricsController.php`

Ambos siguen pasando `data` a Inertia, pero con las claves nuevas. Deprecar la clave `by_method` antigua; queda para PR-2.

### 3.4 Cambios en frontend (mínimos)

`resources/js/Components/Metrics/Content/VentasContent.vue`:

| Acción | Detalle |
|---|---|
| Reemplazar lecturas | `data.total_sales` → `data.net_sales` |
| Renombrar label | KPI "Total vendido" → **"Ventas netas"** con hint "Excluye canceladas" |
| Preservar delta | El delta% se calcula igual (`current` vs `previous` del summary) |
| Preservar heatmap, dailySeries, dailyTable | No cambian |
| **NO tocar** | Layout, grid, estilos, gráficos, donut de métodos (eso cae en PR-2) |

### 3.5 Cambios en docs

- `docs/modulos/metricas.md`: nueva sección al inicio **"Glosario canónico"** (contenido de §2 de este spec).
- Referencia cruzada desde otros apartados del mismo doc al glosario.

---

## 4. PR-2 · `PaymentMethod` enum + desglose dinámico

### 4.1 Objetivos

- Eliminar el hardcode de `{cash, card, transfer, credit}` en `SalesMetrics::aggregateFor()` (línea 53-58 del código actual).
- Introducir enum PHP tipado para los slugs conocidos, con resolución tolerante a slugs no mapeados.
- Cambiar el desglose a **fuente `payments`** (no `sales.payment_method`, que solo registra el primer método y no captura splits).
- Agregar `count` y `average_per_payment` por método.
- El frontend recibe ya los labels resueltos; no traduce nada.

### 4.2 Nuevo enum `app/Enums/PaymentMethod.php`

```php
<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum PaymentMethod: string
{
    case Cash     = 'cash';
    case Card     = 'card';
    case Transfer = 'transfer';
    case Credit   = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash     => 'Efectivo',
            self::Card     => 'Tarjeta',
            self::Transfer => 'Transferencia',
            self::Credit   => 'Crédito',
        };
    }

    public static function resolveLabel(string $slug): string
    {
        return self::tryFrom($slug)?->label()
            ?? Str::title(str_replace('_', ' ', $slug));
    }
}
```

**Comportamiento ante slug desconocido**: `vale_despensa` → `"Vale Despensa"`. Nunca rompe, nunca devuelve `null`.

### 4.3 Nuevo método `SalesMetrics::byPaymentMethod()`

```php
public function byPaymentMethod(DateRange $range, ?int $branchId, int $tenantId): array
{
    $rows = DB::table('payments as p')
        ->join('sales as s', 's.id', '=', 'p.sale_id')
        ->where('s.tenant_id', $tenantId)
        ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
        ->whereNull('p.deleted_at')
        ->whereBetween('p.created_at', [$range->start, $range->end])
        ->selectRaw('
            p.method as method,
            COALESCE(SUM(p.amount), 0) as total,
            COUNT(*) as count,
            COALESCE(AVG(p.amount), 0) as average
        ')
        ->groupBy('p.method')
        ->orderByDesc('total')
        ->get();

    return $rows->map(fn ($r) => [
        'method'  => (string) $r->method,
        'label'   => PaymentMethod::resolveLabel((string) $r->method),
        'total'   => (float) $r->total,
        'count'   => (int) $r->count,
        'average' => round((float) $r->average, 2),
    ])->all();
}
```

**Razón de agrupar por `payments.method` y no `sales.payment_method`**: una venta puede tener pagos split (ej. `$500 efectivo + $500 tarjeta`). La columna `sales.payment_method` solo registra uno (el primero). `payments` captura todos los movimientos.

### 4.4 Integración en controller y frontend

`SalesMetricsController` (empresa + sucursal) agrega al payload de Inertia:

```php
'by_payment_method' => $this->sales->byPaymentMethod($range, $branchId, $tenantId),
```

La clave vieja `by_method` del `summary()` se **elimina** (no era necesaria ya que duplicaba información).

`resources/js/Components/Metrics/Content/VentasContent.vue`:
- El donut `paymentBreakdown` ahora se computa desde `data.by_payment_method`, iterando sin hardcodear:
  ```js
  const paymentSeries = computed(() => props.data.by_payment_method.map(m => m.total));
  const paymentLabels = computed(() => props.data.by_payment_method.map(m => m.label));
  ```
- **No cambia el componente Donut ni su estilo** — solo la fuente de datos.

---

## 5. PR-3 · Fixes: soft-delete en `withoutMovement` + aging exacto

### 5.1 Fix `ProductMetrics::withoutMovement()`

**Archivo**: `app/Services/Metrics/ProductMetrics.php` línea 156.

**Cambio**: agregar `->whereNull('p.deleted_at')` en la query.

**Antes**:
```php
->where('p.status', 'active')
->where(function ($q) use ($cutoff) { ... })
```

**Después**:
```php
->where('p.status', 'active')
->whereNull('p.deleted_at')
->where(function ($q) use ($cutoff) { ... })
```

Cambio de 1 línea. Resuelve el bug reportado en la auditoría: productos eliminados apareciendo como "sin movimiento".

### 5.2 Fix aging en `CollectionMetrics` y `CustomerMetrics`

**Archivos**:
- `app/Services/Metrics/CollectionMetrics.php` método `aging()` (líneas ~52-80).
- `app/Services/Metrics/CustomerMetrics.php` método `aging()` (líneas ~135-160).

**Problema actual**: el bucketing se hace en PHP con `$now->diffInDays($dateRef)` que redondea. Un pago de 30.4 días cae ambiguamente en `0-30` o `31-60` según hora del día.

**Solución**: mover el bucketing a SQL puro usando expresiones de diferencia de fechas exactas.

**Para PostgreSQL** (driver de producción, según `compose.yaml`):

```php
public function aging(?int $branchId, int $tenantId): array
{
    $row = DB::table('sales')
        ->where('tenant_id', $tenantId)
        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
        ->where('amount_pending', '>', 0)
        ->whereNotNull('customer_id')
        ->whereNull('deleted_at')
        ->selectRaw("
            COALESCE(SUM(CASE
                WHEN (NOW()::date - completed_at::date) <= 30 THEN amount_pending
                ELSE 0
            END), 0) AS bucket_0_30,
            COALESCE(SUM(CASE
                WHEN (NOW()::date - completed_at::date) BETWEEN 31 AND 60 THEN amount_pending
                ELSE 0
            END), 0) AS bucket_31_60,
            COALESCE(SUM(CASE
                WHEN (NOW()::date - completed_at::date) > 60 THEN amount_pending
                ELSE 0
            END), 0) AS bucket_61_plus
        ")
        ->first();

    return [
        '0-30'  => (float) $row->bucket_0_30,
        '31-60' => (float) $row->bucket_31_60,
        '61+'   => (float) $row->bucket_61_plus,
    ];
}
```

**Boundaries exactos por día calendario** (no por segundos/horas). Un pago con `completed_at` hace exactamente 30 días cae en `0-30`; uno con 31 días cae en `31-60`.

**Para `CustomerMetrics::aging()`**: mismo patrón, misma query (agrupada por customer si la función original lo requiere — revisar al implementar).

### 5.3 Nota sobre compatibilidad de driver

El compose.yaml usa PostgreSQL 18. La sintaxis `NOW()::date - completed_at::date` es PG-específica. Si el código debe correr en MySQL o SQLite en tests, usar `DB::raw` condicional o `DateTime` arithmetic. Los tests actuales usan SQLite en CI (por `phpunit.xml`).

**Decisión**: escribir la query en variante PG y asegurar que los tests de aging corran contra PG en test feature (via Sail/Docker), no SQLite. Si se necesita SQLite-compat, usar `julianday()` — decidir al implementar viendo `phpunit.xml`.

---

## 6. Anexo — Web Design Guidelines (referencia para Fases 2–3)

Este anexo **no se implementa en Fase 1**. Sirve como fuente única cuando se aborde el rediseño visual. Vive en este spec para que el equipo no tenga que redefinirlo desde cero en Fase 2.

### 6.1 Tokens de color (Tailwind, sin cambios al config)

| Uso | Token |
|---|---|
| Ventas / monto principal | `text-red-600` (identidad carnicería) |
| Ganancia / positivo | `text-green-600` |
| Cobrado / financiero neutral | `text-blue-600` |
| Cancelaciones / alertas | `text-amber-500` |
| Texto principal | `text-slate-900` |
| Labels | `text-slate-500` |
| Bordes / divisores | `border-slate-200` |
| Fondo de tabla header | `bg-slate-50` |

### 6.2 Tipografía

- **Valor de KPI**: `text-3xl font-semibold tabular-nums tracking-tight`.
- **Label de KPI**: `text-xs uppercase tracking-wide text-slate-500`.
- **Delta**: `text-xs font-medium`.
- **Números en tablas**: `tabular-nums text-right`.
- **Texto en tablas**: `text-sm text-slate-900`.

### 6.3 Jerarquía dentro de un KPI card

Orden descendente por peso visual:
1. Valor principal.
2. Label.
3. Delta (si comparación activa).
4. Hint contextual (cobertura, definición corta).

### 6.4 Densidad

- Desktop (≥1280px): max 6 KPIs por fila.
- Laptop (≥1024px): 4 por fila.
- Tablet (≥640px): 2 por fila.
- Mobile: 1 por fila.

### 6.5 Patrón de comparación Actual vs Previo

- Banner `ComparisonBanner` arriba: `Comparando: 1–7 abr 2026 vs 25–31 mar 2026  [× Quitar]`.
- Serie "Actual" en color sólido primario; serie "Previo" en `slate-400` + línea punteada + `opacity-60`.
- Delta con signo explícito: ↑ verde / ↓ rojo, **excepto cancelaciones** (↑ rojo / ↓ verde).

### 6.6 Patrones de tabla

- Header sticky, `bg-slate-50`, sort icons visibles al hover.
- Sin zebra (filas uniformes); hover con `bg-slate-50`.
- Números a la derecha, texto a la izquierda, categorías como chips debajo del nombre del producto.
- Empty state centrado con icono suave + mensaje + acción (si aplica).

### 6.7 Gráficas (ApexCharts)

- Sin border, sin shadows, grid sutil (`#f1f5f9`).
- Tooltip simple: monto + fecha.
- Series primarias color sólido; series "Previo" en gris con línea punteada.
- Animación on mount: 400ms; on data change: 0ms.

### 6.8 Estados de KPI card

| Estado | Render |
|---|---|
| Normal | Valor + label + delta opcional |
| Loading | Skeleton mismo tamaño, aparece tras 300ms |
| Sin datos | `—` en lugar del valor, hint "Sin movimientos" |
| Cobertura baja (<95%) | Pill amber al lado: `Cobertura 72%` |

### 6.9 Principios

1. **Claridad > belleza**. Un número bien presentado le gana a una gráfica decorada.
2. **Tabla primero, gráfica después**. Solo gráfica cuando aporta (tendencias, distribuciones pequeñas).
3. **Zero magic numbers**. Todo número con definición accesible (tooltip o link al glosario).
4. **Comparación explícita**. El usuario debe saber siempre contra qué se compara.
5. **Estados vacíos útiles**. Un rango sin datos explica el por qué y ofrece siguiente paso.

---

## 7. Estrategia de tests

Cada PR incluye sus tests. Sin tests no se mergea. Framework: **Pest** (alineado con `tests/Feature/Services/Metrics/` actual).

### 7.1 Tests de PR-1 — extender `SalesMetricsTest.php`

- `it_includes_pending_sales_in_gross_sales`
- `it_excludes_cancelled_sales_from_gross_sales`
- `it_subtracts_cancelled_amount_from_net_sales_when_cancelled_at_in_range`
- `it_includes_partially_paid_completed_sales_in_gross_sales` (venta con `amount_pending > 0`)
- `it_returns_collected_from_payments_table_not_customer_payments`
- `it_excludes_soft_deleted_payments_from_collected`
- `it_returns_zero_not_null_when_range_is_empty`
- `it_returns_null_avg_ticket_when_no_tickets`

### 7.2 Tests de PR-2 — nuevos

`tests/Unit/Enums/PaymentMethodTest.php`:
- `it_returns_spanish_label_for_each_known_method`
- `it_resolves_unknown_slug_to_title_case`
- `it_resolves_snake_case_slug_to_space_separated_title`

`tests/Feature/Services/Metrics/SalesMetricsPaymentBreakdownTest.php`:
- `it_groups_payments_by_method_dynamically`
- `it_includes_count_and_average_per_method`
- `it_orders_methods_by_total_desc`
- `it_handles_unknown_method_slug_gracefully` (inserta `method = 'vale_despensa'` directo en DB)
- `it_aggregates_split_payments_correctly` (una venta con 2 pagos de métodos distintos)
- `it_excludes_soft_deleted_payments_from_breakdown`

### 7.3 Tests de PR-3 — nuevos

`tests/Feature/Services/Metrics/ProductMetricsTest.php`:
- `it_excludes_soft_deleted_products_from_without_movement`
- `it_includes_active_products_without_recent_sales_in_without_movement` (regresión del happy path).

`tests/Feature/Services/Metrics/CollectionMetricsAgingTest.php`:
- `it_buckets_sale_with_exactly_30_days_into_0_30`
- `it_buckets_sale_with_31_days_into_31_60`
- `it_buckets_sale_with_60_days_into_31_60`
- `it_buckets_sale_with_61_days_into_61_plus`
- `it_returns_zero_in_all_buckets_when_no_pending`
- `it_excludes_soft_deleted_sales_from_aging`
- `it_excludes_sales_without_customer_from_aging`

`tests/Feature/Services/Metrics/CustomerMetricsAgingTest.php`:
- Espejo del anterior (CustomerMetrics).

### 7.4 Cobertura objetivo

- Cada método público nuevo con al menos **1 happy path + 1 edge case** (rango vacío, datos faltantes, slug desconocido, boundary exacto).
- No se mide % de línea — se fija en tests cada definición del glosario.

---

## 8. Riesgos y edge cases

### 8.1 Semánticos

1. **"Ventas" cambia de significado en UI**: la label pasa de "Total vendido" (implícitamente: completado + pagado) a "Ventas netas" (gross − cancelled). **Usuarios existentes pueden notar que los números suben** al incluir pendientes. Mitigación: hint visible + nota en changelog.
2. **"Cobrado" cambia de fuente**: de `customer_payments.amount_applied` a `SUM(payments.amount)`. **El número puede diferir** si hay pagos que no generaron `customer_payment` (caso normal en ventas de contado). Esperable que el nuevo "Cobrado" sea **≥** el anterior. Validar con datos reales antes de merge.
3. **Ventas `Pending` sin `completed_at`**: el glosario las entra por `created_at`. Validar que no hay ventas en estado `Pending` con `created_at IN rango` pero que no sean realmente "entregadas" (riesgo: borradores). Revisar lifecycle del enum `SaleStatus`.

### 8.2 Técnicos

4. **Sintaxis PostgreSQL en aging**: tests deben correr contra PG o usar variante compatible. Resolver al implementar verificando `phpunit.xml`.
5. **Performance de nueva query `grossSales`**: incluye `Pending` que antes no se consultaba. Confirmar que el índice actual `(tenant_id, branch_id, completed_at)` cubre también filtros por `created_at`. Si no, tomar nota y abordar en fase posterior.
6. **Cache invalidation**: TTL 300s sigue igual. Tras merge, purgar cache manualmente para evitar ver datos stale con claves viejas (`total_sales`) mientras ya no existen en backend. **Acción de release**: redeploy con `php artisan cache:clear`.
7. **Driver de test**: si `phpunit.xml` usa SQLite, la query de aging PG-específica falla. Documentar y ajustar en PR-3.

### 8.3 De proceso

8. **3 PRs en orden**: PR-2 depende de PR-1 (reutiliza `SalesMetrics` como host de `byPaymentMethod`). PR-3 es independiente, puede mergear primero o último.
9. **Rollback**: cada PR es reversible con `git revert`. PR-1 es el más invasivo (toca controllers + frontend); validar en staging antes de producción.

---

## 9. Criterios de aceptación

Fase 1 se considera completada cuando:

- [ ] `docs/modulos/metricas.md` tiene sección "Glosario canónico" al inicio.
- [ ] `SalesMetrics::summary()` existe y devuelve las 7 claves canónicas; `aggregateFor()` ya no existe.
- [ ] `SalesMetricsController` (empresa + sucursal) consumen el nuevo payload.
- [ ] `VentasContent.vue` renderiza correctamente los nuevos campos con el label "Ventas netas".
- [ ] `app/Enums/PaymentMethod.php` existe con 4 cases + `label()` + `resolveLabel()`.
- [ ] `SalesMetrics::byPaymentMethod()` devuelve array dinámico con `{method, label, total, count, average}`.
- [ ] Donut de métodos de pago en `VentasContent.vue` itera sobre la respuesta backend sin hardcodear slugs.
- [ ] `ProductMetrics::withoutMovement()` filtra `deleted_at IS NULL`.
- [ ] `CollectionMetrics::aging()` y `CustomerMetrics::aging()` hacen bucketing en SQL con boundaries exactos.
- [ ] Todos los tests listados en §7 pasan en verde.
- [ ] Suite existente de métricas sigue verde (no hay regresiones).
- [ ] Anexo de Web Design Guidelines (§6) presente en este spec.

---

## 10. Fuera de alcance (confirmado)

- Cualquier rediseño visual de las pantallas de métricas (Fases 2–3).
- Consolidación de 8 páginas → 3 tabs (Fase 2).
- Selector de modo de comparación en UI (`previous | last_year | custom`).
- Paginación server-side en tablas de productos.
- Caché tags o invalidación activa al crear ventas.
- Timezone por branch.
- Endpoint API JSON separado.

---

## 11. Plan de implementación

Sigue en writing-plans tras la revisión del spec.
