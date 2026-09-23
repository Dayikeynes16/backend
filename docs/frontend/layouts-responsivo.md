# Layouts y pantallas medianas (tablet)

> Estado: vigente desde 2026-09-23.

La web se usa en tablets (iPad, Surface), no solo en escritorio. Este doc
explica por qué se rompía la interfaz en ese tamaño y las reglas para que no
vuelva a pasar.

## Por qué la tablet es el peor tamaño

- Casi todo el código usa `sm:` (640px) y `lg:` (1024px); `md:` casi no existe.
  La tablet recibe el diseño de escritorio sin tener su espacio.
- En `lg:` aparece la barra lateral de 264px. Un iPad horizontal (1024px) deja
  **~696px** de contenido: menos que un iPad vertical sin barra lateral.
- El encabezado global comparte fila con nota rápida, campanas y chip de rol
  (~240px). A la página le quedan **~450px** en tablet.

## El encabezado global (`Layouts/*Layout.vue`)

Los cuatro layouts (Admin, Empresa, Sucursal, Cajero) comparten la misma forma:

- `min-h-16` / `min-h-14`, no altura fija: si el contenido no cabe, el
  encabezado crece en vez de desbordarse sobre la franja de batería.
- El slot `#header` vive en un `div.min-w-0.flex-1`: puede encogerse; los
  íconos de la derecha llevan `shrink-0` y no se aplastan.
- El chip del rol solo aparece desde `xl:` (1280px): en tablet se cede ese
  espacio a la página.

### Reglas para el slot `#header`

1. **Título y, a lo mucho, una acción corta.** Nada de selectores, chips de
   filtro ni `DatePicker`: van en una barra dentro de la página
   (`mb-6 flex flex-wrap items-center gap-2`), como hacen los Dashboards de
   Empresa y Sucursal y `Components/Metrics/MetricsHeader.vue`.
2. Si hay título + acciones, el contenedor lleva `flex-wrap` y el título
   `min-w-0`/`truncate`, para que las acciones bajen de renglón en lugar de
   deformarse (ver `Sucursal/Cortes/Show.vue`).

## Pantallas lista + detalle (Pagos, Historial)

- La lista mide `w-2/5 min-w-[300px]` y toma su ancho fijo solo desde `xl:`.
- El panel de detalle lleva `min-w-0` para que su contenido no empuje la página.
- Las rejillas de datos del detalle usan
  `grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))]`: se acomodan al ancho real
  del panel, que en tablet no depende del ancho de la pantalla.
- Alturas a pantalla completa con `100dvh`, no `100vh`: en Safari de iPad
  `100vh` incluye la barra de direcciones y corta el panel abajo.

> Pendiente: esas alturas siguen restando números fijos
> (`h-[calc(100dvh-7rem)]`). Si aparece la franja de batería, el aviso de
> verificar correo o la barra de filtros baja de renglón, el panel se sale unos
> píxeles. La solución de fondo es que el layout sea una columna flex y el
> `main` use `flex-1 min-h-0`.

## Tablas

Toda `<table>` va dentro de un contenedor con `overflow-x-auto` (no
`overflow-hidden`): en tablet la tabla se desplaza de lado en vez de aplastar
columnas o ensanchar la página. `overflow-x-auto` también recorta las esquinas
redondeadas, así que sustituye a `overflow-hidden` sin cambiar el aspecto.
