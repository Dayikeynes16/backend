# Métricas — Simplificación: Resumen como hub + header reducido + sin comparativas

**Fecha:** 2026-05-17
**Estado:** aprobado para implementar
**Alcance:** UX y composables del módulo Métricas (Sucursal y Empresa). Cambios al coordinador `MetricsService`, al trait `ResolvesMetricsRequest` y a los 18 controladores `__invoke` bajo `app/Http/Controllers/{Sucursal,Empresa}/Metrics/`. Los servicios de cálculo por eje (`SalesMetrics`, `MarginMetrics`, `CustomerMetrics`, `CollectionMetrics`, `ProductMetrics`, `CashierMetrics`, `ShiftMetrics`, `CancellationMetrics`) sólo pierden la rama `'previous' => …` de sus métodos `summary()`; sus firmas no cambian.

## Motivación

La página Resumen (`Sucursal/Metricas/Index.vue` y su gemela en Empresa) muestra hoy 10 KPI cards (4 de ventas, 3 de cobranza, 3 de ganancia), una `TimeSeriesCard` con la tendencia comparada vs. periodo previo, un heatmap de ventas hora-día, top productos por ganancia y un grid de 8 ejes para "Explorar". Todo encima del mismo header que ofrece 7 presets de fecha, toggle de Comparar y botón Actualizar.

Tres problemas:

1. **Redundancia con el Dashboard**: Ventas netas, # Tickets, Ticket promedio y Cancelaciones ya viven en el Dashboard de Sucursal.
2. **Mezcla de niveles**: Resumen incluye Cobranza (que tiene su propio eje), Ganancia (que tiene Margen) y Cancelaciones (que tiene Cancelaciones). Los detalles se duplican.
3. **Densidad y fricción**: el header acumula presets que rara vez se usan, un toggle Comparar que activa una capa visual extra (deltas ±%, línea fantasma, subtítulos "vs. ...") y un botón Actualizar que la mayoría de operadores no necesita.

La intención es convertir Resumen en un **hub de navegación puro** y aplicar un header más austero a todas las páginas de Métricas. Los detalles operativos viven en cada eje.

## Decisiones tomadas

- **Resumen = grid de ejes**. Sin KPIs, sin gráficos, sin BackfillBanner. Los filtros del header se mantienen y propagan vía URL al hacer click en un eje.
- **Header global reducido**. Presets fijos: `[Hoy] [Ayer] [7 días] [📅 Calendario]`. Se eliminan `Comparar`, `Actualizar`, los presets `this_month`, `last_month`, `this_year`. La opción Calendario corresponde al preset `__custom__` (ya existente).
- **Sin comparativas en ningún eje**. Se quitan deltas ±%, líneas/series de periodo previo y subtítulos "vs. periodo previo".
- **Subheader recortado**. Queda `Mostrando: 17 may 2026 · Hoy`. Desaparece `| vs. 16 may 2026 (periodo previo)`.
- **Status chip se mantiene** (Completadas/Pendientes) donde ya aplica: Resumen, Ventas, Productos, Clientes.
- **Sidebar sin cambios estructurales**. `MetricsSubSidebar.vue` sigue listando Módulo (Resumen) + Ejes con los mismos 8 ítems.

## Convenciones del codebase a respetar

- Los presets de fecha se nombran **`today`, `yesterday`, `last_7_days`** (no `7d`) y la opción libre es **`__custom__`** (no `custom`). Definidos en `DateRange::PRESETS` (`app/Services/Metrics/DateRange.php:10`), en `useMetricsFilters.js` y en `DateRangeFilter.vue`. La constante `DateRange::PRESETS` debe quedar como `['today', 'yesterday', 'last_7_days']` (el `__custom__` no está hoy en la constante; se valida aparte).
- Cada eje tiene su **controlador `__invoke` propio** en `app/Http/Controllers/{Sucursal,Empresa}/Metrics/{Sales,Margin,Product,Customer,Collection,Cashier,Shift,Cancellation,MetricsIndex}Controller.php`. La lógica compartida vive en el trait `ResolvesMetricsRequest`.
- `ResolvesMetricsRequest::commonProps()` hoy expone `range`, `presets`, `compare`, `refresh`, `selected_branch_id`, `statuses`, `tenant`. Las claves `compare` y `refresh` se retiran (toda la app las consume desde aquí).

## Arquitectura

### Rutas

Sin cambios. Tanto `sucursal.metricas.index` como `empresa.metricas.index` siguen siendo Resumen; las rutas de los 8 ejes idem.

### Backend

**`MetricsService` (`app/Services/Metrics/MetricsService.php`)**

