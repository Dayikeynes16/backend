# Módulo de Métricas — admin-sucursal y admin-empresa

**Fecha:** 2026-04-17
**Autor:** Sebas (con apoyo de Claude)
**Estado:** Diseño — pendiente de aprobación del usuario

## 1. Contexto y objetivo

El admin-sucursal hoy cuenta con un `Sucursal/Dashboard` básico que muestra totales del día (ventas por método de pago, top 5 productos, turnos recientes). No hay herramientas para:

- Analizar tendencias por rango de fecha.
- Evaluar **rentabilidad** (el campo `products.cost_price` existe pero no se explota).
- Detectar patrones operativos (horas pico, comportamiento por cajero, clientes inactivos, saldos pendientes).
- Comparar periodos entre sí (hoy vs. ayer, este mes vs. anterior).

Este módulo entrega un sistema de **métricas y reportes** con siete ejes, filtros por rango de fecha con comparativos automáticos, y acceso tanto para admin-sucursal (una sucursal) como para admin-empresa (multi-sucursal). No sustituye el dashboard operativo actual — lo complementa con una vista analítica.

## 2. Alcance v1

**Incluido:**
- Siete ejes de métricas: Ventas, Margen, Productos, Clientes, Cajeros, Caja/Turnos, Cobranza.
- Filtro de rango: presets (`today`, `yesterday`, `last_7_days`, `this_month`, `last_month`, `this_year`) + rango custom (`from`/`to`) + toggle comparativo automático contra periodo previo comparable.
- Acceso para admin-sucursal (scope a su `branch_id`) y admin-empresa (selector de sucursal o consolidado multi-sucursal dentro de su tenant).
- Índice con KPIs resumidos + subpáginas por eje.
- Snapshot del costo al momento de la venta (`sale_items.cost_price_at_sale`) con comando de backfill idempotente.
- Caché de respuestas con TTL 5 min y botón "Actualizar" para bypass.
- UI premium (Vue 3 + Tailwind + ApexCharts) con lineamientos de `frontend-design`.
- Tests Pest: unitarios para `DateRange`, feature por eje, feature por controller, feature para comando backfill.

**Excluido (v2+):**
- Exportación (CSV/PDF/Excel).
- Vista de superadmin (nivel tenant global).
- Streaming en tiempo real (usuario no lo necesita — reload = números frescos).
- Alertas automáticas (productos sin costo, margen en rojo, etc.) — se muestran pasivamente en la UI, no generan notificaciones push.
- Tabla de historial de costos (`product_cost_history`). Usamos snapshot en sale_items, que cubre el caso principal.

## 3. Arquitectura

### 3.1 Capa de servicios (`app/Services/Metrics/`)

```
Services/Metrics/
├── MetricsService.php          ← fachada del índice (invoca varios ejes)
├── DateRange.php               ← value object inmutable
├── SalesMetrics.php
├── MarginMetrics.php
├── ProductMetrics.php
├── CustomerMetrics.php
├── CashierMetrics.php
├── ShiftMetrics.php
└── CollectionMetrics.php
```

**Principios:**

- Cada clase `*Metrics` es invocable desde tests, artisan commands o jobs. No conoce HTTP ni Inertia.
- Firma pública típica:
  ```php
  public function summary(DateRange $range, ?int $branchId, int $tenantId): array
  public function timeSeries(DateRange $range, ?int $branchId, int $tenantId): array
  ```
- `branchId = null` en contexto empresa ⇒ consolidado de todas las sucursales del tenant.
- Todos los queries agregados en BD (`SUM`, `AVG`, `COUNT`, `GROUP BY`). Nunca `->get()` + suma en PHP.

### 3.2 `DateRange` (value object)

- Construcción: `DateRange::preset('this_month')` o `DateRange::custom($from, $to)`.
- Resuelve zona horaria del tenant (por ahora usa `config('app.timezone')`, se documenta para v2 tenant-aware).
- Método `previousComparable(): DateRange` — devuelve el rango previo del mismo tamaño (con ajuste: si el rango actual es "este mes hasta hoy", el previo es "mes pasado del día 1 al mismo día del mes").
- Método `hash(): string` — para key de caché.

