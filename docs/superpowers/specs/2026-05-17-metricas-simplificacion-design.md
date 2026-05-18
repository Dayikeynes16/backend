# Métricas — Simplificación: Resumen como hub + header reducido + sin comparativas

**Fecha:** 2026-05-17
**Estado:** aprobado para implementar
**Alcance:** UX y composables del módulo Métricas (Sucursal y Empresa). Cambios al coordinador `MetricsService` y a los controladores `SucursalMetricasController` y `EmpresaMetricasController`. Cero cambios a los servicios de cálculo por eje (`SalesMetrics`, `MarginMetrics`, `CustomerMetrics`, `CollectionMetrics`, `ProductMetrics`, `CashierMetrics`, `ShiftMetrics`, `CancellationMetrics`).

## Motivación

La página Resumen (`Sucursal/Metricas/Index.vue` y su gemela en Empresa) muestra hoy 10 KPI cards (4 de ventas, 3 de cobranza, 3 de ganancia), una `TimeSeriesCard` con la tendencia comparada vs. periodo previo, un heatmap de ventas hora-día, top productos por ganancia y un grid de 8 ejes para "Explorar". Todo encima del mismo header que ofrece 7 presets de fecha, toggle de Comparar y botón Actualizar.

Tres problemas:

1. **Redundancia con el Dashboard**: Ventas netas, # Tickets, Ticket promedio y Cancelaciones ya viven en el Dashboard de Sucursal.
2. **Mezcla de niveles**: Resumen incluye Cobranza (que tiene su propio eje), Ganancia (que tiene Margen) y Cancelaciones (que tiene Cancelaciones). Los detalles se duplican.
3. **Densidad y fricción**: el header acumula presets que rara vez se usan, un toggle Comparar que activa una capa visual extra (deltas ±%, línea fantasma, subtítulos "vs. ...") y un botón Actualizar que la mayoría de operadores no necesita.

La intención es convertir Resumen en un **hub de navegación puro** y aplicar un header más austero a todas las páginas de Métricas. Los detalles operativos viven en cada eje.

## Decisiones tomadas

- **Resumen = grid de ejes**. Sin KPIs, sin gráficos, sin BackfillBanner. Los filtros del header se mantienen y propagan vía URL al hacer click en un eje.
- **Header global reducido**. Presets fijos: `[Hoy] [Ayer] [7 días] [📅 Calendario]`. Se eliminan `Comparar`, `Actualizar`, los presets `Este mes`, `Mes pasado`, `Este año` y `Personalizado` (el calendario absorbe "personalizado").
- **Sin comparativas en ningún eje**. Se quitan deltas ±%, líneas/series de periodo previo y subtítulos "vs. periodo previo".
- **Subheader recortado**. Queda `Mostrando: 17 may 2026 · Hoy`. Desaparece `| vs. 16 may 2026 (periodo previo)`.
- **Status chip se mantiene** (Completadas/Pendientes) donde ya aplica: Resumen, Ventas, Productos, Clientes.
- **Sidebar sin cambios estructurales**. `MetricsSubSidebar.vue` sigue listando Módulo (Resumen) + Ejes con los mismos 8 ítems.

## Arquitectura

### Rutas

Sin cambios. Tanto `sucursal.metricas.index` como `empresa.metricas.index` siguen siendo Resumen; las rutas de los 8 ejes idem.

### Backend

**`MetricsService` (coordinador)**

- Deja de calcular y exponer las claves `previous`, `previous_daily_series` y derivados (`compare`-driven).
- La firma pública del método principal pierde el parámetro `bool $compare` (o se mantiene aceptando el booleano por compatibilidad pero ignorándolo internamente — preferir lo primero por limpieza).
- Los servicios por eje (`SalesMetrics`, `MarginMetrics`, etc.) que hoy aceptan un `DateRange $previous` opcional pierden ese parámetro. Las queries de `previous` desaparecen.

**`SucursalMetricasController::index` y `EmpresaMetricasController::index`**

