# Rediseño del módulo de Métricas (sucursal)

**Fecha:** 2026-04-19
**Alcance:** Panel `admin-sucursal`, rutas bajo `/{tenant}/sucursal/metricas/*`.
**Autor:** Sebas + Claude (brainstorming colaborativo).
**Estado:** Diseño aprobado por el usuario; pendiente spec-review y plan de implementación.

---

## 1. Contexto y problema

El módulo de Métricas ya existe (ver spec previa `2026-04-17-metricas-sucursal-empresa-design.md`) y está en producción. La arquitectura backend es sólida — servicios desacoplados (`SalesMetrics`, `MarginMetrics`, etc.), controllers invokables por eje, caché de 5 min, filtros globales (`DateRange`, preset, comparar) que se preservan al navegar.

Sin embargo, el usuario reporta cinco problemas de UX y transparencia:

1. **Navegación confusa** — al entrar a un eje (ventas, margen, productos, clientes, cajeros, turnos, cobranza) no hay indicación clara de dónde está el usuario dentro del módulo, ni cómo moverse entre ejes sin volver al hub.
2. **No hay forma evidente de "volver"** — no existe breadcrumb, botón back ni navegación secundaria dentro de la página.
3. **Opacidad del cálculo de ganancia/margen** — el servicio `MarginMetrics` excluye correctamente los items sin `cost_price_at_sale`, pero la UI no lo comunica. El usuario no sabe qué ventas entraron al cálculo ni cuáles quedaron fuera.
4. **Cálculos no validados explícitamente** — tests cubren autorización y algunos agregados, pero no hay un test end-to-end que verifique el cálculo de margen frente a sumas manuales con datos mixtos (con y sin costo).
5. **Gráficos sin leyendas** — los `ChartCard` muestran título pero no explican qué miden ni de dónde salen los datos.

Este spec resuelve los cinco puntos en un pase coherente. **Alcance aprobado por el usuario: opción B** — UI/UX + unificar cálculos de margen y añadir test end-to-end. Queda explícitamente fuera: tests HTTP para los otros 5 ejes, centralización de formateo, migración de caché a Redis.

---

## 2. Auditoría resumen (estado actual)

### 2.1 Estructura actual

- **Rutas** (`routes/web.php` ~línea 258): `sucursal.metricas.index` + 7 ejes invokables.
- **Controllers**: uno invokable por eje (`SalesMetricsController`, `MarginMetricsController`, …).
- **Services**: `app/Services/Metrics/*` — agnósticos de HTTP, retornan arrays.
- **Vues**: `resources/js/Pages/Sucursal/Metricas/*.vue` + contenido en `ComponentsMetrics/Content/*.vue`.
- **Componentes compartidos**: `MetricsHeader`, `KpiCard`, `ChartCard`, `DataTable`, `EmptyState`, `BackfillBanner`.
- **Filtros**: preservados entre ejes vía `useMetricsFilters()` (query params + `router.get` con `replace: true`).

### 2.2 Inconsistencias detectadas en `MarginMetrics.php`

- `aggregateFor()` usa `CASE WHEN cost_price_at_sale IS NULL THEN 0 ELSE ... END` en el `SELECT`.
- `dailyGrossProfit()` y `byCategory()` usan `->whereNotNull('cost_price_at_sale')` en el `WHERE`.
- `byProduct()` calcula margen con `CASE` pero **no filtra nulos**; reporta `has_missing_cost` por producto pero la vista no lo renderiza.

Resultado numérico equivalente, pero patrón mezclado dificulta auditoría y refactor. El dato `items_without_cost` existe en el payload pero ningún componente de UI lo consume.

### 2.3 Tests actuales

- `tests/Feature/Services/Metrics/MarginMetricsTest.php` — agregados a nivel service.
- `tests/Feature/Http/Sucursal/Metrics/MetricsAuthorizationTest.php` — acceso por rol, redirect de invitado, filtro branch.
- **Ausente**: test HTTP end-to-end que valide el cálculo de margen contra sumas manuales con datos mixtos.

---

## 3. Diseño

### 3.1 Navegación — sub-sidebar de Métricas