### 3.3 Controllers

```
Http/Controllers/Sucursal/Metrics/
├── MetricsIndexController.php
├── SalesMetricsController.php
├── MarginMetricsController.php
├── ProductMetricsController.php
├── CustomerMetricsController.php
├── CashierMetricsController.php
├── ShiftMetricsController.php
└── CollectionMetricsController.php

Http/Controllers/Empresa/Metrics/
├── MetricsIndexController.php
├── SalesMetricsController.php
├── MarginMetricsController.php
├── ProductMetricsController.php
├── CustomerMetricsController.php
├── CashierMetricsController.php
├── ShiftMetricsController.php
└── CollectionMetricsController.php
```

Los controllers son finos: parsean query string, construyen `DateRange`, llaman al servicio, renderizan Inertia. No contienen lógica de agregación. Los de `Empresa/` reciben un `?branch_id` opcional y renderizan `Pages/Empresa/Metricas/*`.

### 3.4 Rutas

```
/{tenant}/sucursal/metricas                GET  → Sucursal\Metrics\MetricsIndexController    name: sucursal.metricas.index
/{tenant}/sucursal/metricas/ventas         GET  → Sucursal\Metrics\SalesMetricsController     name: sucursal.metricas.ventas
/{tenant}/sucursal/metricas/margen         GET  → Sucursal\Metrics\MarginMetricsController    name: sucursal.metricas.margen
/{tenant}/sucursal/metricas/productos      GET  → Sucursal\Metrics\ProductMetricsController   name: sucursal.metricas.productos
/{tenant}/sucursal/metricas/clientes       GET  → Sucursal\Metrics\CustomerMetricsController  name: sucursal.metricas.clientes
/{tenant}/sucursal/metricas/cajeros        GET  → Sucursal\Metrics\CashierMetricsController   name: sucursal.metricas.cajeros
/{tenant}/sucursal/metricas/turnos         GET  → Sucursal\Metrics\ShiftMetricsController     name: sucursal.metricas.turnos
/{tenant}/sucursal/metricas/cobranza       GET  → Sucursal\Metrics\CollectionMetricsController name: sucursal.metricas.cobranza

/{tenant}/empresa/metricas                 GET  → Empresa\Metrics\MetricsIndexController      name: empresa.metricas.index
/{tenant}/empresa/metricas/ventas          GET  → Empresa\Metrics\SalesMetricsController      name: empresa.metricas.ventas
/{tenant}/empresa/metricas/margen          GET  → Empresa\Metrics\MarginMetricsController     name: empresa.metricas.margen
/{tenant}/empresa/metricas/productos       GET  → Empresa\Metrics\ProductMetricsController    name: empresa.metricas.productos
/{tenant}/empresa/metricas/clientes        GET  → Empresa\Metrics\CustomerMetricsController   name: empresa.metricas.clientes
/{tenant}/empresa/metricas/cajeros         GET  → Empresa\Metrics\CashierMetricsController    name: empresa.metricas.cajeros
/{tenant}/empresa/metricas/turnos          GET  → Empresa\Metrics\ShiftMetricsController      name: empresa.metricas.turnos
/{tenant}/empresa/metricas/cobranza        GET  → Empresa\Metrics\CollectionMetricsController name: empresa.metricas.cobranza
```

Middleware stack (existente): `resolve.tenant` → `auth` → `ensure.tenant` → `role:admin-sucursal` (o `role:admin-empresa`).

### 3.5 Autorización