- Dejan de leer `request->compare` y `request->refresh`.
- Inertia ya no inyecta `compare` ni `previous_*` en `props`.
- El payload `data` pierde las ramas `sales.previous`, `margin.previous`, etc.
- El resto de acciones (`ventas`, `margen`, `productos`, `clientes`, `cobranza`, `cajeros`, `turnos`, `cancelaciones`) hacen el mismo recorte.

**Tests**

- Eliminar/ajustar aserciones sobre `previous`, `previous_daily_series`, deltas y `compare=true` en los tests existentes de Metrics.
- Añadir un test feature de `SucursalMetricasController::index` que verifique que la respuesta NO incluye `compare` ni `previous_*` y que los presets aceptados son únicamente `today`, `yesterday`, `7d`, `custom`.

### Frontend

**`resources/js/Pages/Sucursal/Metricas/Index.vue` y `Pages/Empresa/Metricas/Index.vue`**

Quedan minimalistas: `<Head>`, `MetricsLayout`, `MetricsHeader`, y `MetricsHubGrid` (nuevo componente). No reciben `compare`, `backfill_run_at` ni `data` pesado. Solo `tenant` y los props de filtros que ya provee `useMetricsFilters`.

**`resources/js/Components/Metrics/Content/IndexContent.vue`**

Se renombra y reduce a `MetricsHubGrid.vue` (ubicación `Components/Metrics/MetricsHubGrid.vue`). Su responsabilidad única: renderizar el grid 4×2 de los 8 ejes.

- Props: `scope: 'sucursal' | 'empresa'`.
- Lee `slug` de `usePage().props.tenant`.
- Define internamente la lista `subpages` (idéntica a la actual: `ventas`, `margen`, `productos`, `clientes`, `cajeros`, `turnos`, `cobranza`, `cancelaciones`) con `key`, `label`, `hint`, `icon`.
- Renderiza cada card como `<Link>` a `route('{scope}.metricas.{key}', slug)` preservando los query params actuales (los filtros se llevan al eje). Tailwind ya usado en el grid actual basta como base.

Se elimina del componente: bloques `Ventas generadas`, `Cobranza`, `Ganancia`, `TimeSeriesCard`, heatmap (`ChartCard` + apexchart heatmap), top productos por margen, y el grid "Explorar por eje" como sección secundaria — el grid principal es ahora la única sección.

**`resources/js/Components/Metrics/MetricsHeader.vue`**

- Eliminar el bloque `<label v-if="showCompare">` con el checkbox Comparar.
- Eliminar el `<button @click="filters.refresh()">` Actualizar.
- Eliminar la rama `<span v-if="compareEnabled && previousRangeLabel">` del subheader (queda solo el "Mostrando: ...").
- Eliminar las props `showCompare` y los cálculos `compareEnabled`, `previousRangeLabel`.
- Sin cambios en `showStatusChip` ni en `showBranchSelector`.

**`resources/js/Components/Metrics/DateRangeFilter.vue`**

- Lista de presets visibles reducida a `today`, `yesterday`, `7d`, `custom` (el calendario).
- Los presets `this_month`, `last_month`, `this_year` quedan removidos del UI; si el backend los recibe (por URL antigua) se aceptan en silencio pero no se ofrecen en el chip strip.

**`resources/js/composables/useMetricsFilters.js`**

- Eliminar `compare`, `setCompare`, `refresh`, `setRefresh`.
- Lista interna de presets válidos: `['today', 'yesterday', '7d', 'custom']`.
- El query param `compare` y `refresh` ya no se serializan en URL.

**`resources/js/Components/Metrics/KpiCard.vue`**

- Mantener la prop `delta` declarada para no romper callsites de inmediato, pero el componente deja de renderizar el chip de delta. Idealmente, una vez quitados todos los callsites, también se borra la prop.

**`resources/js/Components/Metrics/TimeSeriesCard.vue`**

- Eliminar prop `previous` y prop `compare`.
- Render: una sola serie (`current`) con su color. Sin línea fantasma.

**`Components/Metrics/Content/*.vue` (Ventas, Margen, Productos, Clientes, etc.)**