Cuando el usuario está bajo `/{tenant}/sucursal/metricas/*`, aparece un **sub-sidebar interno** entre el sidebar principal de sucursal y el contenido:

```
┌─ Sidebar sucursal ─┐ ┌─ Sub-sidebar métricas ───┐ ┌─ Contenido ─────────┐
│ Dashboard          │ │ 📊 Resumen              ▶│ │ <MetricsHeader>     │
│ …                  │ │ ── Ejes ──               │ │ <MetricsBreadcrumb> │
│ Métricas ←activo   │ │ 💵 Ventas                │ │  Sucursal › Métricas│
│ Configuracion      │ │ 📈 Margen     ← activo   │ │   › Margen          │
│                    │ │ 📦 Productos             │ │                     │
│                    │ │ 👥 Clientes              │ │ <Contenido del eje> │
│                    │ │ 🧑‍💼 Cajeros              │ │                     │
│                    │ │ ⏱ Turnos                 │ │                     │
│                    │ │ 💳 Cobranza              │ │                     │
└────────────────────┘ └──────────────────────────┘ └─────────────────────┘
```

**Comportamiento:**

- "Resumen" = el `Index.vue` actual (7 tarjetas de overview). Mantiene su valor como hub.
- Cambiar de eje en el sub-sidebar preserva filtros (rango, comparar, preset). Los links usan `router.get(route, currentQueryParams)`.
- **Mobile (<1024px)**: sub-sidebar colapsa a un dropdown "Ver: Margen ▾" arriba del contenido.
- **Breadcrumb** (`MetricsBreadcrumb.vue`): `Sucursal › Métricas › <Eje>`; cada segmento clickeable.
- **Botón secundario** `← Volver al resumen` junto al breadcrumb en vistas de eje, como escape rápido.

**Componentes y layout:**

- Nuevo `resources/js/Layouts/MetricsLayout.vue` — envuelve al `SucursalLayout` existente sin modificarlo; añade el slot del sub-sidebar y el breadcrumb.
- Nuevo `resources/js/Components/Metrics/MetricsSubSidebar.vue` — recibe `activeAxis` prop, enlaces con preservación de query params.
- Nuevo `resources/js/Components/Metrics/MetricsBreadcrumb.vue` — recibe `axis` prop (o null si estamos en Index).
- Cada uno de los 7 `Pages/Sucursal/Metricas/*.vue` cambia su layout por `MetricsLayout`.

### 3.2 Leyendas y copy

Cada `ChartCard` muestra un **subtítulo inline corto siempre visible** bajo el título (una sola línea, ≤90 caracteres, sin ícono de info). Si más adelante se requiere fórmula completa, se añade popover ℹ️ como mejora incremental — no entra en este pase.

**Tabla de subtítulos oficiales:**

| Eje / gráfico | Subtítulo |
|---|---|
| Ventas · Ingresos diarios | `Ventas cobradas completas por día. Excluye canceladas y pendientes.` |
| Ventas · Heatmap hora×día | `Concentración de ventas por hora del día. Color = monto cobrado.` |
| Ventas · Métodos de pago | `Distribución por forma de pago de ventas cobradas.` |
| Margen · Ganancia diaria | `Ingresos − costo al momento de venta. Solo items con costo registrado.` |
| Margen · Margen % por categoría | `(Ganancia ÷ ingreso) por categoría. Solo ítems con costo.` |
| Productos · Top ingreso | `Los 10 productos que más facturaron en el rango.` |
| Productos · Sin movimiento | `Productos sin ventas en los últimos N días.` |
| Clientes · Top | `Los 10 clientes con mayor compra acumulada.` |
| Clientes · Aging saldos | `Saldo pendiente por antigüedad de deuda.` |
| Cajeros · Tabla | `Desempeño por cajero: tickets, total, % cancelación, descuentos.` |
| Turnos · Diferencia diaria | `Declarado − esperado al cierre de turno. Solo turnos cerrados.` |
| Cobranza · Cobranza diaria | `Pagos aplicados por día (todos los métodos).` |
| Cobranza · Aging CxC | `Saldos por cobrar por antigüedad. Requiere cliente asignado.` |

