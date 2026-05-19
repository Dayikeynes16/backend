# Módulo de Métricas

Panel de reportes y análisis para **admin-sucursal** (una sucursal) y **admin-empresa** (multi-sucursal con selector o consolidado). Cubre **ocho ejes**: ventas, margen, productos, clientes, cajeros, turnos, cobranza y cancelaciones.

> **Nota (mayo 2026):** El módulo se simplificó. El "Resumen" dejó de mostrar KPIs y se convirtió en un **hub de navegación** (grid 4×2 de ejes). El header se redujo a 3 presets de fecha + calendario; se eliminaron el toggle "Comparar" y el botón "Actualizar". Los ejes individuales mantienen sus KPIs/gráficos pero ya no exhiben deltas ±% ni líneas de periodo previo. Spec: [`docs/superpowers/specs/2026-05-17-metricas-simplificacion-design.md`](../superpowers/specs/2026-05-17-metricas-simplificacion-design.md). Plan: [`docs/superpowers/plans/2026-05-18-metricas-simplificacion-plan.md`](../superpowers/plans/2026-05-18-metricas-simplificacion-plan.md).

## Glosario canónico

Este glosario es la fuente de verdad del módulo. Todo cálculo nuevo o refactor debe referenciar estas definiciones. Ampliación formal: `docs/superpowers/specs/2026-04-20-metricas-fase-1-design.md`.

### Flujo de dinero

| Métrica | Definición | Notas |
|---|---|---|
| **Ventas** (`gross_sales` = `net_sales`) | `SUM(sales.total)` de las ventas con `status` en el conjunto seleccionado **(default: solo `Completed`)** AND `cancelled_at IS NULL` (salvo que se incluya `cancelled`) AND `deleted_at IS NULL`, agrupado por `COALESCE(completed_at, created_at)` dentro del rango. | **KPI principal**, en UI "Ventas" / "Ventas netas". Por default solo `Completed`; el chip de estados permite añadir `Pending` y/o `Cancelled`. Incluye crédito y pagos parciales. `net_sales == gross_sales` — las cancelaciones **ya no se restan** (ver fila Cancelaciones). |
| **Cobrado** (`collected`) | Dinero recibido en caja durante el rango. `SUM(payments.amount)` con `payments.created_at IN rango` AND `payments.deleted_at IS NULL`. | Única fuente: tabla `payments`. Incluye contado y abonos a crédito anterior. |
| **Saldo pendiente generado** | Crédito otorgado en el rango. `SUM(sales.amount_pending)` donde `completed_at IN rango` y `amount_pending > 0`. | Alimenta vista Cobranza. |
| **# Tickets** (`ticket_count`) | `COUNT(sales)` con los mismos filtros de Ventas. | |
| **Ticket promedio** (`avg_ticket`) | `gross_sales / ticket_count`. | Si `ticket_count = 0` → `null` (UI muestra `—`). |
| **Cancelaciones** (`cancelled_count` + `cancelled_amount`) | Conteo y monto de `status=Cancelled` agrupado por `cancelled_at` en rango. | Cifra **informativa**, se muestra aparte. **No** se resta de Ventas. |
| **Ganancia bruta** (`gross_profit`) | `SUM(subtotal − cost_price_at_sale × quantity)` sobre items de ventas no canceladas **donde `cost_price_at_sale IS NOT NULL`**. | Reportar cobertura: "X de Y items con costo registrado". |
| **Margen %** | `gross_profit / revenue` del subconjunto con costo. | Base ≠ Ventas netas; documentado explícitamente en UI. |

### Productos

| Métrica | Definición | Notas |
|---|---|---|
| **Cantidad vendida** | `SUM(sale_items.quantity)` por `product_id`, respetando `unit_type`. | Formato UI: `12.350 kg`, `8 pz`. |
| **Ingreso por producto** | `SUM(sale_items.subtotal)` por producto. | Cobertura 100%. |
| **Costo por producto** | `SUM(cost_price_at_sale × quantity)` donde costo no es nulo. | Cobertura reportada. |
| **Ganancia por producto** | Ingreso − Costo del subconjunto con costo. | Badge "sin costo" cuando aplique. |
| **Margen % por producto** | Ganancia ÷ Ingreso del subconjunto con costo. | `—` si `items_with_cost = 0`. |

### Reglas transversales

