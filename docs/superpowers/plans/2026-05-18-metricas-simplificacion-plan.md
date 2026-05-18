# Plan de Implementación — Métricas: Resumen como hub + header reducido + sin comparativas

**Fecha**: 2026-05-18
**Spec**: `docs/superpowers/specs/2026-05-17-metricas-simplificacion-design.md`
**Estado**: Listo para ejecución

> Cada fase es autocontenida y deja la app en un estado funcional. Después de la Fase 3, el Resumen ya es un hub navegable y el header está limpio en toda Métricas. Las fases 4–5 limpian deuda (comparativas en ejes individuales) y validan con tests.

---

## Fase 1 — Backend: coordinador + trait + Index/Sales controllers

Limpieza del backend que alimenta al Resumen y a Ventas. Después de esta fase, el backend deja de calcular el periodo previo y los presets largos. El frontend sigue funcionando porque los componentes que leen `previous_*` y `compare` toleran ausencia (optional chaining + defaults).

**Archivos a modificar:**

- `app/Services/Metrics/DateRange.php`:
  - `PRESETS` queda como `['today', 'yesterday', 'last_7_days']` (se retiran `this_month`, `last_month`, `this_year`).
  - `fromRequest` no requiere cambios: si llega un preset retirado, el `in_array` falla y cae al default actual.

- `app/Services/Metrics/MetricsService.php`:
  - Eliminar el método `dashboardSummary()` completo.
  - Auditar el constructor: retirar dependencias inyectadas (`CollectionMetrics`, `MarginMetrics`, `SalesMetrics`, etc.) que solo se usaban dentro del método borrado.

- `app/Http/Controllers/Concerns/ResolvesMetricsRequest.php`:
  - Eliminar los métodos `compareEnabled(Request $request): bool` y `wantsRefresh(Request $request): bool`.
  - En `commonProps()`, retirar las claves `'compare'` y `'refresh'`. La función queda devolviendo `range`, `presets`, `selected_branch_id`, `statuses`, `tenant`.

- `app/Http/Controllers/Sucursal/Metrics/MetricsIndexController.php`:
  - Reescritura mínima: deja de invocar `dashboardSummary` y `wantsRefresh`. Pasa solo `commonProps()` a Inertia (sin `data`, sin `backfill_run_at`, sin `compare`).

- `app/Http/Controllers/Empresa/Metrics/MetricsIndexController.php`:
  - Idem Sucursal **pero conserva** `'branches' => $this->branchOptions($tenantId)` (alimenta el selector de sucursal del header en Empresa).

- `app/Http/Controllers/Sucursal/Metrics/SalesMetricsController.php`:
  - Eliminar la línea `'previous_daily_series' => $service->dailySeries($range->previousComparable(), $branchId, $tenantId, $statuses),`.

- `app/Http/Controllers/Empresa/Metrics/SalesMetricsController.php`:
  - Idem.

**Auditoría requerida:**

Buscar y eliminar usos de `compareEnabled(` y `wantsRefresh(` en los 18 controladores. `grep -rn "compareEnabled\|wantsRefresh" app/Http/Controllers/` debe devolver 0 resultados después de la fase.

**Tests mínimos** (`tests/Feature/Services/Metrics/`):

- Ajustar cualquier test existente que asserte sobre `previous`, `previous_daily_series`, `compare` o `refresh`.
- Nuevo `tests/Unit/Metrics/DateRangePresetsTest.php`: `DateRange::PRESETS === ['today', 'yesterday', 'last_7_days']`.

**Criterio de hecho:**

- `sail artisan test --compact tests/Feature/Services/Metrics/ tests/Unit/Metrics/` verde.
- `grep -rn "compareEnabled\|wantsRefresh\|dashboardSummary" app/` devuelve 0 matches.

---

## Fase 2 — Backend: servicios por eje sin rama `'previous'`

Quitar la rama `'previous' => $this->summaryFor($range->previousComparable(), ...)` de los 8 servicios. Las firmas públicas no cambian.

**Archivos a modificar:**

- `app/Services/Metrics/SalesMetrics.php` — en `summary()`, quitar la clave `'previous'`.
- `app/Services/Metrics/MarginMetrics.php` — idem.
- `app/Services/Metrics/CancellationMetrics.php` — idem.
- `app/Services/Metrics/CustomerMetrics.php` — idem (si tiene `'previous'`).
- `app/Services/Metrics/CollectionMetrics.php` — idem.
- `app/Services/Metrics/ProductMetrics.php` — idem.
- `app/Services/Metrics/CashierMetrics.php` — idem.
- `app/Services/Metrics/ShiftMetrics.php` — idem.

