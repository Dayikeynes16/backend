# Métricas: pantalla «Resumen» con Utilidad real, comparativas y alertas

**Fecha:** 2026-06-18
**Estado:** Aprobado — pendiente de plan de implementación
**Alcance:** Fase 1 de la mejora de Métricas (UX + métricas que faltan).

## Problema

El módulo de Métricas tiene 8 ejes bien hechos (Ventas, Margen, Productos, Clientes,
Cajeros, Turnos, Cobranza, Cancelaciones) pero **desconectados**: el landing
(`Metricas/Index.vue`) es solo una grilla de botones (`MetricsHubGrid`), no informa
nada de un vistazo. Falta lo más importante para un dueño:

1. **No hay un número de Utilidad** ("¿gané dinero?"). Ventas, costo, gastos viven separados.
2. **Sin comparativas**: los KPIs muestran solo el valor del período, no vs el anterior.
3. **Sin señales accionables** (alertas) ni comparación entre sucursales de un vistazo.

## Decisiones de diseño (acordadas con el usuario)

1. **Nueva pantalla «Resumen»** reemplaza la grilla de botones como landing de Métricas.
   Los 8 ejes siguen existiendo y se navegan desde el shell existente
   (`MetricsHeader` + `MetricsSubSidebar`/breadcrumb). El `MetricsHubGrid` deja de ser
   el contenido principal del index.

2. **Utilidad real basada en CMV (costo de la mercancía *vendida*), no en compras.**
   La app ya captura costo por línea (`sale_items.cost_price_at_sale`) y `MarginMetrics`
   ya lo agrega. El P&L del Resumen es:
   - `Ventas netas` (SalesMetrics, ventas `completed`)
   - `− Costo de lo vendido (CMV)` = `MarginMetrics` cost
   - `= Utilidad bruta` (gross_profit) + `margin_pct`
   - `− Gastos operativos` (suma de `expenses.amount` del período)
   - `= Utilidad neta (operativa)`

   *Nota de denominadores:* `SalesMetrics` cuenta ventas `completed` sin importar
   `amount_pending`, mientras que el CMV de `MarginMetrics` solo considera ventas
   `completed` y pagadas (`amount_pending <= 0`). Los universos difieren por diseño; la
   cobertura (decisión 3) lo refleja. El planner debe ser consciente de esto al armar el
   waterfall (la "Utilidad bruta" se calcula sobre el universo de `MarginMetrics`).

3. **Cobertura honesta.** El CMV/utilidad se calcula **solo sobre ventas con costo
   capturado**. El Resumen muestra el % de cobertura (ventas con costo / total) y un
   aviso cuando es < 100% (mismo criterio que el `MarginCoverageBanner` actual). No se
   estiman costos faltantes. La cobertura se mide por **conteo de items** (items con
   costo / total de items vendidos), tal como ya lo reporta `MarginMetrics`
   (`items_with_cost` / `items_without_cost`); el badge comunica esa cobertura por item,
   no el % del valor vendido.

4. **Compras = dato de caja aparte.** Se muestra `Compras del período` (suma de
   `purchases.total`) como egreso informativo de flujo de caja, **separado** de la
   utilidad (que usa CMV real). Evita el doble conteo.

5. **Comparativas vs período anterior.** Los KPIs hero (Utilidad neta, Ventas, Margen,
   Gastos) muestran el delta vs el período inmediatamente anterior de igual longitud.
   Para gastos, ▲ es negativo (color invertido).

6. **Alertas derivadas de datos existentes (sin "metas").** No se crean metas/objetivos
   en esta fase (queda como fase futura). Las alertas salen de señales disponibles:
   cobranza vencida, margen de producto bajo umbral, gasto por categoría con alza
   inusual vs período anterior, y sucursal por debajo de su período anterior.

7. **Empresa vs Sucursal.** Empresa: selector de sucursal (todas/una) + sección
   "Comparativa por sucursal". Sucursal: scope fijo a su `branch_id`, sin esa sección.

## Backend

### Servicio agregador
- Nuevo `App\Services\Metrics\OverviewMetrics` que **compone** servicios existentes
  (no reimplementa cálculos):
  - `profitAndLoss(range, branchId, tenantId)` → ventas, CMV, utilidad bruta, margin_pct,
    gastos, utilidad neta, cobertura (items_with_cost / total). Reusa
    `SalesMetrics::summary` + `MarginMetrics::aggregateFor` + suma de `expenses`.
  - `purchasesTotal(range, branchId, tenantId)` → suma `purchases.total` filtrando por
    `purchased_at` en el rango, **excluyendo compras canceladas** (`status` cancelado) y
    soft-deleted. Es solo dato de caja.
  - `heroKpis(range, branchId, tenantId)` → valor actual + valor período anterior + delta %
    para Utilidad neta, Ventas, Margen, Gastos (usa `DateRange::previousComparable()`).
  - `topProductsByProfit(...)` → reusa `MarginMetrics::byProduct` (ordenado por gross_profit).
  - `branchComparison(range, tenantId)` → ventas por sucursal (solo empresa).
  - `alerts(range, branchId, tenantId)` → ver abajo.