- `admin-sucursal`: `branchId` se toma de `Auth::user()->branch_id`. El usuario **no puede** pasar `?branch_id=otro` — el controller lo ignora.
- `admin-empresa`: `?branch_id=<id>` filtra a esa sucursal; sin parámetro o `?branch_id=all` ⇒ consolidado de todas las sucursales del tenant.
- **Validación estricta en admin-empresa:** se valida `branch_id` con la regla `Rule::exists('branches', 'id')->where('tenant_id', $currentTenantId)`. Si falla → respuesta **403 Forbidden** (no 404, no "filtrar a nada"). El intento queda en el log de seguridad.
- Tenant scope: `BelongsToTenant` ya cubre aislamiento a nivel modelo. Los servicios reciben `tenantId` explícito para defensa en profundidad en queries con `DB::raw`.
- `cajero` y otros roles: 403.

## 4. Migración de costo histórico

### 4.1 Nueva migración

`database/migrations/2026_04_17_000001_add_cost_price_at_sale_to_sale_items_table.php`:

```php
Schema::table('sale_items', function (Blueprint $table) {
    // Nota: Postgres ignora ->after() (es MySQL-only). Se omite intencionalmente.
    $table->decimal('cost_price_at_sale', 10, 2)->nullable();
    $table->index('cost_price_at_sale');
});
```

### 4.2 Asignación automática vía evento del modelo

Para evitar olvidos en cualquier punto de creación de items (existen múltiples: `WorkbenchController`, `Api/V1/SaleController`, ediciones desde `SaleHistoryController`, etc.), la asignación se hace en el modelo:

```php
// app/Models/SaleItem.php
protected static function booted(): void
{
    static::creating(function (SaleItem $item) {
        if ($item->cost_price_at_sale === null && $item->product_id) {
            $item->cost_price_at_sale = Product::withoutGlobalScopes()
                ->where('id', $item->product_id)
                ->value('cost_price');
        }
    });
}
```

**Por qué `withoutGlobalScopes`:** evita problemas si el evento se dispara en un contexto sin tenant resuelto (jobs, comandos). El producto ya fue validado upstream para pertenecer al tenant.

`SaleItem::$fillable` añade `'cost_price_at_sale'` y `casts()` incluye `'cost_price_at_sale' => 'decimal:2'`.

**Auditoría:** durante la implementación, se hace `grep` por `SaleItem::create`, `new SaleItem`, `->items()->create` para confirmar que ninguna llamada inserta un item bypaseando el evento (p. ej. via `insert()` directo, que sí lo evita). Si se encuentra alguna, se documenta o se refactoriza.

### 4.3 Comando de backfill

`php artisan metrics:backfill-cost-prices`:

- Recorre `sale_items WHERE cost_price_at_sale IS NULL` en chunks de 1000.
- Copia `products.cost_price` **actual** como aproximación.
- Items de productos sin `cost_price` registrado: queda `NULL`.
- **Idempotencia (definición precisa):** una segunda corrida **no sobrescribe** valores no-NULL existentes. Sí completa los huecos que quedaron `NULL` la corrida anterior si hoy esos productos ya tienen costo cargado — eso es el comportamiento deseado, no un bug.
- Loguea resumen (`X items rellenados, Y items sin costo disponible`).
- Al finalizar, registra la fecha en la tabla `settings` (ver §4.5) con clave `metrics.backfill_run_at`. Si la clave ya existe, no la sobrescribe (la primera corrida es la fecha de corte).

### 4.4 Comportamiento en reportes de margen

- Por defecto, `MarginMetrics` excluye items con `cost_price_at_sale IS NULL` de los agregados de margen (no del conteo de ventas).
- Si el rango solicitado incluye ventas con `created_at < metrics.backfill_run_at`, la UI muestra un banner discreto:
  > *"Márgenes anteriores al YYYY-MM-DD son aproximados (costo calculado con el precio al día del backfill)."*
  La fecha YYYY-MM-DD se lee de la tabla `settings`.
- Las ventas sin costo registrado aparecen en la tabla de productos con un badge "costo no registrado" en vez de "margen negativo".

### 4.5 Almacén de la fecha de backfill

Se reutiliza una tabla simple `settings` clave-valor por tenant. Si no existe en el proyecto, se crea con esta migración:

`database/migrations/2026_04_17_000003_create_settings_table.php`:

```php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('key');
    $table->text('value')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'key']);
});
```

API mínima vía un helper `Setting::get($key, $default = null, ?int $tenantId = null)` y `Setting::set($key, $value, ?int $tenantId = null)`. La fecha se guarda como `tenant_id = null` (es global por instalación, ya que el backfill corre sobre toda la BD).

> Si en `app/Models/` ya existe un mecanismo equivalente (config dinámica, tabla `tenant_settings`, etc.), se reutiliza ese y se elimina esta migración del plan.

## 5. Estructura de páginas y componentes

### 5.1 Páginas Vue

```
resources/js/Pages/Sucursal/Metricas/
├── Index.vue
├── Ventas.vue
├── Margen.vue
├── Productos.vue
├── Clientes.vue
├── Cajeros.vue
├── Turnos.vue
└── Cobranza.vue

resources/js/Pages/Empresa/Metricas/
└── (mismos archivos con selector de sucursal en el header)
```

### 5.2 Componentes compartidos

`resources/js/Components/Metrics/`:

- `DateRangePicker.vue` — presets + rango custom + toggle comparativo.
- `KpiCard.vue` — valor, label, delta % vs. periodo previo (verde/rojo/gris neutro cuando no hay comparable).
- `ChartCard.vue` — contenedor estándar (título, subtítulo, chart slot).
- `DataTable.vue` — tabla ligera, sort y paginación client-side.
- `EmptyState.vue` — estado cuando el rango no tiene datos.
- `MetricsLayout.vue` — wrapper con breadcrumbs, filtro global y refresh button.

### 5.3 Composable

`resources/js/composables/useDateRange.js`:

- Sincroniza el rango con query params (`?preset=this_month&compare=1` o `?from=...&to=...`).
- Debounce de 300ms en custom para evitar peticiones en cada tecleo.
- Exposición reactiva del rango actual y del comparativo.

### 5.4 Layout del índice

```
┌─────────────────────────────────────────────────────┐
│  [DateRangePicker + comparativo]     [↻ Actualizar] │
├─────────────────────────────────────────────────────┤
│  6 KPIs (grid 3×2):                                 │
│    Ventas totales · Ganancia bruta                  │
│    Ticket promedio · # Tickets                      │
│    Cobrado · Cancelaciones                          │
│  Cada tarjeta: valor grande, label, delta %         │
├─────────────────────────────────────────────────────┤
│  Chart: serie temporal de ingresos (periodo actual  │
│          en sólido, previo en banda gris)           │
├─────────────────────────────────────────────────────┤
│  Columna izq: heatmap hora × día de semana          │
│  Columna der: top 5 productos por margen            │
├─────────────────────────────────────────────────────┤
│  Accesos rápidos: 7 tarjetas → subpáginas           │
└─────────────────────────────────────────────────────┘
```

## 6. Detalle de métricas por eje

Todas las subpáginas siguen el patrón: **filtro global → KPIs → gráficos → tabla detallada**. Todos los valores muestran delta vs. periodo previo cuando el comparativo está activo.

### Eje 1 — Ventas (`/metricas/ventas`)

- **KPIs:** total vendido · # tickets · ticket promedio · # canceladas · monto cancelado.
- **Gráficos:** línea de ingresos diarios con banda del periodo previo · heatmap hora × día de semana · dona de métodos de pago.
- **Tabla:** ventas agrupadas por día (fecha, tickets, total, promedio, canceladas).

### Eje 2 — Margen (`/metricas/margen`)

- **KPIs:** ganancia bruta · % margen global · margen promedio por ticket · # productos sin costo.
- **Gráficos:** línea de ganancia diaria · barras de margen % por categoría.
- **Tabla:** producto, cantidad vendida, ingreso, costo total, ganancia, % margen — sortable.
- **Banner** si el rango incluye periodo pre-backfill.

### Eje 3 — Productos (`/metricas/productos`)