Si algún método privado/protegido (`summaryFor`, `aggregateFor`, etc.) queda sin consumidores tras la eliminación, marcarlo y eliminarlo en la misma pasada.

**Tests mínimos:**

- Ajustar tests de `tests/Feature/Services/Metrics/*Test.php` que verifiquen la presencia de `'previous'`.

**Criterio de hecho:**

- `sail artisan test --compact tests/Feature/Services/Metrics/` verde.
- `grep -rn "'previous'" app/Services/Metrics/` devuelve 0 matches (excepto si hay otra clave `previous` no relacionada).

---

## Fase 3 — Frontend: hub + header + composable

El cambio visible del usuario. Después de esta fase, el Resumen es un grid de 8 ejes con filtros simplificados, y todas las páginas de Métricas tienen el header austero.

**Archivos a crear:**

- `resources/js/Components/Metrics/MetricsHubGrid.vue`:
  - Props: `scope: 'sucursal' | 'empresa'`.
  - Lee `slug` de `usePage().props.tenant`.
  - Lista interna `subpages` con `key/label/hint/icon` (las 8 existentes: ventas, margen, productos, clientes, cajeros, turnos, cobranza, cancelaciones).
  - Renderiza `<Link :href="route(...)">` para cada eje, preservando query params actuales para que los filtros se lleven al eje destino.
  - Tailwind: `grid grid-cols-2 lg:grid-cols-4 gap-3`. Reutilizar las clases de hover del actual "Explorar por eje" en `IndexContent.vue`.

**Archivos a modificar:**

- `resources/js/Pages/Sucursal/Metricas/Index.vue`:
  - Reducir a `<Head>`, `MetricsLayout`, `MetricsHeader`, `MetricsHubGrid scope="sucursal"`.
  - Eliminar imports y props de `data`, `compare`, `backfill_run_at`, `BackfillBanner`, `IndexContent`.

- `resources/js/Pages/Empresa/Metricas/Index.vue`:
  - Idem `scope="empresa"`. El selector de sucursales del header sigue funcionando porque `branches` viene en `commonProps()`.

- `resources/js/composables/useMetricsFilters.js`:
  - Eliminar `compare`, `setCompare`, `refresh`, `setRefresh`.
  - Eliminar los query params `compare` y `refresh` de la serialización.
  - Lista interna de presets válidos: `['today', 'yesterday', 'last_7_days', '__custom__']`.

- `resources/js/Components/Metrics/MetricsHeader.vue`:
  - Eliminar el bloque `<label v-if="showCompare">` (checkbox Comparar).
  - Eliminar el `<button @click="filters.refresh()">` Actualizar.
  - Eliminar la rama `<span v-if="compareEnabled && previousRangeLabel">` del subheader (queda solo `Mostrando: ... · {{ range.label }}`).
  - Eliminar prop `showCompare` y los computeds `compareEnabled`, `previousRangeLabel`.
  - Auditar todos los callsites: `grep -rn ":show-compare\|show-compare=" resources/js/Pages/` y limpiar.

- `resources/js/Components/Metrics/DateRangeFilter.vue`:
  - Lista de presets visibles: `today`, `yesterday`, `last_7_days`, y la opción Calendario (`__custom__`).
  - Eliminar la UI para `this_month`, `last_month`, `this_year`.

**Archivos a eliminar:**

- `resources/js/Components/Metrics/Content/IndexContent.vue` (reemplazado por `MetricsHubGrid.vue`).

**Tests mínimos:**

- Nuevo `tests/Feature/Http/Sucursal/Metrics/MetricsIndexResponseTest.php`: la respuesta Inertia de `sucursal.metricas.index` incluye `range`, `presets`, `selected_branch_id`, `statuses`, `tenant`; **no** incluye `compare`, `refresh`, ni `data`.
- Nuevo `tests/Feature/Http/Empresa/Metrics/MetricsIndexResponseTest.php`: idem + incluye `branches`.

**Criterio de hecho:**

- `sail npm run build` sin errores.
- Navegar a `/{tenant}/sucursal/metricas` y `/{tenant}/empresa/metricas` — ver hub 4×2, filtros simplificados, sin Comparar/Actualizar.
- Cambiar filtros en hub → click en un eje → llegar al eje con esos filtros aplicados (verificar query string).
- Suite verde: `sail artisan test --compact tests/Feature/Http/`.

---

## Fase 4 — Frontend: limpiar comparativas en componentes y ejes

Después de la Fase 3 el header ya no tiene Comparar y los datos `previous_*` ya no llegan, pero `VentasContent`, `MargenContent`, `TimeSeriesCard`, `KpiCard`, `MetricsSubSidebar` y `MetricsBreadcrumb` todavía tienen código muerto referenciando `compare` y `previous`. Esta fase los limpia.

