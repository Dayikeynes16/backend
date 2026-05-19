# Métricas · Ventas — Card "Ventas por hora" adaptativa

**Fecha:** 2026-05-19
**Estado:** aprobado para implementar
**Alcance:** Una sola card dentro de `resources/js/Components/Metrics/Content/VentasContent.vue` — bloque de `heatmapSeries` y `heatmapOptions` (líneas ~54-65 del `<script setup>`) más el render del `<ChartCard>` heatmap (líneas ~116-121 del `<template>`). Cero cambios a backend, servicios, controladores o tests automatizados existentes.

## Motivación

Con el rango "Hoy" (o cualquier rango corto en una sucursal con poca actividad), el heatmap actual queda **~95% en blanco** y tres problemas se vuelven evidentes:

1. **Etiquetas X-axis ilegibles**: los labels `00h, 01h, …, 23h` están rotados/encimados, no se pueden leer.
2. **Leyenda inapropiada para pesos**: rangos crudos (`5001 - 999999`) en vez de pesos formateados.
3. **Densidad nula**: el heatmap pierde su razón de ser cuando solo hay 1-2 días de datos. La pregunta operativa real ("¿a qué hora vendo?") quedaría mejor respondida con un bar chart simple.

La intención es **que el chart se adapte al volumen de datos del rango**, siguiendo el patrón ya establecido por `TimeSeriesCard.vue` (modos `single`/`bars`/`line` según buckets).

## Decisiones tomadas

- **Threshold**: por días del rango. `daysInRange ≤ 7` → bar chart 0h-23h. `daysInRange > 7` → heatmap (existente, mejorado).
- **Bar chart fallback**: 1 dimensión (solo hora). Agrega los 1-7 días del rango sumando por columna.
- **Métrica**: solo monto vendido. Sin toggle Monto/Tickets.
- **Datos**: sin cambios. `data.heatmap` (matrix 7×24) sigue siendo la única fuente; el bar chart agrega en frontend.
- **Ubicación**: inline en `VentasContent.vue`. No se extrae a un componente nuevo. YAGNI: solo se usa una vez.

## Arquitectura

### Datos

El payload del controlador `SalesMetricsController` no cambia. Sigue exponiendo:

```php
'heatmap' => $service->hourDayHeatmap($range, $branchId, $tenantId, $statuses),
```

`hourDayHeatmap()` retorna un mapa `{dow: 1..7 → {hour: 0..23 → {tickets: int, total: float}}}`. El bar chart se construye en frontend:

```js
// Agregación: suma por hora a lo largo de todos los días del rango.
const hourlyTotals = computed(() => {
    const m = props.data?.heatmap ?? {};
    return Array.from({ length: 24 }, (_, h) => {
        let total = 0;
        for (let d = 1; d <= 7; d++) {
            total += Number(m[d]?.[h]?.total ?? 0);
        }
        return total;
    });
});
```

### Decisión de modo

```js
const daysInRange = computed(() => Number(usePage().props.range?.days ?? 1));
const useBarMode = computed(() => daysInRange.value <= 7);
const totalAmount = computed(() => hourlyTotals.value.reduce((s, v) => s + v, 0));
const isEmpty = computed(() => totalAmount.value === 0);
```

`page.props.range.days` ya es expuesto por `DateRange::toArray()` (clave `days`, integer).

### Render

Pseudocódigo dentro de `<template>`:

```html
<ChartCard :title="cardTitle" :subtitle="cardSubtitle">
  <EmptyHourState v-if="isEmpty" /> <!-- inline, no nuevo componente -->
  <apexchart v-else-if="useBarMode" type="bar" height="280"
    :options="barOptions" :series="barSeries" />
  <apexchart v-else type="heatmap" height="320"
    :options="heatmapOptions" :series="heatmapSeries" />
</ChartCard>
```

### Bar chart spec (modo ≤7 días)

- **Series**: una sola, `[{ name: 'Ventas', data: hourlyTotals }]`.
- **Categories**: `['00h', '01h', ..., '23h']`.
- **Color**: `#dc2626` (rojo carnicería, coherente con resto de la pantalla).
- **Labels sobre barras**: `formatter: v => v > 0 ? formatCurrency(v) : ''`, `style.fontSize: '10px'`, `offsetY: -20`.
- **Y-axis labels**: usan un abreviador (`$1k`, `$5k`, `$1M`) ya disponible como helper `abbreviated()` en `TimeSeriesCard`. Considerar mover ese helper a `useCurrency.js` si se reutiliza; por ahora copiar inline (3 líneas).
- **X-axis labels**: `style.fontSize: '11px'`, sin rotación (24 etiquetas fijas caben en horizontal).
- **Tooltip Y**: `formatCurrency`.
- **Grid**: `borderColor: '#f3f4f6'`, `padding.top: 30` (para que la etiqueta de la barra no se corte).
- **Plot**: `bar.columnWidth: '60%'`, `borderRadius: 4`.
- **Legend**: oculto (`show: false`) — solo hay una serie.