- **KPIs:** # productos únicos vendidos · top producto (nombre + monto) · más rentable · sin movimiento (N días configurable, default 30).
- **Gráficos:** barras horizontales top 10 por ingreso · top 10 por cantidad · dona por categoría.
- **Tablas (tabs):** Top vendidos · Menos vendidos · Sin movimiento · Precio ≤ costo (alerta pasiva).
- **"Precio ≤ costo" — definición:** compara `products.price` (precio de venta actual) con `products.cost_price` (costo actual). Esta tabla mira el catálogo presente, no la historia. Es una alerta de configuración del catálogo, no de ventas pasadas.

### Eje 4 — Clientes (`/metricas/clientes`)

- **KPIs:** clientes que compraron · nuevos · con saldo · saldo total pendiente · ticket promedio por cliente.
- **Gráficos:** barras top 10 clientes por monto · aging de saldos (0–30, 31–60, 60+).
- **Tablas (tabs):** Top · Con saldo · Nuevos · Inactivos (selector 30/60/90 días).

### Eje 5 — Cajeros (`/metricas/cajeros`)

- **KPIs:** cajeros activos · cajero top · ratio promedio de cancelación · descuentos totales aplicados (Σ `original_unit_price − unit_price` × quantity).
- **Gráficos:** barras de ventas por cajero · barras de % cancelación por cajero.
- **Tabla:** cajero, tickets, total, ticket promedio, # cancelaciones, % cancelación, descuentos aplicados.

### Eje 6 — Caja / Turnos (`/metricas/turnos`)

- **KPIs:** turnos cerrados · diferencia total (sobrante/faltante) · retiros totales · turno con mayor diferencia.
- **Gráficos:** línea de diferencia diaria · barras comparativas por método (esperado vs. declarado).
- **Tabla:** turno, fecha, cajero, apertura, esperado, declarado, diferencia, retiros — con link al detalle del turno en el módulo existente.

### Eje 7 — Cobranza (`/metricas/cobranza`)

- **KPIs:** total cobrado · # pagos recibidos · saldo pendiente global · días promedio de cobro.
- **Gráficos:** línea de cobranza diaria · aging de cuentas por cobrar.
- **Tabla:** cliente, saldo, última compra, último pago, antigüedad, # ventas a crédito pendientes.

## 7. Performance y caché

### 7.1 Índices de BD

Migración complementaria `2026_04_17_000002_add_metrics_indexes.php`:

```php
// sale_items
Schema::table('sale_items', function (Blueprint $table) {
    $table->index(['sale_id', 'product_id']);
    $table->index('product_id');
});

// sales — el plan de implementación verificará primero qué índices ya existen y
// solo añadirá los faltantes
Schema::table('sales', function (Blueprint $table) {
    $table->index(['branch_id', 'status', 'completed_at']);
    $table->index(['branch_id', 'customer_id', 'completed_at']);
});

// payments NO tiene branch_id (verificado contra create_payments_table).
// El filtro por sucursal se hace vía JOIN a sales. Solo se indexa lo necesario:
Schema::table('payments', function (Blueprint $table) {
    $table->index(['sale_id', 'created_at']);
});

// customer_payments SÍ tiene branch_id — este es el índice relevante para Cobranza:
Schema::table('customer_payments', function (Blueprint $table) {
    $table->index(['branch_id', 'created_at']);
});
```

**Estrategia de queries de cobranza:** el eje 7 (Cobranza) usa principalmente `customer_payments` (que sí tiene `branch_id`). Los pagos en `payments` se asocian a sucursal via `JOIN sales ON payments.sale_id = sales.id` cuando se necesita ese cruce.

### 7.2 Queries

Agregados siempre en BD con `DB::raw` + `groupBy`. Para margen, un JOIN `sale_items → sales` agregado por día/producto/categoría según el eje.

### 7.3 Caché

