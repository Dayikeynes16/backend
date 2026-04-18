# Módulo de Métricas

Panel de reportes y análisis para **admin-sucursal** (una sucursal) y **admin-empresa** (multi-sucursal con selector o consolidado). Cubre siete ejes: ventas, margen, productos, clientes, cajeros, turnos y cobranza.

## Rutas

### Admin sucursal (`role:admin-sucursal|superadmin`)

| URL | Nombre | Propósito |
|-----|--------|-----------|
| `/{tenant}/sucursal/metricas` | `sucursal.metricas.index` | Dashboard resumen |
| `/{tenant}/sucursal/metricas/ventas` | `sucursal.metricas.ventas` | Volumen y tendencia |
| `/{tenant}/sucursal/metricas/margen` | `sucursal.metricas.margen` | Ganancia bruta |
| `/{tenant}/sucursal/metricas/productos` | `sucursal.metricas.productos` | Top / sin movimiento / alertas |
| `/{tenant}/sucursal/metricas/clientes` | `sucursal.metricas.clientes` | Top / saldos / inactivos |
| `/{tenant}/sucursal/metricas/cajeros` | `sucursal.metricas.cajeros` | Desempeño por cajero |
| `/{tenant}/sucursal/metricas/turnos` | `sucursal.metricas.turnos` | Diferencias de corte |
| `/{tenant}/sucursal/metricas/cobranza` | `sucursal.metricas.cobranza` | Cuentas por cobrar |

### Admin empresa (`role:admin-empresa|superadmin`)

Mismas 8 rutas con prefijo `empresa` y nombres `empresa.metricas.*`. Aceptan `?branch_id=<id>` para filtrar a una sucursal; sin parámetro o con `all` muestran consolidado del tenant.

**Autorización:** `admin-empresa` con `branch_id` de otro tenant → 403.

## Filtros globales

Todas las páginas aceptan:

- `?preset=today|yesterday|last_7_days|this_month|last_month|this_year` — preset rápido
- `?from=YYYY-MM-DD&to=YYYY-MM-DD` — rango personalizado (tope de 365 días)
- `?compare=1|0` — overlay/delta vs. periodo previo comparable
- `?refresh=1` — invalida el caché de la página actual
- `?branch_id=<id>` — solo rutas `empresa.*`

Las URLs son compartibles: copiar/pegar el link preserva filtros.

## Arquitectura

```
app/
├── Models/Setting.php                      ← clave-valor tenant-scoped
├── Services/Metrics/
│   ├── DateRange.php                       ← value object + previousComparable()
│   ├── AbstractMetrics.php
│   ├── SalesMetrics.php
│   ├── MarginMetrics.php
│   ├── ProductMetrics.php
│   ├── CustomerMetrics.php
│   ├── CashierMetrics.php
│   ├── ShiftMetrics.php
│   ├── CollectionMetrics.php
│   └── MetricsService.php                  ← fachada del índice (caché incluido)
├── Http/Controllers/Concerns/ResolvesMetricsRequest.php
├── Http/Controllers/Sucursal/Metrics/*     ← 8 controllers finos
├── Http/Controllers/Empresa/Metrics/*      ← 8 controllers finos
└── Console/Commands/BackfillCostPricesCommand.php
```

**Principios:**

- Cada `*Metrics` es invocable desde tests, artisan o jobs — no conoce HTTP.
- Todos los queries son agregados en BD (`SUM`/`COUNT`/`AVG` + `GROUP BY`), nunca sumas en PHP.
- Filtro por tenant explícito (defensa en profundidad) — no dependemos solo del global scope.
- Controllers parsean filtros, arman `DateRange`, invocan el servicio, renderizan Inertia.

### Caché

- Key: `metrics:{tenantId}:{branchIdOrAll}:{axis}:{rangeHash}`
- TTL: **5 minutos**
- Botón "Actualizar" → `?refresh=1` → invalida solo la key actual (no cross-axis)
- **No se usa `Cache::tags()`** — incompatible con el driver `database` default
- Invalidación pasiva vía TTL corto (no se toca en writes)

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
├── Components/Metrics/
│   ├── MetricsHeader.vue                   ← filtros globales (presets + custom + compare + refresh)
│   ├── KpiCard.vue                         ← KPI con delta %
│   ├── ChartCard.vue
│   ├── DataTable.vue                       ← tabla con sort + paginación client-side
│   ├── EmptyState.vue
│   ├── BackfillBanner.vue                  ← aviso de margen aproximado
│   └── Content/                            ← 8 componentes (1 por eje) reutilizados por Sucursal y Empresa
├── composables/
│   ├── useMetricsFilters.js                ← sincroniza filtros con query params
│   └── useCurrency.js                      ← format helpers
└── Pages/
    ├── Sucursal/Metricas/*                 ← 8 páginas wrapper (SucursalLayout + Content)
    └── Empresa/Metricas/*                  ← 8 páginas wrapper (EmpresaLayout + selector + Content)
```

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

46 tests cubren:
- `DateRange` (presets, custom, cap a 365 días, comparativo, hash)
- Cada servicio agregado (tenant isolation, branch filter, status filter)
- `MarginMetrics` excluye items sin costo
- `BackfillCostPrices` idempotente + fecha en settings
- Auth: admin-sucursal/admin-empresa pueden, cajero 403, guest redirect, branch_id foráneo 403

## Deploy

1. `./vendor/bin/sail artisan migrate` — aplica `cost_price_at_sale`, índices, `settings`.
2. Deploy del código (el evento `SaleItem::creating` cubre ventas nuevas sin tocar controllers).
3. `./vendor/bin/sail artisan metrics:backfill-cost-prices` — una sola vez (idempotente si se repite).
4. `./vendor/bin/sail npm install && ./vendor/bin/sail npm run build`.
5. Auditoría: `grep -r "DB::table('sale_items')->insert\|SaleItem::insert" app/` para confirmar que nadie inserta bypaseando el evento.

## Spec

Diseño detallado: [`docs/superpowers/specs/2026-04-17-metricas-sucursal-empresa-design.md`](../superpowers/specs/2026-04-17-metricas-sucursal-empresa-design.md).