- Eliminar el método `dashboardSummary()` por completo. Es el único consumidor de la rama `previous_daily_series` y vivía solo para alimentar el Resumen viejo. Los demás métodos del servicio (si existen) se conservan.
- Verificar consumidores: hoy `dashboardSummary` se invoca únicamente desde `Sucursal\Metrics\MetricsIndexController:23` y `Empresa\Metrics\MetricsIndexController:23`. Ambos cambian (ver abajo).
- Auditar el constructor de `MetricsService`: si alguna dependencia inyectada (p.ej. `CollectionMetrics`, `MarginMetrics`) deja de usarse tras la eliminación de `dashboardSummary`, retirarla para evitar inyecciones muertas.

**`MetricsIndexController` (Sucursal y Empresa)**

- Reescritura mínima: deja de invocar `dashboardSummary` y de leer `wantsRefresh`. Pasa solo `commonProps()` + `backfill_run_at` a Inertia.
- Render objetivo:
  ```php
  return Inertia::render('Sucursal/Metricas/Index', [
      ...$this->commonProps($request, $range, $branchId),
      'backfill_run_at' => null, // BackfillBanner ya no se monta aquí
  ]);
  ```
- El prop `backfill_run_at` puede directamente dejar de enviarse desde Index si la vista ya no usa `BackfillBanner`.
- **Empresa**: conservar también la clave `'branches' => $this->branchOptions($tenantId)` (alimenta el selector de sucursal en el header). Solo Sucursal puede omitirla.

**`SalesMetricsController` (Sucursal y Empresa)**

- Eliminar la clave `'previous_daily_series' => …` del payload (línea 34 de cada controlador). El resto (`daily_series`, `heatmap`) se conserva — sigue siendo el contenido propio de la página Ventas.

**Servicios por eje (`SalesMetrics`, `MarginMetrics`, `CustomerMetrics`, `CollectionMetrics`, `ProductMetrics`, `CashierMetrics`, `ShiftMetrics`, `CancellationMetrics`)**

- En cada `summary()` (y métodos análogos), eliminar la rama `'previous' => $this->summaryFor($range->previousComparable(), …)` (o equivalente). Las firmas públicas **no cambian**: hoy ningún método acepta un `$previous` parámetro — el periodo previo se computa internamente con `$range->previousComparable()` y se devuelve anidado.
- Si un método (`summaryFor`, `aggregateFor`, etc.) quedara sin más consumidores que la rama eliminada, marcarlo para borrar en el plan.

**`ResolvesMetricsRequest` (trait)**

- Eliminar los métodos `compareEnabled()` y `wantsRefresh()`.
- En `commonProps()`, retirar las claves `'compare'` y `'refresh'`. La función queda devolviendo: `range`, `presets`, `selected_branch_id`, `statuses`, `tenant`.
- Auditar los 18 controladores: cualquier uso de `$this->compareEnabled($request)` o `$this->wantsRefresh($request)` debe ser eliminado junto con el código que dependa de él.

**`DateRange` (`app/Services/Metrics/DateRange.php`)**

- `PRESETS` queda como `['today', 'yesterday', 'last_7_days']` (se retiran `this_month`, `last_month`, `this_year`).
- Si `DateRange::fromRequest()` recibe un preset eliminado (URL antigua o externa), el comportamiento actual de `if ($preset && in_array($preset, self::PRESETS, true))` ya cae al default — no requiere lógica nueva.

**Tests**

- Ajustar tests existentes que aserten sobre `previous`, `previous_daily_series`, `compare`, `refresh`.
- Añadir un test feature por scope (Sucursal y Empresa) sobre `MetricsIndexController`: la respuesta Inertia incluye `range`, `presets`, `selected_branch_id`, `statuses`, `tenant`; **no** incluye `compare`, `refresh`, ni rama `data.*.previous`.
- Añadir test sobre `DateRange::PRESETS`: contiene exactamente `['today', 'yesterday', 'last_7_days']`.

### Frontend

**`resources/js/Pages/Sucursal/Metricas/Index.vue` y `Pages/Empresa/Metricas/Index.vue`**

Quedan minimalistas. Reciben solo lo que `commonProps()` aún expone (no `data`, no `compare`, no `backfill_run_at`). Estructura:

```vue
<script setup>
import MetricsLayout from '@/Layouts/MetricsLayout.vue';
import MetricsHeader from '@/Components/Metrics/MetricsHeader.vue';
import MetricsHubGrid from '@/Components/Metrics/MetricsHubGrid.vue';
import { Head } from '@inertiajs/vue3';
import { useMetricsFilters } from '@/composables/useMetricsFilters';

defineProps({ tenant: Object });
const filters = useMetricsFilters('sucursal.metricas.index'); // o empresa.metricas.index
</script>

<template>
  <Head title="Métricas" />
  <MetricsLayout>
    <MetricsHeader title="Métricas" subtitle="Elegí un eje para explorar." :filters="filters" show-status-chip />
    <MetricsHubGrid scope="sucursal" /> <!-- o "empresa" -->
  </MetricsLayout>
</template>
```