### Heatmap mejorado (modo >7 días)

Las correcciones aplicadas al heatmap actual:

- **Leyenda en pesos**: `colorScale.ranges` con `name` formateado a pesos:
  ```js
  ranges: [
      { from: 0,    to: 0,        color: '#f9fafb', name: 'Sin ventas' },
      { from: 1,    to: 500,      color: '#fecaca', name: 'Hasta $500' },
      { from: 501,  to: 2000,     color: '#fca5a5', name: '$500 – $2k' },
      { from: 2001, to: 5000,     color: '#ef4444', name: '$2k – $5k' },
      { from: 5001, to: 999999,   color: '#991b1b', name: 'Más de $5k' },
  ]
  ```
  (El último range mantiene su `to: 999999` como guard técnico, pero ya no es visible en la leyenda — el `name` "Más de $5k" lo reemplaza.)
- **X-axis labels**: agregar `rotate: -45` y `hideOverlappingLabels: true` al `xaxis.labels`.
- **Altura**: subir `chart.height` de `260` a `320` para que las celdas no queden tan finas.
- Resto del config (colores, plotOptions, tooltip) sin cambios.

### Empty state (`isEmpty === true`)

Misma estética que el empty state de `TimeSeriesCard.vue:295-306`:

```html
<div class="flex flex-col items-center justify-center rounded-xl border border-dashed
            border-gray-200 bg-gray-50/60 px-6 py-12 text-center">
    <!-- ícono -->
    <p class="mt-3 text-base font-semibold text-gray-700">
        No hubo ventas en este periodo
    </p>
    <p class="mt-1 max-w-sm text-sm text-gray-500">
        Cuando registres ventas verás aquí en qué horas se concentran.
    </p>
</div>
```

### Título y subtítulo

```js
const cardTitle = computed(() =>
    useBarMode.value ? 'Ventas por hora' : 'Ventas por hora y día de la semana'
);
const cardSubtitle = computed(() =>
    useBarMode.value
        ? 'Suma de ventas por hora del día en el rango seleccionado.'
        : 'Color = monto vendido. Más oscuro = más ventas. Identifica horas pico para planear staff y producción.'
);
```

### Layout impact

El heatmap actual vive en una columna ancha (`lg:col-span-2` de un `grid lg:grid-cols-3`). El bar chart usa el mismo contenedor — no se cambian las clases de grid. Vista responsive heredada de `ChartCard`.

## Lo que NO se toca

- **Backend** (`SalesMetrics::hourDayHeatmap`, `SalesMetricsController`): el endpoint y su payload quedan idénticos.
- **Otros heatmaps**: no hay más en la app.
- **Tests automatizados**: la decisión `useBarMode` es aritmética simple sobre un prop. Smoke manual cubre el flujo. No se agregan unit tests para el cálculo de `hourlyTotals`.
- **`TimeSeriesCard.vue`**: queda como está. Patrón inspirador, no consumidor.
- **`hourlySeries()`** del servicio: existe pero no se usa — el heatmap matrix ya tiene la dimensión de hora. No introducir una segunda fuente.

## Impacto y riesgo

- **Archivos a tocar**: 1 (`resources/js/Components/Metrics/Content/VentasContent.vue`).
- **Líneas estimadas**: +60 / -10 en el bloque del heatmap. Total del componente sube de ~150 a ~200 líneas, bajo el umbral donde tendría sentido extraer.
- **Riesgo**: nulo a nivel de datos (el contrato Inertia no cambia). Solo cambia la presentación. URLs antiguas siguen sirviendo.
- **Rollout**: requiere `sail npm run build` para que el cambio se vea en producción.

## Verificación

Smoke manual:
1. Login como `sucursal@eltoro.test`, navegar a `/{tenant}/sucursal/metricas/ventas`.
2. Preset **Hoy**: ver bar chart 0h-23h (puede estar vacío si no hay ventas hoy — empty state).
3. Preset **Ayer**: bar chart con barras pintadas en las horas con ventas.
4. Preset **7 días**: bar chart agregando los 7 días.
5. Calendario → 15 días: heatmap (con la leyenda nueva en pesos y labels X rotadas/no encimadas).
6. Calendario → 30 días en una sucursal con datos: heatmap con celdas legibles.

## Métricas de éxito

- En sucursales chicas con `Hoy/Ayer/7d`, la card pasa de "95% blanco" a "bar chart con barras donde hay ventas".
- En rangos largos (`>7d`), la leyenda usa pesos y los labels X son legibles.
- El componente sigue siendo una sola card de una sola query backend.