- `Cache::remember("metrics:{$tenantId}:{$branchIdOrAll}:{$axis}:{$rangeHash}", 300, fn () => …)`.
- TTL = 5 min. Redis ya disponible en Sail; el proyecto por defecto usa el store `database` (`CACHE_STORE=database`).
- Botón "Actualizar" envía `?refresh=1` → `Cache::forget` de **la key de la página actual** (axis + rangeHash). Re-computa y devuelve.
- **Diseño explícito:** NO se usa `Cache::tags()` porque el store por defecto (`database`) no lo soporta y lanzaría `BadMethodCallException` en producción. Por tanto, al apretar "Actualizar" en la página de Ventas, solo Ventas se recomputa. Si el usuario navega a Margen segundos después, verá el valor cacheado hasta que expire el TTL o presione "Actualizar" ahí. Esto es aceptable dado el TTL corto y la semántica de "refresh" asociada al click, no cross-page.
- No se invalida en writes (el TTL corto lo hace innecesario y simplifica).
- **Nota futura:** si se migra a Redis como store por defecto, se puede añadir soporte de tags sin cambios en servicios, solo en los controllers, usando `Cache::store('redis')->tags(...)->flush()` en el endpoint de refresh.

### 7.4 Límites

- Rango máximo: 365 días. Si se excede, el controller capa a 365 y devuelve un aviso.
- Tablas con > 500 filas: paginación client-side con ventanas de 50.

## 8. UI / Diseño

- Estándar premium con `frontend-design` durante implementación.
- Tokens: spacing, radios y colores consistentes con el resto del panel Sucursal existente.
- Estados: empty, loading (skeleton), error, sin-datos-en-rango — todos diseñados, no placeholders genéricos.
- Microinteracciones: hover en KPIs, transición de rango (fade entre valores), tooltips de charts con formato de moneda localizado.
- Accesibilidad: focus visible, contraste WCAG AA, labels asociados en el DateRangePicker, roles ARIA en tabs.
- Responsive: el admin usa el módulo desde desktop mayoritariamente, pero el índice debe quedar presentable en tablet (iPad). Móvil no es prioridad v1.

## 9. Plan de testing

### 9.1 Unit

- `tests/Unit/Services/Metrics/DateRangeTest.php`
  - Cada preset resuelve al rango correcto.
  - `previousComparable()` calcula el rango previo con el mismo tamaño y el ajuste de "mes en curso" (día 1 al día actual del mes previo).
  - `hash()` es estable para el mismo rango y distinto entre rangos diferentes.

### 9.2 Feature (por eje)

Una clase de test por `*Metrics`:

- Agregados correctos con datos seed-eados.
- Solo considera ventas con `status = Completed`.
- Filtra por `branch_id` correcto.
- `MarginMetrics` excluye items con `cost_price_at_sale IS NULL`.
- Delta % calcula bien y maneja división por cero (devuelve `null`, no crash).
- Tenant scope respetado (dos tenants con datos cruzados — no se filtran entre sí).

### 9.3 Feature (controllers)

- `tests/Feature/Http/Sucursal/MetricsControllerTest.php`
  - `admin-sucursal` ve solo su sucursal (intento de `?branch_id=otra` es ignorado).
  - `cajero` → 403.
  - Sin auth → redirige a login.
  - Query params inválidos → default a "hoy" sin error.
- `tests/Feature/Http/Empresa/MetricsControllerTest.php`
  - `admin-empresa` con `?branch_id=X` filtra correctamente.
  - Sin selector → consolidado del tenant.
  - Intento de `?branch_id=<otro tenant>` → **403 Forbidden** (alineado con §3.5).

### 9.4 Command

- `tests/Feature/Commands/BackfillCostPricesTest.php`
  - Rellena solo items con `cost_price_at_sale IS NULL`.
  - No modifica items ya poblados.
  - Idempotente: dos corridas = mismo estado final.
  - Items de productos sin `cost_price` quedan `NULL`.

### 9.5 Casos extra a cubrir explícitamente