- Caché igual que el resto: `Cache::remember($meta->cacheKey('resumen:...'), 300, ...)`.

### Período anterior (comparativas)
- **Reusar el método existente `DateRange::previousComparable()`** (ya devuelve el rango
  inmediatamente anterior de igual longitud). No crear un método nuevo.

### Gastos del período
- Suma de `expenses.amount` con `expense_at` en el rango, scopeada a tenant (+ branch si
  aplica), **excluyendo gastos cancelados** (`cancelled_by` NULL). Respeta
  `TenantScope`/soft-deletes del modelo `Expense`.

### Alertas (umbrales en código, fase 1)
- **Cobranza vencida** > 0 → reusa `CollectionMetrics::aging()` (buckets vencidos, p. ej.
  31-60 / 61+) para el monto vencido y `receivablesTable()` para el # de clientes. NO usar
  `summary().total_pending_balance` (es saldo pendiente total, no vencido).
- **Margen de producto bajo**: productos de `MarginMetrics::byProduct` con `margin_pct`
  por debajo de un umbral (p. ej. < 15%) y venta relevante en el período.
- **Gasto inusual**: categoría de gasto cuyo total del período supera al del período
  anterior por encima de un umbral (p. ej. +30%).
- **Sucursal a la baja** (solo empresa): sucursal con ventas del período < período
  anterior por encima de un umbral (p. ej. −15%).
- Cada alerta: `{ type, severity (red|amber|blue), title, detail }`. Lista acotada (top N).

### Controladores
- `Empresa\Metrics\MetricsIndexController` y `Sucursal\Metrics\MetricsIndexController`
  pasan a renderizar `Metricas/Resumen` con el payload de `OverviewMetrics`
  (empresa: `branchId` resuelto del selector; sucursal: `branch_id` del usuario).
- Reutilizan `ResolvesMetricsRequest` (rango, branch, statuses, commonProps).

## Frontend

- Nueva página `resources/js/Pages/{Empresa,Sucursal}/Metricas/Resumen.vue`, envuelta en
  su layout, usando el `MetricsHeader` (filtros de fecha + selector de sucursal en empresa)
  y dejando los 8 ejes accesibles por el sub-sidebar/breadcrumb existente.
- Nuevo componente de contenido `Components/Metrics/Content/ResumenContent.vue` (compartido
  empresa/sucursal vía prop `scope`), con:
  - Fila de **KPIs hero** con comparación vs período anterior (reusa/extiende `KpiCard`
    para soportar delta ▲▼ y tono invertido en Gastos).
  - Tarjeta **«¿Gané dinero?»**: waterfall Ventas → −CMV → =Utilidad bruta → −Gastos →
    =Utilidad neta (ApexChart o barras), con **badge de cobertura** y chip de
    **Compras del período** (caja).
  - Tarjeta **Alertas** (lista con severidad por color; cada item enlaza a su eje cuando aplique).
  - **Productos que más aportan** (tabla, reusa `DataTable`) y, en empresa,
    **Comparativa por sucursal** (barras, cada sucursal enlaza a su detalle filtrado).
- `Metricas/Index.vue` deja de mostrar `MetricsHubGrid` como contenido principal; el hub
  grid se conserva como navegación secundaria si hace falta, o se reemplaza por el
  sub-sidebar.

## No incluido (fases futuras)

- **Metas/objetivos configurables** y alertas basadas en metas.
- Eje dedicado «Utilidad / P&L» con desglose profundo (esta fase entrega el resumen).
- Estimación de costos faltantes (se mantiene cobertura honesta).

## Pruebas (PHPUnit, feature + unit)

- `OverviewMetrics::profitAndLoss`: utilidad neta = ventas − CMV − gastos; margin_pct
  correcto; cobertura reportada (caso con items sin costo).
- `DateRange::previousComparable()`: ya cubierto por tests existentes; agregar caso solo
  si el resumen usa un preset no cubierto.
- `heroKpis`: delta % correcto vs período anterior (incl. división por cero).
- Compras del período NO afectan la utilidad (caja aparte).
- `alerts`: dispara cada tipo bajo su condición; vacío cuando no aplica.
- Empresa: `branchComparison` presente; sucursal: ausente y scope a su branch.
- Aislamiento cross-tenant.

## Documentación

Actualizar `docs/modulos/` (o crear doc de métricas) con el modelo de Utilidad (CMV,
cobertura), las comparativas y las alertas.