- **Soft deletes**: siempre filtrar `deleted_at IS NULL` en `sales`, `sale_items`, `payments`, `products`, `customer_payments`.
- **Timezone**: `config('app.timezone')`. No hay override por branch.
- **Rango inclusive**: `DateRange::start = startOfDay()`, `end = endOfDay()`.
- **Cobertura de costo < 95%**: UI marca la cifra como aproximada; nunca la oculta.
- **Sin items con costo en rango**: `gross_profit` y margen se reportan como `—`, nunca como `0`.

## Rutas

### Admin sucursal (`role:admin-sucursal|superadmin`)

| URL | Nombre | Propósito |
|-----|--------|-----------|
| `/{tenant}/sucursal/metricas` | `sucursal.metricas.index` | **Hub de navegación** (grid 4×2 de ejes) |
| `/{tenant}/sucursal/metricas/ventas` | `sucursal.metricas.ventas` | Volumen y tendencia |
| `/{tenant}/sucursal/metricas/margen` | `sucursal.metricas.margen` | Ganancia bruta |
| `/{tenant}/sucursal/metricas/productos` | `sucursal.metricas.productos` | Top / sin movimiento / alertas |
| `/{tenant}/sucursal/metricas/clientes` | `sucursal.metricas.clientes` | Top / saldos / inactivos |
| `/{tenant}/sucursal/metricas/cajeros` | `sucursal.metricas.cajeros` | Desempeño por cajero |
| `/{tenant}/sucursal/metricas/turnos` | `sucursal.metricas.turnos` | Diferencias de corte |
| `/{tenant}/sucursal/metricas/cobranza` | `sucursal.metricas.cobranza` | Cuentas por cobrar |
| `/{tenant}/sucursal/metricas/cancelaciones` | `sucursal.metricas.cancelaciones` | Motivos y tiempo de respuesta |

**Resumen (`index`)**: No transporta `data` ni `backfill_run_at`. El payload Inertia solo incluye `range`, `presets`, `statuses`, `tenant`, `selected_branch_id` (Empresa añade `branches`). Ver `MetricsHubGrid.vue` para el grid de ejes; los filtros del header se propagan al eje destino vía URL.

### Admin empresa (`role:admin-empresa|superadmin`)

Mismas 8 rutas con prefijo `empresa` y nombres `empresa.metricas.*`. Aceptan `?branch_id=<id>` para filtrar a una sucursal; sin parámetro o con `all` muestran consolidado del tenant.

**Autorización:** `admin-empresa` con `branch_id` de otro tenant → 403.

## Filtros globales

Todas las páginas aceptan:

- `?preset=today|yesterday|last_7_days` — preset rápido (3 valores)
- `?from=YYYY-MM-DD&to=YYYY-MM-DD` — rango personalizado vía Calendario (tope 365 días); internamente preset = `__custom__`
- `?statuses=completed,pending,cancelled` — filtro de estados para "venta generada" (default solo `completed`). Solo aplica en Resumen/Ventas/Productos/Clientes
- `?branch_id=<id>` — solo rutas `empresa.*`

URLs antiguas con `?preset=this_month|last_month|this_year`, `?compare=1`, `?refresh=1` se aceptan en silencio: los presets retirados caen al default (`today`), y `compare`/`refresh` ya no se leen ni se emiten en `commonProps()`.

Las URLs son compartibles: copiar/pegar el link preserva filtros, y entrar a un eje desde el hub conserva los filtros que estaban activos.

## Arquitectura

```
app/
├── Models/Setting.php                      ← clave-valor tenant-scoped
├── Services/Metrics/
│   ├── DateRange.php                       ← value object; PRESETS=[today,yesterday,last_7_days]
│   ├── AbstractMetrics.php
│   ├── SalesMetrics.php
│   ├── MarginMetrics.php
│   ├── ProductMetrics.php
│   ├── CustomerMetrics.php
│   ├── CashierMetrics.php
│   ├── ShiftMetrics.php
│   ├── CancellationMetrics.php
│   ├── CollectionMetrics.php
│   └── MetricsService.php                  ← helpers de caché (cacheKey/forget) + backfillDate
├── Http/Controllers/Concerns/ResolvesMetricsRequest.php
├── Http/Controllers/Sucursal/Metrics/*     ← 9 controllers finos (Index + 8 ejes)
├── Http/Controllers/Empresa/Metrics/*      ← 9 controllers finos
└── Console/Commands/BackfillCostPricesCommand.php
```

