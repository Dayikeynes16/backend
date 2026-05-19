# Plan de Implementación — Card "Ventas por hora" adaptativa

**Fecha**: 2026-05-19
**Spec**: `docs/superpowers/specs/2026-05-19-ventas-por-hora-adaptativa-design.md`
**Estado**: Listo para ejecución

> Cambio chico, una sola fase. Toca un solo archivo Vue, no toca backend ni tests automatizados.

---

## Fase 1 — VentasContent.vue adaptativo (única fase)

**Archivo a modificar:**

- `resources/js/Components/Metrics/Content/VentasContent.vue`

**Pasos:**

1. **Imports**: el archivo ya importa `computed`, `usePage`, `ChartCard`, `formatCurrency`. No requiere imports adicionales.

2. **Nuevos computeds en `<script setup>`** (justo después de los del heatmap actual):
   ```js
   // Agrega el matrix 7×24 → 24 totales por hora (suma de todos los días del rango).
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

   const daysInRange = computed(() => Number(page.props.range?.days ?? 1));
   const useBarMode = computed(() => daysInRange.value <= 7);
   const totalAmount = computed(() => hourlyTotals.value.reduce((s, v) => s + v, 0));
   const isHourEmpty = computed(() => totalAmount.value === 0);

   const cardTitle = computed(() =>
       useBarMode.value ? 'Ventas por hora' : 'Ventas por hora y día de la semana'
   );
   const cardSubtitle = computed(() =>
       useBarMode.value
           ? 'Suma de ventas por hora del día en el rango seleccionado.'
           : 'Color = monto vendido. Más oscuro = más ventas. Identifica horas pico para planear staff y producción.'
   );

   // Y-axis abreviador (mismo helper que TimeSeriesCard, copiado inline porque
   // todavía no vive en useCurrency).
   function abbreviated(v) {
       const n = Number(v ?? 0);
       if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
       if (Math.abs(n) >= 1_000) return `$${Math.round(n / 1_000)}k`;
       return `$${Math.round(n)}`;
   }
   ```

3. **Nuevo bloque `barSeries` y `barOptions`** (junto a `heatmapSeries`/`heatmapOptions`):
   ```js
   const barSeries = computed(() => [
       { name: 'Ventas', data: hourlyTotals.value },
   ]);

   const barOptions = computed(() => ({
       chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
       colors: ['#dc2626'],
       plotOptions: { bar: { columnWidth: '60%', borderRadius: 4, dataLabels: { position: 'top' } } },
       dataLabels: {
           enabled: true,
           formatter: (v) => v > 0 ? formatCurrency(v) : '',
           style: { fontSize: '10px', fontWeight: 700, colors: ['#111827'] },
           offsetY: -20,
       },
       xaxis: {
           categories: Array.from({ length: 24 }, (_, h) => `${String(h).padStart(2, '0')}h`),
           labels: { style: { fontSize: '11px', colors: '#6b7280' }, rotate: 0 },
           axisBorder: { show: false },
           axisTicks: { show: false },
       },
       yaxis: { labels: { formatter: abbreviated, style: { fontSize: '11px', colors: '#9ca3af' } } },
       tooltip: { y: { formatter: (v) => formatCurrency(v) } },
       grid: { borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 30 } },
       legend: { show: false },
   }));
   ```

4. **Actualizar `heatmapOptions`** (líneas ~65-76 actuales) con las correcciones del spec:
   - `colorScale.ranges` con `name` en pesos (ver spec).
   - `xaxis.labels.rotate: -45` y `hideOverlappingLabels: true`.
   - `chart.height` se controla desde el atributo del `<apexchart>` en el template — subir de 280 a 320.

5. **Render en `<template>`** — reemplazar el bloque actual:
   ```html
   <div class="lg:col-span-2">
       <ChartCard :title="cardTitle" :subtitle="cardSubtitle">
           <div v-if="isHourEmpty"
               class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-12 text-center">
               <span class="flex h-14 w-14 items-center justify-center rounded-full bg-white shadow-sm">
                   <svg class="h-7 w-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                       <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                   </svg>
               </span>
               <p class="mt-3 text-base font-semibold text-gray-700">No hubo ventas en este periodo</p>
               <p class="mt-1 max-w-sm text-sm text-gray-500">
                   Cuando registres ventas verás aquí en qué horas se concentran.
               </p>
           </div>
           <apexchart v-else-if="useBarMode" type="bar" height="280" :options="barOptions" :series="barSeries" />
           <apexchart v-else type="heatmap" height="320" :options="heatmapOptions" :series="heatmapSeries" />
       </ChartCard>
   </div>
   ```

6. **`page` ya está disponible**: ya se usa en el cómputo de `currentRangeText` (`page.props.range`). El nuevo `daysInRange` reusa esa instancia.

**Criterio de hecho:**

- `sail npm run build` sin errores ni warnings nuevos.
- Smoke manual cubierto (ver sección Verificación del spec, pasos 1-6).
- `git diff --stat` muestra **1 archivo modificado** (`resources/js/Components/Metrics/Content/VentasContent.vue`).

---

## Orden de ejecución

Una sola fase, un solo commit. Después de validar el smoke manual, commit y push.

Total estimado: ~1 archivo tocado, +60/-10 líneas, sin nuevas dependencias.
