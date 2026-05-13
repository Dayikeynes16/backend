# Cancelaciones — Analítica + rango en página operativa

**Fecha:** 2026-05-13
**Estado:** aprobado para implementar

## Motivación

Hoy la página `/sucursal/cancelaciones` (`CancelRequestController@index`) ofrece:
- Solicitudes pendientes (operativo).
- Conteo + monto cancelado de **un solo día**.
- Top 5 motivos fijos a los **últimos 30 días**.
- Historial filtrado por **un solo día**.

Faltan: rangos de fecha, comparación vs periodo anterior, gráfica por día, desglose por cajero, y vista a nivel empresa que compare sucursales.

## Decisión de arquitectura

La parte **analítica** se integra al módulo **Métricas** ya existente como una 9ª sección (`Métricas › Cancelaciones`), reutilizando `DateRange`, `AbstractMetrics`, `MetricsService` (caché), `Metrics/DateRangeFilter.vue` y `Metrics/ChartCard.vue`. La página operativa `/sucursal/cancelaciones` se mantiene para solicitudes pendientes pero su filtro pasa de un día a **rango**, y enlaza a la analítica completa.

Ventajas: cero infra nueva (reutiliza el patrón consolidado de las 8 métricas existentes); el "hub" de cancelaciones sigue siendo la página operativa; admin-empresa obtiene una vista cross-sucursal natural.

## Componentes

### Backend

**`app/Services/Metrics/CancellationMetrics.php`** (nuevo, extiende `AbstractMetrics`). Filtro base consistente con `SalesMetrics::cancelled()`:
`status = 'cancelled' AND deleted_at IS NULL AND cancelled_at BETWEEN range.start AND range.end`, más `scope()` (tenant + branch opcional).

Métodos:

- **`summary(DateRange, ?int $branchId, int $tenantId): array`** — KPIs con `deltaPair()` vs `range->previousComparable()`:
  - `cancelled_count` — `COUNT(*)`.
  - `cancelled_amount` — `SUM(total)`.
  - `pct_of_sales` — `cancelled_amount / SalesMetrics::grossSales(range, branch, tenant, ['completed','pending'])`; `null` si gross = 0. (Se inyecta `SalesMetrics` para una sola fuente de "ventas brutas".)
  - `avg_response_minutes` — `AVG(EXTRACT(EPOCH FROM (cancelled_at - cancel_requested_at)) / 60)` sobre filas con `cancel_requested_by IS NOT NULL`; `null` si ninguna.
  - `from_request_count` / `direct_count` — `SUM(CASE WHEN cancel_requested_by IS NOT NULL THEN 1 ELSE 0 END)` y su complemento.

- **`daily(DateRange, ?int $branchId, int $tenantId): array`** — un punto por día del rango vía `zeroFillDays()`. Cada punto: `{day, count, amount}`. Devuelve también la serie del rango previo, alineada por offset de día, para el chart.

- **`byReason(DateRange, ?int $branchId, int $tenantId): array`** — `GROUP BY cancel_reason` (NULL → "Sin motivo"). Por motivo: `{reason, count, amount, pct_of_count}`. Orden: `count DESC`. Sin limit (la UI corta visualmente).

- **`byCashier(DateRange, ?int $branchId, int $tenantId): array`** — UNION conceptual entre `cancelled_by` y `cancel_requested_by`. Por usuario: `{id, name, cancelled_count, cancelled_amount, requested_count}`. Orden: `cancelled_count DESC, requested_count DESC`.

- **`byBranch(DateRange, int $tenantId): array`** — solo lo consume empresa. Por sucursal: `{branch_id, name, cancelled_count, cancelled_amount, pct_of_sales}`. Orden: `cancelled_amount DESC`.

Constructor: `public function __construct(public SalesMetrics $sales) {}` (para `pct_of_sales` y `byBranch.pct_of_sales`).

### Controladores

**`app/Http/Controllers/Sucursal/Metrics/CancellationMetricsController.php`** y **`app/Http/Controllers/Empresa/Metrics/CancellationMetricsController.php`** — clones del patrón de `CashierMetricsController` (sucursal/empresa). Usan `ResolvesMetricsRequest`, `$meta->cacheKey('cancelaciones', ...)`, `Cache::remember($key, 300, ...)`.

Datos enviados a Inertia:
- Sucursal: `summary`, `daily`, `by_reason`, `by_cashier`, `history` (cursor-paginado vía query directa, no cacheada).
- Empresa: lo mismo + `by_branch` + `branches` (lista para el filtro).