**Después de la simplificación (mayo 2026):**

- `MetricsService::dashboardSummary()` fue **eliminado**: el Index ya no calcula KPIs, solo enruta. El servicio quedó como helper de caché y `backfillDate()`. Su constructor ya no inyecta los 7 `*Metrics`.
- `ResolvesMetricsRequest` ya no expone `compareEnabled()` ni `wantsRefresh()`; `commonProps()` no emite `compare` ni `refresh`.
- `SalesMetrics::summary()`, `MarginMetrics::summary()` y `CancellationMetrics::summary()` ya no retornan la rama `'previous'` (queda solo `['current' => …]`). El `previousComparable()` de `DateRange` se mantiene **solo** porque lo usan dos consumidores externos ajenos a Métricas: `DailySummaryService` (Dashboard, "vs ayer") y `SalesSummaryTool` (asistente IA, `delta_pct`). Esos consumidores hacen dos llamadas a `SalesMetrics::summary()` con rangos distintos en lugar de leer una rama anidada.

**Principios:**

- Cada `*Metrics` es invocable desde tests, artisan o jobs — no conoce HTTP.
- Todos los queries son agregados en BD (`SUM`/`COUNT`/`AVG` + `GROUP BY`), nunca sumas en PHP.
- Filtro por tenant explícito (defensa en profundidad) — no dependemos solo del global scope.
- Controllers parsean filtros, arman `DateRange`, invocan el servicio, renderizan Inertia.

### Resumen del día (`DailySummaryService`)

`app/Services/DailySummaryService.php` es la **fuente única de verdad** para el "resumen de hoy" de las pantallas operativas: Dashboard (Sucursal y Empresa), Historial y Pagos. No reimplementa nada: arma un `DateRange` de un solo día y **delega los agregados de venta a `SalesMetrics::summary()`** con el default de estados (solo `Completed`), de modo que esas pantallas y el módulo de Métricas (con su chip en "Completadas") muestran exactamente los mismos números para un mismo día — misma fecha canónica `COALESCE(completed_at, created_at)`, mismo glosario. Las cancelaciones del día se exponen como cifra aparte (`cancelled_amount`/`cancelled_count`), no se restan de "Ventas netas".

Lo único propio del servicio es la **cobranza del día desglosada por método** con split por antigüedad de la venta — `from_today` (ventas cuyo día canónico es la fecha) vs `from_previous` (abonos a cuentas anteriores) —, un cálculo específico de "hoy" que no aplica a rangos arbitrarios. Lista siempre los métodos habilitados aunque tengan `$0`.

- `forDate(?int $branchId, int $tenantId, string $date, array $paymentMethods)` → `['sales' => …, 'sales_yesterday' => …, 'delta_pct' => …, 'collections' => …]`
- `hourlySeries(?int $branchId, int $tenantId, string $date)` → mapa `hora => {trx, total}` (delega en `SalesMetrics::hourlySeries()`)
- `branchId = null` agrega todas las sucursales del tenant.

Equivalencia verificada en `tests/Feature/Services/DailySummaryServiceTest.php` (los números de `DailySummaryService` coinciden con los de `SalesMetrics` para el mismo día).

### Caché

- Key: `metrics:{tenantId}:{branchIdOrAll}:{axis}:{rangeHash}`
- TTL: **5 minutos**
- Invalidación: solo TTL. El botón "Actualizar" y el query param `?refresh=1` fueron retirados; si necesitas forzar refresco manualmente, espera al TTL o usa `MetricsService::forget()` desde tinker.
- **No se usa `Cache::tags()`** — incompatible con el driver `database` default
- Invalidación pasiva vía TTL corto (no se toca en writes)

### Regla de cálculo de ganancia y margen

La ganancia bruta y el margen se calculan como `(precio de venta − costo registrado) × cantidad`, usando el costo que estaba vigente al momento exacto de la venta (campo `sale_items.cost_price_at_sale`).

Si un producto se vendió sin costo registrado (porque no se había capturado en el catálogo al momento de la venta), esa venta **se excluye del cálculo de margen**, pero sí aparece en Ventas y Productos. La vista de Margen lo comunica con tres indicadores coordinados:

- **Banner** arriba de la página cuando `items_without_cost > 0`, con conteo y link a filtrar la tabla.
- **Footnote** en el KPI de "Ganancia bruta" del tipo `Basado en N items con costo · M excluidos`.
- **Badge** `sin costo` por fila en la tabla de productos que tuvieron al menos una venta sin costo registrado.

**Semántica importante:** el campo `revenue` que expone `MarginMetrics::aggregateFor()` representa el **ingreso de items con costo registrado** (denominador correcto del `margin_pct`). El ingreso total de la sucursal (incluyendo items sin costo) vive en `SalesMetrics::summary()`. No se mezclan en el mismo payload.

A nivel implementación, `MarginMetrics` usa `whereNotNull('sale_items.cost_price_at_sale')` de forma uniforme en los cuatro métodos (`aggregateFor`, `dailyGrossProfit`, `byCategory`, `byProduct`). Los conteos de cobertura (`items_without_cost`, `has_missing_cost`) se calculan en queries separados sin ese filtro, para poder reportar la brecha sin contaminar las sumas.

### Costo histórico

`sale_items.cost_price_at_sale` se completa automáticamente vía `SaleItem::creating` con `products.cost_price` al momento de crear el item. Funciona en todos los flujos (Workbench, API v1, ediciones) sin tocar controllers.

Para ventas previas a la instalación del módulo, correr:

```bash
php artisan metrics:backfill-cost-prices
```

El comando:
- Rellena items con `cost_price_at_sale IS NULL` usando el costo actual del producto como aproximación.
- No sobreescribe valores existentes (idempotente).
- Registra la fecha en `settings` (`metrics.backfill_run_at`) — usada por el banner UI que avisa que los márgenes antes de esa fecha son aproximados.

### Timezone

Los `DATE()` / `EXTRACT()` operan sobre `completed_at` directo (se asume que la columna ya está en la zona horaria del app). No usar `AT TIME ZONE` con `timestamp without time zone` porque desplaza el día en fronteras de UTC.

## Frontend

```
resources/js/
├── Layouts/
│   └── MetricsLayout.vue                   ← envuelve SucursalLayout, añade sub-sidebar + breadcrumb
├── Components/Metrics/
│   ├── MetricsHeader.vue                   ← título + selector sucursal + DateRangeFilter + StatusFilterChips + "Mostrando: …"
│   ├── DateRangeFilter.vue                 ← segmented [Hoy / Ayer / 7 días] + Calendario
│   ├── MetricsHubGrid.vue                  ← grid 4×2 de ejes (Resumen); propaga filtros en URL
│   ├── MetricsSubSidebar.vue               ← navegación entre ejes, preserva filtros
│   ├── MetricsBreadcrumb.vue               ← Sucursal › Métricas › <Eje> + botón "Volver al resumen"
│   ├── MarginCoverageBanner.vue            ← banner de items sin costo en Margen
│   ├── KpiCard.vue                         ← KPI con hint/footnote (sin chip de delta)
│   ├── ChartCard.vue                       ← título + subtítulo inline + slot
│   ├── TimeSeriesCard.vue                  ← tendencia adaptativa (single/bars/line); una sola serie
│   ├── DataTable.vue                       ← tabla con sort + paginación client-side
│   ├── EmptyState.vue
│   ├── BackfillBanner.vue                  ← aviso de margen aproximado (solo en ejes, no en Resumen)
│   └── Content/                            ← 8 componentes (1 por eje) reutilizados por Sucursal y Empresa
├── composables/
│   ├── useMetricsFilters.js                ← sincroniza preset/from/to/branchId/statuses con query params
│   ├── useDateRange.js                     ← labels y format helpers de rangos
│   └── useCurrency.js                      ← format helpers
└── Pages/
    ├── Sucursal/Metricas/Index.vue         ← MetricsLayout + MetricsHeader + MetricsHubGrid
    ├── Sucursal/Metricas/{Ventas,Margen,…}.vue ← 8 páginas wrapper de ejes
    └── Empresa/Metricas/*                  ← idem con EmpresaLayout + selector de sucursal
```

**Cambios tras la simplificación:**