**`MetricsHeader`** también gana un subtítulo explicativo bajo el título del eje:
> `Métricas › Margen — Rentabilidad calculada con costo registrado al momento de cada venta.`

**Vocabulario consistente en todo el módulo:**
- "Ventas cobradas completas" ≡ `status = Completed AND amount_pending <= 0`
- "Items con costo registrado" ≡ `cost_price_at_sale IS NOT NULL`
- "Rango actual" ≡ el filtro visible arriba (nunca "hoy", "este mes" literal)

**Componentes:**
- Nuevo `resources/js/Components/Metrics/ChartLegend.vue` — props `title`, `subtitle`. Renderiza `<h3>` + `<p class="text-sm text-gray-500">`.
- Modificar `ChartCard.vue` para aceptar slot `#subtitle` o prop `subtitle` (retrocompatible).
- Modificar los 7 `*Content.vue` — añadir subtítulo por chart según tabla.

### 3.3 Transparencia del cálculo de margen

Tres piezas coordinadas en `MargenContent.vue`. **Todas condicionales a `items_without_cost > 0`.**

**3.3.1 Banner arriba de los KPIs**

```
┌─ MarginCoverageBanner ───────────────────────────────────────────────┐
│ ⚠  12 items vendidos sin costo registrado fueron excluidos          │
│    del cálculo de ganancia y margen en este rango.                  │
│                                         [ Ver productos afectados → ]│
└──────────────────────────────────────────────────────────────────────┘
```
- Tono: `bg-amber-50 text-amber-800 border-amber-200` — informativo, no error.
- Acción del link: scroll a la tabla de productos y activa el filtro `has_missing_cost=true`.
- Si `items_without_cost === 0`: no se renderiza.

**3.3.2 Footnote en el KPI de Ganancia bruta**

Bajo el delta comparativo, texto gris pequeño (`text-gray-500 text-xs mt-1`):
- Con exclusiones: `Basado en 348 items con costo · 12 excluidos`.
- Sin exclusiones: `Basado en 348 items con costo registrado.`

**3.3.3 Badge en la tabla de productos**

En la columna "Producto", badge pill `[sin costo]` (gris claro) solo en filas con `has_missing_cost === true`. Click abre tooltip:
> "Algunas ventas de este producto no tenían costo registrado al momento de la venta. Esas ventas no entraron en el cálculo de ganancia."

Se añade un toggle encima de la tabla: `☐ Ver solo productos sin costo` (filtro cliente, sin re-fetch).

**3.3.4 Copy oficial — regla de cálculo**

Documentada en `docs/modulos/metricas.md` y referenciada desde el banner y el tooltip:

> **Regla de cálculo de ganancia y margen**
> La ganancia bruta y el margen se calculan como `(precio de venta − costo registrado) × cantidad`, usando el costo que estaba vigente al momento exacto de la venta (campo `cost_price_at_sale`).
> Si un producto se vendió sin costo registrado (porque no se había capturado en el catálogo al momento de la venta), esa venta se excluye del cálculo de margen, pero **sí aparece en Ventas y Productos**.

**Componentes:**
- Nuevo `resources/js/Components/Metrics/MarginCoverageBanner.vue` — props `itemsWithoutCost`.
- Modificar `KpiCard.vue` para aceptar slot `#footnote` o prop `footnote`.
- Modificar `MargenContent.vue` — banner, footnote, badge, filtro.
- Modificar `docs/modulos/metricas.md`.

### 3.4 Unificación del cálculo en `MarginMetrics`

**Regla:** todo SUM/AVG de margen se calcula en un query con `->whereNotNull('sale_items.cost_price_at_sale')` en el `WHERE`. Los conteos de "universo excluido" (`items_without_cost`, `has_missing_cost`) se calculan en queries separados **sin** ese filtro. Nunca se mezclan en el mismo `SELECT`.

**Refactor por método** (`app/Services/Metrics/MarginMetrics.php`):

- **`aggregateFor()`**
  - Antes: `CASE WHEN cost_price_at_sale IS NULL THEN 0 ELSE ...` en el SELECT.
  - Después: `whereNotNull('cost_price_at_sale')` en el WHERE para el cálculo de `gross_profit`, `revenue_with_cost`, `margin_pct`, `margin_per_ticket`.
  - Los conteos `items_with_cost` e `items_without_cost` se calculan en un segundo query (misma ventana temporal/branch) que cuenta por `cost_price_at_sale IS NULL` vs `NOT NULL`. Esto mantiene el dato para el banner y el KPI footnote.
  - Protección de división por cero en `margin_pct` se conserva.