**`resources/js/Components/Metrics/MetricsHubGrid.vue` (nuevo)**

Reemplaza a `Components/Metrics/Content/IndexContent.vue`. Su única responsabilidad: renderizar el grid 4×2 de los 8 ejes.

- Props: `scope: 'sucursal' | 'empresa'`.
- Lee `slug` de `usePage().props.tenant`.
- Define internamente la lista `subpages` con `key`, `label`, `hint`, `icon` (idéntica a la actual: `ventas`, `margen`, `productos`, `clientes`, `cajeros`, `turnos`, `cobranza`, `cancelaciones`).
- Renderiza cada card como `<Link>` a `route('{scope}.metricas.{key}', { ...slug, ...currentQuery })` preservando los query params (los filtros se llevan al eje).
- Tailwind: `grid grid-cols-2 lg:grid-cols-4 gap-3`. Cada card reutiliza el estilo actual de las cards de "Explorar por eje" (hover translate + sombra).

**`resources/js/Components/Metrics/Content/IndexContent.vue`**

Se elimina (reemplazado por `MetricsHubGrid.vue`).

**`resources/js/Components/Metrics/MetricsHeader.vue`**

- Eliminar el bloque `<label v-if="showCompare">` (checkbox Comparar).
- Eliminar el `<button @click="filters.refresh()">` Actualizar.
- Eliminar la rama `<span v-if="compareEnabled && previousRangeLabel">` del subheader.
- Eliminar las props `showCompare` y los cálculos `compareEnabled`, `previousRangeLabel`.
- Sin cambios en `showStatusChip` ni en `showBranchSelector`.
- Auditar todos los callsites que pasen `:show-compare="..."` y limpiar.

**`resources/js/Components/Metrics/DateRangeFilter.vue`**

- Lista de presets visibles reducida a `today`, `yesterday`, `last_7_days` y la opción Calendario (que corresponde a `__custom__`).
- Eliminar la UI para `this_month`, `last_month`, `this_year`. Si el composable recibe uno de esos por URL antigua, se silencian (el backend ya no los acepta y cae al default).

**`resources/js/composables/useMetricsFilters.js`**

- Eliminar `compare`, `setCompare`, `refresh`, `setRefresh`.
- Eliminar los query params `compare` y `refresh` de la serialización.
- Lista interna de presets válidos: `['today', 'yesterday', 'last_7_days', '__custom__']`.

**`resources/js/Components/Metrics/MetricsSubSidebar.vue` y `MetricsBreadcrumb.vue`**

- Ambos serializan `compare` en query strings (`if (page.props.compare !== undefined) q.compare = page.props.compare ? 1 : 0;`). Eliminar esas líneas — `page.props.compare` ya no existe.

**`resources/js/Components/Metrics/KpiCard.vue`**

- Mantener la prop `delta` declarada para no romper callsites de inmediato, pero el componente deja de renderizar el chip de delta. Idealmente, en una pasada de limpieza posterior, eliminar la prop por completo y los `:delta="..."` en cada callsite.

**`resources/js/Components/Metrics/TimeSeriesCard.vue`**

- Eliminar props `previous` y `compare`.
- Eliminar `previousAgg` y `hasComparison`.
- Render: una sola serie (`current`) con su color. Sin línea fantasma.

**`Components/Metrics/Content/VentasContent.vue` y `MargenContent.vue`**

Son los únicos `Content/*Content.vue` (además de `IndexContent`, que se elimina) que hoy declaran `props.compare`. Los demás (`ProductosContent`, `ClientesContent`, `CobranzaContent`, `CajerosContent`, `TurnosContent`, `CancelacionesContent`) **no** usan `props.compare` y no requieren cambios.

En ambos:

- Eliminar la prop `compare`.
- Eliminar funciones helper `pct`, `d`/`deltaIf`, y referencias a `previousRangeText`.
- Las llamadas `<KpiCard :delta="d(...)" />` se simplifican a `<KpiCard />` sin delta.
- Subtítulos de gráficos pasan de `... vs. ${previousRangeText}` a `... · ${currentRangeText}`.
- `<TimeSeriesCard :compare="props.compare" :previous="..." />` queda como `<TimeSeriesCard :current="..." />` sin previous ni compare.

### Lo que NO se toca

- **Sidebar** (`MetricsSubSidebar.vue`): misma estructura, mismos links — solo se quita la línea que serializa `compare`.
- **Servicios de cálculo por eje**: solo se elimina la rama `'previous' => …` de sus `summary()`. Firmas, parámetros y demás métodos quedan idénticos.
- **Cada eje individual mantiene sus KPIs y gráficos** — solo se les quita la capa de comparativa.
- **`BackfillBanner`** sigue apareciendo en cada eje (donde sí hay números que contextualizar). Solo se quita del Resumen.
- **`StatusFilterChips`** se mantiene como hoy.
- **Migraciones y modelos**: sin cambios.