- `MetricsHubGrid.vue` reemplaza a `Content/IndexContent.vue` (eliminado). Solo renderiza navegación; no KPIs ni gráficos.
- `MetricsHeader.vue` perdió el toggle "Comparar" y el botón "Actualizar". Subheader solo lista "Mostrando: …" sin "vs. periodo previo".
- `TimeSeriesCard.vue` ya no acepta props `previous` ni `compare`; render de una sola serie con headline + mejor/peor punto.
- `KpiCard.vue` ya no muestra el chip de delta. Su prop `delta` queda declarada por compat hacia atrás (no rompe callsites existentes que la pasen) pero no se usa.
- `useMetricsFilters.js` ya no expone `compare`, `setCompare`, `refresh`, `setRefresh`. Lista de presets válidos: `['today','yesterday','last_7_days','__custom__']`.

**Charts:** ApexCharts (vue3-apexcharts), registrado globalmente en `app.js` como `<apexchart>`.

## Agregar un nuevo eje

1. Crear `app/Services/Metrics/MiNuevoMetrics.php` que extiende `AbstractMetrics`.
2. Crear `app/Http/Controllers/Sucursal/Metrics/MiNuevoMetricsController.php` (y el gemelo en `Empresa/`).
3. Registrar la ruta en ambos grupos (`sucursal.metricas.*` y `empresa.metricas.*`) en `routes/web.php`.
4. Crear `resources/js/Components/Metrics/Content/MiNuevoContent.vue`.
5. Crear las 2 páginas wrapper (`Sucursal/Metricas/MiNuevo.vue`, `Empresa/Metricas/MiNuevo.vue`).
6. Agregar el link al layout (`SucursalLayout.vue`, `EmpresaLayout.vue`) si se quiere en el sidebar.
7. Agregar tests: feature para el servicio + controller auth test.

## Testing

```bash
./vendor/bin/sail artisan test tests/Unit/Services/Metrics
./vendor/bin/sail artisan test tests/Feature/Services/Metrics
./vendor/bin/sail artisan test tests/Feature/Console/BackfillCostPricesTest.php
./vendor/bin/sail artisan test tests/Feature/Http/Sucursal/Metrics
./vendor/bin/sail artisan test tests/Feature/Http/Empresa/Metrics
```

Cobertura clave:
- `DateRange` (presets, custom, cap a 365 días, hash). `PRESETS` está restringido a `[today, yesterday, last_7_days]`; presets retirados lanzan en `preset()` directo y caen al default en `fromRequest()`.
- `MetricsIndexResponseTest` (Sucursal y Empresa): la respuesta Inertia del Resumen incluye `range`, `presets`, `statuses`, `tenant`, `selected_branch_id` (+ `branches` en Empresa) y **no** incluye `compare`, `refresh`, `data` ni `backfill_run_at`. `range` no expone la rama `previous`.
- Cada servicio agregado (tenant isolation, branch filter, status filter).
- `MarginMetrics` excluye items sin costo.
- `BackfillCostPrices` idempotente + fecha en settings.
- Auth: admin-sucursal/admin-empresa pueden, cajero 403, guest redirect, branch_id foráneo 403.

## Deploy

1. `./vendor/bin/sail artisan migrate` — aplica `cost_price_at_sale`, índices, `settings`.
2. Deploy del código (el evento `SaleItem::creating` cubre ventas nuevas sin tocar controllers).
3. `./vendor/bin/sail artisan metrics:backfill-cost-prices` — una sola vez (idempotente si se repite).
4. `./vendor/bin/sail npm install && ./vendor/bin/sail npm run build`.
5. Auditoría: `grep -r "DB::table('sale_items')->insert\|SaleItem::insert" app/` para confirmar que nadie inserta bypaseando el evento.

## Spec

- Diseño inicial: [`docs/superpowers/specs/2026-04-17-metricas-sucursal-empresa-design.md`](../superpowers/specs/2026-04-17-metricas-sucursal-empresa-design.md).
- Rediseño de navegación + transparencia de margen: [`docs/superpowers/specs/2026-04-19-metricas-rediseno-design.md`](../superpowers/specs/2026-04-19-metricas-rediseno-design.md).
- Glosario canónico y reglas transversales: [`docs/superpowers/specs/2026-04-20-metricas-fase-1-design.md`](../superpowers/specs/2026-04-20-metricas-fase-1-design.md).
- **Simplificación (mayo 2026)**: [`docs/superpowers/specs/2026-05-17-metricas-simplificacion-design.md`](../superpowers/specs/2026-05-17-metricas-simplificacion-design.md) + [`docs/superpowers/plans/2026-05-18-metricas-simplificacion-plan.md`](../superpowers/plans/2026-05-18-metricas-simplificacion-plan.md).