- **`dailyGrossProfit()`** — ya usa `whereNotNull`. No se toca.
- **`byCategory()`** — ya usa `whereNotNull`. No se toca.
- **`byProduct()`**
  - Añadir `whereNotNull('cost_price_at_sale')` al cálculo de `gross_profit`, `revenue_with_cost` y `margin_pct` por producto.
  - `has_missing_cost` se calcula en un segundo query: `SELECT product_id FROM sale_items WHERE cost_price_at_sale IS NULL AND ...` — lista blanca por producto.
  - El resultado final hace `merge` de ambos queries por `product_id`.

**Racional:**
- Patrón uniforme facilita auditoría (1 regla, 4 métodos).
- Postgres optimiza mejor con `whereNotNull` + índice parcial (follow-up §7).
- Separar el cálculo del reporte de cobertura evita bugs de `CASE` implícitos.

### 3.5 Test end-to-end del cálculo

Archivo nuevo: `tests/Feature/Http/Sucursal/Metrics/MarginCalculationTest.php`.

Todos los tests usan factories reales (no mocks), hacen `$this->actingAs($adminSucursal)->get(route('sucursal.metricas.margen', ...))` y validan el payload que Inertia pasa a la vista (cubre controller + service + flujo a la UI en un solo test).

**Casos cubiertos:**

1. `ganancia_bruta_suma_solo_items_con_costo_registrado`
   Tres ventas: dos con `cost_price_at_sale`, una sin. Asserta:
   - `summary.gross_profit` == suma manual de las dos con costo.
   - `summary.items_without_cost` == qty de la venta sin costo.
   - `summary.items_with_cost` == qty de las dos con costo.

2. `margen_porcentaje_es_cero_cuando_no_hay_items_con_costo`
   Todas las ventas sin `cost_price_at_sale`. Asserta `summary.margin_pct === 0` (no null, no NaN, no división por cero) y `items_without_cost > 0`.

3. `by_product_marca_has_missing_cost_cuando_producto_tiene_ventas_sin_costo`
   Producto X con 5 ventas (3 con costo, 2 sin). Asserta:
   - `byProduct[X].has_missing_cost === true`.
   - `byProduct[X].gross_profit` == suma manual de las 3 con costo (no las 5).

4. `by_category_excluye_items_sin_costo`
   Categoría con ventas mixtas; la ganancia por categoría coincide con la suma manual de items con costo.

5. `filtro_branch_se_respeta_y_no_mezcla_margen_de_otra_sucursal`
   Dos sucursales con ventas; el payload de branch A no contiene datos de branch B.

6. `rango_de_fechas_se_aplica_por_hora_local_de_la_sucursal`
   Ventas al filo del día (23:58 y 00:02 del día siguiente) caen en el día correcto según timezone configurado.

---

## 4. Plan de archivos

**Nuevos (6):**
```
resources/js/Layouts/MetricsLayout.vue
resources/js/Components/Metrics/MetricsSubSidebar.vue
resources/js/Components/Metrics/MetricsBreadcrumb.vue
resources/js/Components/Metrics/ChartLegend.vue
resources/js/Components/Metrics/MarginCoverageBanner.vue
tests/Feature/Http/Sucursal/Metrics/MarginCalculationTest.php
```

**Modificados (13):**
```
app/Services/Metrics/MarginMetrics.php            — unificar whereNotNull (§3.4)
resources/js/Components/Metrics/KpiCard.vue       — slot/prop footnote
resources/js/Components/Metrics/ChartCard.vue     — slot/prop subtitle

resources/js/Pages/Sucursal/Metricas/Index.vue            — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Ventas.vue           — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Margen.vue           — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Productos.vue        — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Clientes.vue         — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Cajeros.vue          — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Turnos.vue           — usa MetricsLayout
resources/js/Pages/Sucursal/Metricas/Cobranza.vue         — usa MetricsLayout

resources/js/ComponentsMetrics/Content/MargenContent.vue
    — banner + KPI footnote + badge en tabla + toggle "ver solo sin costo"

docs/modulos/metricas.md   — sección "Regla de cálculo de ganancia y margen"
```

