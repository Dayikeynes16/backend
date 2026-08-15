# "Nuevo cliente" baja a la barra de herramientas

- **Estado:** Implementado (2026-08-15) · plan en `docs/superpowers/plans/2026-08-15-boton-nuevo-cliente-toolbar.md`
- **Fecha:** 2026-08-15
- **Repos afectados:** `carniceria-saas` (web, dos pantallas) y `carniceria-hub` (una)
- **Alcance:** posición de un botón y del contador. No toca datos, permisos ni el formulario de alta.

---

## 1. Problema

En la pantalla de Clientes, "Nuevo cliente" es un botón rojo sólido en la cabecera, a la altura del título (`Sucursal/Clientes/Index.vue:106-117`, `Caja/Clientes/Index.vue:99-110`, y junto al título en `CustomersView.vue:145-148` del hub).

Pesa visualmente lo mismo que el título de la pantalla y tira del ojo hacia una acción que se usa pocas veces al día. La cabecera termina sirviendo a dos amos: decir dónde estás y ofrecer una acción.

Mientras tanto, el dato que sí describe la pantalla —cuántos clientes hay— vive al final de la barra de herramientas como una pastilla gris (`Sucursal/Clientes/Index.vue:177`), donde nadie lo busca.

## 2. Decisión

**Los dos intercambian su sitio.**

- **"Nuevo cliente" baja a la barra de herramientas**, como última pieza de la fila que ya tiene buscar, filtro de estado, "Con deuda" y orden.
- **El contador sube a la cabecera**, a la derecha del título.

El razonamiento: dar de alta un cliente ocurre *mientras se trabaja la lista* —se busca, no aparece, se crea—, así que el botón pertenece al mismo sitio que buscar y filtrar. Y la cabecera queda puramente informativa, que es lo que hoy se ve recargado.

Alternativas descartadas, validadas visualmente con el dueño del producto el 2026-08-15:

| Opción | Por qué no |
|---|---|
| Botón flotante abajo a la derecha | Cómodo con el dedo en la tablet, pero tapa la última fila y en escritorio se siente de app móvil |
| Dejarlo arriba con estilo suave | El cambio más pequeño, pero lo que molesta es **que esté ahí**, no su color |

## 3. Detalles

- **El texto se mantiene completo: "Nuevo cliente".** En la toolbar hay sitio, y "Nuevo" a secas obliga a deducir de qué. En pantallas estrechas la fila se envuelve (`flex-wrap`, ya presente); no se recorta el texto.
- **Mantiene el rojo sólido.** Abajo ya no compite con el título, y es la única acción de creación de la pantalla. Lo que sobraba era su posición, no su color.
- **El contador conserva su texto actual** (`totalLabel`); solo cambia de sitio.

## 4. Lo que NO cambia

- **El botón "Crear el primero" del estado vacío se queda.** Sin ningún cliente, esa es la acción principal de la pantalla y merece el centro.
- El modal de alta, sus validaciones y el resto de la toolbar.
- Permisos: quién ve el botón no cambia. En el hub el cajero ya lo tiene desde 2026-08-13; "Eliminar" sigue siendo solo del admin.

## 5. Archivos

Esta pantalla existe **tres veces**:

| Archivo | Nota |
|---|---|
| `carniceria-saas/resources/js/Pages/Sucursal/Clientes/Index.vue` | Web, admin-sucursal |
| `carniceria-saas/resources/js/Pages/Caja/Clientes/Index.vue` | Web, cajero — casi gemela de la anterior |
| `carniceria-hub/src/renderer/views/CustomersView.vue` | Hub; lista en tarjetas, no en tabla, pero tiene la misma toolbar |

Las dos de la web son casi idénticas y **este cambio no las unifica**: hacerlo es un refactor con su propio riesgo y no es lo que se pidió. Queda anotado como deuda (§7).

## 6. Verificación

No hay cobertura automática de maquetación en ninguno de los dos repos: los tests de Inertia comprueban props y los de Vitest del hub, lógica. Así que aquí la verificación es:

1. Las suites siguen verdes (nada de esto toca lógica): `sail artisan test` y `npx vitest run`.
2. El build pasa en ambos.
3. Revisión visual en las tres pantallas, incluida una ventana estrecha para comprobar que la fila se envuelve en vez de recortarse.

## 7. Riesgos y deuda

- **El riesgo real es divergir**: tres pantallas, tres ediciones a mano. Si una se queda con el botón arriba, el módulo se ve inconsistente entre la web y el hub.
- **La duplicación entre `Sucursal/Clientes` y `Caja/Clientes` sigue ahí** y este cambio la hace más visible: dos archivos de ~350 líneas que hay que editar igual. Merece unificarse algún día; hoy no.