`history` (no cacheado, sigue el patrón Historial): `Sale::where('status', Cancelled) ->whereBetween('cancelled_at', range)` con `cancelledByUser:id,name`, `cancelRequestedByUser:id,name`, `items`, `customer:id,name`, `orderByDesc('cancelled_at')->cursorPaginate(20)->withQueryString()`.

### Rutas

Dentro de los dos grupos `metricas` ya existentes en `routes/web.php`:
```php
Route::get('cancelaciones', SucursalCancellationMetricsController::class)->name('cancelaciones');
Route::get('cancelaciones', EmpresaCancellationMetricsController::class)->name('cancelaciones');
```
Nombres: `sucursal.metricas.cancelaciones`, `empresa.metricas.cancelaciones`.

### Registro en el índice de métricas

`MetricsIndexController` y `resources/js/Pages/{Sucursal,Empresa}/Metricas/Index.vue` — agregar tarjeta "Cancelaciones" (ícono/acento rojo) en el grid.

### Frontend

**Nuevas páginas:** `resources/js/Pages/Sucursal/Metricas/Cancelaciones.vue` y `resources/js/Pages/Empresa/Metricas/Cancelaciones.vue`. Estructura (mismo molde que `Cajeros.vue` / `Ventas.vue`):

1. Header con `DateRangeFilter` + (empresa) selector de sucursal.
2. Fila de tarjetas KPI con delta: # canceladas, monto, % sobre ventas, tiempo promedio de respuesta, chip "X de solicitud / Y directas".
3. `ChartCard` "Cancelaciones por día" — barras monto (toggle conteo), línea fantasma del periodo previo.
4. "Motivos del periodo" — tabla con barras horizontales: motivo · # · monto · %.
5. "Por cajero" — tabla: cajero · canceladas (#) · monto · solicitadas (#).
6. **Solo empresa:** "Por sucursal" — sucursal · # · monto · % sobre sus ventas.
7. "Detalle del periodo" — lista expandible scroll-infinito (folio, fecha, monto, motivo, quién canceló, quién solicitó, items, cliente).

### Cambios a lo existente

**`CancelRequestController@index`:**
- `$date` único → `$range = $this->resolveDateRange($request)` (incluyendo el trait `ResolvesMetricsRequest`).
- `stats`, `topReasons` (deja de estar fijo a 30 días) e `history` usan `cancelled_at BETWEEN range.start AND range.end`.
- Devuelve `range` en `filters` (igual que el resto).
- Solicitudes pendientes: sin cambios (independientes del rango).

**`resources/js/Pages/Sucursal/Cancelaciones/Index.vue`:**
- `DatePicker` → `Metrics/DateRangeFilter`. Mantener el resto del layout.
- En el header, link "Ver analítica completa →" a `route('sucursal.metricas.cancelaciones')`.

### Permisos

La sección Métricas ya está restringida por rol (admin-sucursal en `/sucursal/metricas/...`, admin-empresa en `/empresa/metricas/...`). Cancelaciones hereda. La página operativa sigue como está (admin-sucursal).

### Decisiones explícitas / fuera de alcance

- **No** se calcula "ratio aprobadas/rechazadas" porque los rechazos no persisten (al rechazar se limpian los campos). Se reporta solo lo que existe en datos.
- **No** se reporta "monto recuperado/revertido" — al cancelar los pagos se soft-deletean y `amount_paid` se resetea; calcular el revert sumando `withTrashed()` se evalúa en un sprint posterior si hace falta.
- El historial paginado **no** entra al caché (es por cursor con joins).

## Tests

- `tests/Feature/Services/Metrics/CancellationMetricsTest.php` — unit del servicio: `summary` con/sin previo, `daily` con días vacíos, `byReason` con NULL, `byCashier` con cancelled_by ≠ requested_by, `byBranch` cross-sucursal.
- `tests/Feature/Sucursal/Metrics/CancellationMetricsControllerTest.php` — endpoint + caché + filtro por rango.
- `tests/Feature/Empresa/Metrics/CancellationMetricsControllerTest.php` — endpoint + filtro `branch_id`.
- Actualizar `tests/Feature/Sucursal/CancelRequestIndexTest.php` (si existe) o crear: que `stats`/`topReasons`/`history` respetan el rango.

## Orden de implementación

1. `CancellationMetrics` servicio + tests.
2. Controladores sucursal/empresa + rutas + tests.
3. Páginas Vue de métricas (sucursal + empresa) + tarjeta en `Metricas/Index.vue`.
4. Cambios a `CancelRequestController` y `Cancelaciones/Index.vue` (rango + link).
5. `npm run build`.