- Cada uno deja de leer `props.compare`, `data.*.previous` y `data.previous_daily_series`.
- Las llamadas `<KpiCard :delta="deltaIf(...)" />` se simplifican a `<KpiCard />` sin delta.
- Subtítulos de gráficos pasan de `... vs. ${previousRangeText}` a `... · ${currentRangeText}`.

### Lo que NO se toca

- **Sidebar** (`MetricsSubSidebar.vue`): misma estructura, mismos links.
- **Servicios de cálculo por eje** (`SalesMetrics`, `MarginMetrics`, `CustomerMetrics`, `CollectionMetrics`, `ProductMetrics`, `CashierMetrics`, `ShiftMetrics`, `CancellationMetrics`): solo pierden el parámetro `previous` del método público.
- **Cada eje individual mantiene sus KPIs y gráficos** — solo se les quita la capa de comparativa.
- **`BackfillBanner`** sigue apareciendo en cada eje (donde sí hay números que contextualizar). Solo se quita del Resumen.
- **`StatusFilterChips`** se mantiene como hoy.
- **Migraciones y modelos**: sin cambios.

## Comportamiento de filtros

- `useMetricsFilters` continúa siendo la fuente única de verdad para `date`, `preset`, `from`, `to`, `status`, `branchId` (en Empresa).
- Al hacer click en un card del hub, el `<Link>` lleva los query params actuales en la URL del eje destino. Si el usuario ya tenía `?preset=7d&status=completed,pending` en Resumen, entra a Ventas con los mismos filtros aplicados.
- Si el usuario ingresa directamente a un eje (deep-link), los filtros viven en ese eje y persisten en URL como hoy.

## Estados visuales

- **Mobile**: el grid 4×2 colapsa a `grid-cols-2` (4 filas × 2 columnas). En pantallas medianas (`sm`/`md`) puede mostrar 2 columnas; `lg` en adelante, 4 columnas.
- **Hover**: cards conservan la animación actual (`hover:-translate-y-0.5 hover:border-red-300 hover:shadow-md`).
- **Sin datos**: no aplica (el hub no muestra datos).

## Impacto y riesgo

- **Archivos a tocar**:
  - 2 páginas Vue (`Sucursal/Metricas/Index.vue`, `Empresa/Metricas/Index.vue`).
  - 1 componente nuevo (`Components/Metrics/MetricsHubGrid.vue`).
  - 1 componente eliminado (`Components/Metrics/Content/IndexContent.vue`).
  - 9 componentes editados (`MetricsHeader.vue`, `DateRangeFilter.vue`, `KpiCard.vue`, `TimeSeriesCard.vue`, `Content/VentasContent.vue`, `Content/MargenContent.vue`, `Content/ProductosContent.vue`, `Content/ClientesContent.vue`, y donde sea que se use `:compare`).
  - 1 composable editado (`composables/useMetricsFilters.js`).
  - 2 controladores editados (`SucursalMetricasController`, `EmpresaMetricasController`).
  - 1 servicio coordinador editado (`MetricsService`).
  - 8 servicios por eje (parámetros `previous` removidos).
  - Tests de Metrics actualizados; un test feature nuevo verificando ausencia de `compare`/`previous_*`.

- **Riesgo bajo**:
  - URLs antiguas con `?compare=1` o `?preset=this_month` se ignoran silenciosamente.
  - Ningún cambio de datos en producción.
  - El sidebar y las rutas se conservan, así que bookmarks y muscle memory no se rompen.

- **Migración no requerida**: no hay esquema afectado.

## Métricas de éxito

- Resumen pasa de ~270 líneas de Vue y 10+ KPI cards a ~50 líneas y 8 cards de navegación.
- `MetricsHeader` pierde ~30 líneas (toggle Comparar + botón Actualizar + subheader comparativo).
- Payload Inertia del Resumen pasa de ~15 claves anidadas (`data.sales.current/previous`, `data.margin.*`, `data.collection`, `data.daily_series`, etc.) a ~0 (solo `tenant` y filtros desde la URL).
- Tiempo de respuesta del controlador Resumen mejora (deja de calcular previous_* y todos los agregados de Ganancia/Cobranza/Heatmap/Top productos).