**Archivos a modificar:**

- `resources/js/Components/Metrics/TimeSeriesCard.vue`:
  - Eliminar props `previous` y `compare`.
  - Eliminar `previousAgg`, `hasComparison`, y la serie previous del render Apex.
  - Render: una sola serie (`current`).

- `resources/js/Components/Metrics/KpiCard.vue`:
  - Mantener la prop `delta` declarada (compatibilidad temporal) pero dejar de renderizar el chip de delta.
  - Documentar (comentario corto) que la prop quedará deprecada hasta que todos los callsites la quiten.

- `resources/js/Components/Metrics/MetricsSubSidebar.vue`:
  - Línea ~33: eliminar `if (page.props.compare !== undefined) q.compare = page.props.compare ? 1 : 0;`.

- `resources/js/Components/Metrics/MetricsBreadcrumb.vue`:
  - Línea ~20: eliminar `if (page.props.compare !== undefined) q.compare = page.props.compare ? 1 : 0;`.

- `resources/js/Components/Metrics/Content/VentasContent.vue`:
  - Eliminar prop `compare`.
  - Eliminar funciones `pct`, `d`, y referencias a `previousRangeText`.
  - Llamadas `<KpiCard :delta="d(...)" />` quedan `<KpiCard ... />` sin `:delta`.
  - Subtítulo del `TimeSeriesCard`: pasar de `... vs. ${previousRangeText}` a `... · ${currentRangeText}`.
  - `<TimeSeriesCard :compare="..." :previous="..." />` queda `<TimeSeriesCard :current="..." />`.

- `resources/js/Components/Metrics/Content/MargenContent.vue`:
  - Idem VentasContent.

**Auditoría requerida:**

- `grep -rn "props.compare\|props\.compare\|:compare=" resources/js/` debe devolver 0 matches al final.
- `grep -rn ":previous=\|props.previous" resources/js/Components/Metrics/` debe devolver 0 matches.

**Criterio de hecho:**

- Visitar las páginas `ventas` y `margen` (Sucursal y Empresa) — ver que no aparecen deltas ±%, ni línea fantasma, ni subtítulos `vs. periodo previo`.
- `sail npm run build` sin warnings de props sin usar.

---

## Fase 5 — Tests + suite completa

Cierre con verificación cross-cutting.

**Tests a ajustar:**

- Cualquier test feature de `tests/Feature/Http/{Sucursal,Empresa}/Metrics/` que asserte sobre `compare`, `refresh`, `previous`.
- Tests existentes en `tests/Feature/Services/Metrics/` que dependan de `'previous'` en `summary()`.

**Tests nuevos (si no se cubrieron en fases previas):**

- `tests/Unit/Metrics/DateRangePresetsTest.php` (Fase 1).
- `tests/Feature/Http/Sucursal/Metrics/MetricsIndexResponseTest.php` (Fase 3).
- `tests/Feature/Http/Empresa/Metrics/MetricsIndexResponseTest.php` (Fase 3).
- `tests/Feature/Http/Sucursal/Metrics/SalesMetricsResponseTest.php`: respuesta de `sucursal.metricas.ventas` no incluye `previous_daily_series`.
- Idem Empresa.

**Criterio de hecho:**

- `sail artisan test --compact` verde, sin regresiones respecto al baseline de 486 tests.
- `sail bin pint --dirty --format agent` devuelve `{"result":"pass"}`.
- Smoke manual end-to-end:
  1. Login como `sucursal@eltoro.test`.
  2. Métricas → ver hub 4×2.
  3. Cambiar a `7 días` + activar Pendientes → ver query string actualizada.
  4. Click en Ventas → llegar con `?preset=last_7_days&statuses=completed,pending`.
  5. Ver que no hay deltas ni línea fantasma en el gráfico de tendencia.
  6. Volver a Métricas (hub) → los filtros siguen activos.
  7. Repetir con `admin@eltoro.test` en Empresa, verificando el selector de sucursal.

---

## Orden de ejecución sugerido

Las fases están ordenadas para que cada commit deje la app en estado verde:

1. **Fase 1** (backend coordinador) — commit independiente.
2. **Fase 2** (servicios por eje) — commit independiente.
3. **Fase 3** (hub + header + composable) — commit independiente; el feature ya es visible aquí.
4. **Fase 4** (limpieza de componentes) — commit independiente; deuda eliminada.
5. **Fase 5** (tests + verificación) — commit final si hay tests nuevos pendientes.

Total estimado: ~25 archivos tocados, ~1 archivo creado (`MetricsHubGrid.vue`), ~1 archivo eliminado (`IndexContent.vue`).