## Comportamiento de filtros

- `useMetricsFilters` continúa siendo la fuente única de verdad para `preset`, `from`, `to`, `statuses`, `branchId` (en Empresa).
- Al hacer click en un card del hub, el `<Link>` lleva los query params actuales en la URL del eje destino. Si el usuario tenía `?preset=last_7_days&statuses=completed,pending` en Resumen, entra a Ventas con los mismos filtros aplicados.
- Si el usuario ingresa directamente a un eje (deep-link), los filtros viven en ese eje y persisten en URL como hoy.

## Estados visuales

- **Mobile**: el grid colapsa a `grid-cols-2` (4 filas × 2 columnas). En `lg` en adelante, 4 columnas (1 fila × 4 × 2 filas).
- **Hover**: cards conservan la animación actual (`hover:-translate-y-0.5 hover:border-red-300 hover:shadow-md`).
- **Sin datos**: no aplica (el hub no muestra datos).

## Impacto y riesgo

### Archivos a tocar

**Backend (15):**
- `app/Services/Metrics/MetricsService.php` (eliminar `dashboardSummary`).
- `app/Services/Metrics/DateRange.php` (recortar `PRESETS`).
- `app/Http/Controllers/Concerns/ResolvesMetricsRequest.php` (eliminar `compareEnabled`, `wantsRefresh`, recortar `commonProps`).
- `app/Http/Controllers/{Sucursal,Empresa}/Metrics/MetricsIndexController.php` (×2: reescritura mínima).
- `app/Http/Controllers/{Sucursal,Empresa}/Metrics/SalesMetricsController.php` (×2: quitar `previous_daily_series`).
- `app/Services/Metrics/{SalesMetrics,MarginMetrics,CustomerMetrics,CollectionMetrics,ProductMetrics,CashierMetrics,ShiftMetrics,CancellationMetrics}.php` (×8: quitar rama `'previous'` de sus `summary()`).

**Frontend (12):**
- `resources/js/Pages/{Sucursal,Empresa}/Metricas/Index.vue` (×2: simplificar).
- `resources/js/Components/Metrics/MetricsHubGrid.vue` (nuevo).
- `resources/js/Components/Metrics/Content/IndexContent.vue` (eliminar).
- `resources/js/Components/Metrics/MetricsHeader.vue`.
- `resources/js/Components/Metrics/DateRangeFilter.vue`.
- `resources/js/Components/Metrics/KpiCard.vue`.
- `resources/js/Components/Metrics/TimeSeriesCard.vue`.
- `resources/js/Components/Metrics/MetricsSubSidebar.vue` (quitar serialización de `compare`).
- `resources/js/Components/Metrics/MetricsBreadcrumb.vue` (idem).
- `resources/js/Components/Metrics/Content/VentasContent.vue`.
- `resources/js/Components/Metrics/Content/MargenContent.vue`.
- `resources/js/composables/useMetricsFilters.js`.

**Tests (al menos 3):**
- Ajuste de tests existentes en `tests/Feature/Services/Metrics/` que asserten sobre `'previous'`.
- Nuevo test feature por scope sobre `MetricsIndexController` (ausencia de `compare`/`refresh`/`previous`).
- Test sobre `DateRange::PRESETS`.

### Riesgo

- **Bajo**. Cambio cosmético/estructural; no toca datos ni dominio.
- URLs antiguas con `?compare=1`, `?refresh=1` o `?preset=this_month` se ignoran silenciosamente (el backend deja de leer esos params, el composable los descarta).
- Ningún cambio de migración.
- El sidebar y las rutas se conservan, así que bookmarks y muscle memory no se rompen.

### Migración no requerida

No hay esquema afectado.

## Métricas de éxito

- Resumen pasa de ~270 líneas de Vue (`IndexContent.vue`) + ~25 (`Index.vue`) a ~50 líneas totales (`MetricsHubGrid.vue` ~40 + `Index.vue` ~15).
- `MetricsHeader.vue` pierde ~30 líneas (toggle Comparar + botón Actualizar + subheader comparativo).
- Payload Inertia del Resumen pasa de ~15 claves anidadas (`data.sales.current/previous`, `data.margin.*`, `data.collection`, `data.daily_series`, `data.previous_daily_series`, `data.heatmap`, `data.top_products_by_margin`, etc.) a las que retiene `commonProps()`: `range`, `presets`, `selected_branch_id`, `statuses`, `tenant`. Las queries SQL que alimentaban el Resumen viejo desaparecen (`dashboardSummary` borrado).
- Tiempo de respuesta del controlador Resumen mejora medible.