**Sin cambios:** controllers, services distintos a `MarginMetrics`, `routes/web.php`, caché, schema, migrations, backfill, `BackfillBanner`.

---

## 5. Testing

**Automático (CI):**
- Nuevo archivo `MarginCalculationTest.php` (6 casos, §3.5).
- Los tests existentes (`MarginMetricsTest`, `MetricsAuthorizationTest`, agregados de ventas) deben seguir pasando sin modificación — la unificación no cambia resultados numéricos.

**Manual (antes de merge):**

1. Navegar a `/{tenant}/sucursal/metricas` → hub + sub-sidebar visibles, "Resumen" activo.
2. Saltar Margen → Ventas → Productos desde el sub-sidebar; rango/preset/compare se preservan.
3. En Margen con datos sembrados (usar `migrate:fresh --seed` y ajustar algunas `cost_price_at_sale` a null): aparecen banner, footnote del KPI y badges en tabla.
4. En viewport <1024px: sub-sidebar colapsa a dropdown; breadcrumb sigue visible.
5. Click en cada crumb del breadcrumb: navega correctamente.
6. Deshabilitar datos sin costo (`items_without_cost = 0`): banner, footnote expandido y badges desaparecen — footnote estándar queda.

---

## 6. Criterios de aceptación

- [ ] Cualquier página bajo `/metricas/*` muestra el sub-sidebar con el eje actual marcado.
- [ ] Breadcrumb clickeable presente en todas las vistas de eje (no en Index).
- [ ] Cada `ChartCard` de los 7 ejes tiene subtítulo inline (tabla §3.2).
- [ ] En Margen: banner, footnote y badge aparecen **solo si** `items_without_cost > 0`.
- [ ] `MarginMetrics` usa `whereNotNull` en los cuatro métodos para el cálculo; los conteos de excluidos viven en queries separados.
- [ ] `MarginCalculationTest` pasa los 6 casos.
- [ ] `docs/modulos/metricas.md` contiene la sección "Regla de cálculo de ganancia y margen".
- [ ] Ningún test existente falla.
- [ ] Navegación preserva query params (rango, preset, compare) al cambiar de eje.
- [ ] Mobile <1024px: sub-sidebar funcional como dropdown.

---

## 7. Out of scope (follow-ups anotados)

- Ícono ℹ️ con popover de fórmula completa por chart.
- Tests HTTP end-to-end para Productos, Clientes, Cajeros, Turnos, Cobranza.
- Centralización de formateo (`formatCurrency`, `formatNumber`) en un composable.
- Migración de caché a Redis con cache tags.
- Índice parcial Postgres en `sale_items (sale_id, product_id) WHERE cost_price_at_sale IS NOT NULL`.

---

## 8. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Sub-sidebar rompe layout en tenant con sidebar custom | `MetricsLayout` envuelve `SucursalLayout` sin modificarlo; rollback = revertir 1 archivo |
| Refactor `aggregateFor()` cambia números por accidente | Los 6 tests validan totales contra sumas manuales de factories |
| Badge en tabla afecta perf con 100+ filas | `has_missing_cost` ya viene por fila; render es un `v-if` barato |
| Copy de subtítulos puede sonar técnico | Revisión final con el usuario antes de merge; los textos cambian sin rebuild de assets |
| Inconsistencia entre `MarginMetricsTest` (service) y nuevos casos (HTTP) | Ambos apuntan al mismo service; si difieren, se arregla la factory antes de mergear |

---

## 9. Referencias

- Spec previa: `docs/superpowers/specs/2026-04-17-metricas-sucursal-empresa-design.md`
- Doc del módulo: `docs/modulos/metricas.md`
- Service principal: `app/Services/Metrics/MarginMetrics.php`
- Layout base: `resources/js/Layouts/SucursalLayout.vue`
- Composable de filtros: `resources/js/composables/useMetricsFilters.js` (vía `MetricsHeader.vue`)