- **Venta a través de medianoche:** una venta cuyo turno abrió a las 23:50 y se completa a las 00:10 debe atribuirse al día de `completed_at`, no al día de apertura del turno. Test que crea exactamente este caso y verifica el `groupBy` diario.
- **Zona horaria:** los `groupBy` por día usan `DATE(completed_at AT TIME ZONE :tz)` con la zona horaria de `config('app.timezone')`. Test que verifica que una venta del día X a las 23:30 hora local NO aparece en el día siguiente cuando la BD está en UTC.

### 9.6 No cubierto por tests

- La renderización de ApexCharts (librería externa, sin valor testear).
- Redis como infraestructura.
- **Test de `?refresh=1`:** se ejecuta usando el cache driver `array` (default en `phpunit.xml` para tests). Se verifica que:
  1. Una primera petición popula el caché (mock del servicio devuelve valor A; segunda petición sin `refresh` devuelve A sin volver a invocar el servicio — verificado con `Mockery::times(1)`).
  2. Una petición con `?refresh=1` invoca el servicio nuevamente (mock devuelve valor B; respuesta contiene B).

## 10. Migraciones y pasos de deploy

1. Correr `php artisan migrate` → aplica las migraciones nuevas: `add_cost_price_at_sale_to_sale_items`, `add_metrics_indexes`, y `create_settings_table` (si aplica).
2. Deploy del código (el evento `SaleItem::creating` ya cubre las ventas nuevas automáticamente — no hay cambios manuales en controllers).
3. Correr `php artisan metrics:backfill-cost-prices` una vez. El propio comando registra la fecha en `settings` con clave `metrics.backfill_run_at` para que el banner UI la lea.
4. `npm install apexcharts vue3-apexcharts` + `npm run build`.
5. **Auditoría post-deploy:** ejecutar `grep -r "SaleItem::insert\|DB::table('sale_items')->insert" app/` para confirmar que ningún flujo inserta items bypaseando el evento `creating`. Si aparece alguno, evaluar caso por caso.

## 11. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Queries pesadas con rangos anuales | Índices BD + caché TTL 5 min + tope de 365 días |
| Margen histórico inexacto pre-backfill | Banner explícito + documentación + exclusión opcional |
| Comparativo "mes en curso" engañoso | Ajuste explícito (mismo número de días transcurridos del mes previo) |
| ApexCharts suma ~150KB al bundle | Import dinámico por página de métricas (code splitting con Vite) |
| Admin-empresa con muchas sucursales = queries multiplicadas | Consolidado se hace con un solo query agregado por eje, no N queries |

## 12. Fuera de alcance de este spec

- Módulo de metas/KPIs con thresholds configurables.
- Envío programado de reportes por email.
- Alertas push cuando un indicador cruza un umbral.
- Comparativos custom (p.ej. "este mes vs. mismo mes del año pasado").
- Vista superadmin (global del tenant o del sistema).

## 13. Criterios de aceptación

- [ ] El admin-sucursal accede a `/metricas` y ve el índice con los 6 KPIs principales cargados en < 2s con dataset seed de demo (~30 días, ~1000 ventas). Esto es objetivo de implementación, no gate de CI.
- [ ] Cambiar entre los 7 presets de rango actualiza todos los valores y gráficos sin recargar la página (Inertia preserve state).
- [ ] El toggle "comparar con periodo anterior" añade el delta % en cada KPI y la banda overlay en la serie temporal.
- [ ] Cada subpágina (7 ejes) carga, muestra sus KPIs/gráficos/tabla y respeta el rango global.
- [ ] El admin-empresa con una sucursal seleccionada ve los mismos números que ve el admin-sucursal de esa sucursal.
- [ ] El admin-empresa en modo "consolidado" ve la suma correcta de todas sus sucursales (verificable con queries manuales en la BD).
- [ ] El cajero recibe 403 al intentar acceder.
- [ ] Tras correr el backfill, ningún SaleItem pre-existente queda con `cost_price_at_sale = NULL` salvo los de productos sin costo registrado.
- [ ] La suite `php artisan test` pasa al 100%.
- [ ] Lint (`./vendor/bin/pint`) sin warnings.
