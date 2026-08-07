# Rediseño visual del Atlas del sistema

**Estado:** Diseño aprobado; implementación pendiente
**Fecha:** 2026-08-07
**Aplicación anfitriona:** `carniceria-saas`
**Rama:** `feat/atlas-rediseno-visual` (parte de `feat/atlas-vivo`, commit `58f2f6f`)
**Doc viva del módulo:** [atlas-vivo.md](../../frontend/atlas-vivo.md)
**Spec del MVP original:** [2026-08-06-atlas-vivo-sistema-design.md](2026-08-06-atlas-vivo-sistema-design.md)
**Prototipos de referencia:** [`docs/frontend/prototipos/atlas-campus-prototipo.html`](../../frontend/prototipos/atlas-campus-prototipo.html) y [`atlas-planta-prototipo.html`](../../frontend/prototipos/atlas-planta-prototipo.html)

## Por qué se rediseña

El MVP del Atlas se entregó completo y funcional el 2026-08-06: acceso exclusivo
para `superadmin`, manifiesto versionado de 47 módulos, búsqueda, filtros, tres
modos de lectura, panel de detalle y vista de lista accesible. Toda esa capa
funciona y se conserva.

Lo que se rechazó fue la **representación visual**. El spec original prohibió
Three.js, canvas e imágenes como estructura, y fijó una proyección isométrica
paralela dibujada con geometría calculada. El resultado fueron prismas de color
plano sobre un fondo oscuro con retícula y tipografía monoespaciada — un
lenguaje "plano técnico" que choca con el sistema visual de la aplicación
anfitriona (fondo claro, barra lateral roja, superficies blancas). Además, las
etiquetas flotaban desancladas y se solapaban con los volúmenes vecinos, defecto
que requirió tres rondas de corrección durante la Task 7 sin quedar resuelto.

El diagnóstico es que el problema no era de ejecución sino de dirección: una
proyección paralela con volúmenes desnudos no puede comunicar identidad ni
jerarquía, por bien que se dibuje.

## Decisiones aprobadas

Tomadas con el usuario sobre prototipos interactivos (sesión del 2026-08-07).

1. **Fachada legible.** Cada ventana de un edificio *es* un módulo, coloreada por
   su estado real. El dibujo codifica datos; deja de ser decoración.
2. **Lienzo fijo con panel deslizante.** La página no se desplaza
   verticalmente. El panel de detalle se superpone al entrar y se cierra con
   `Escape`, dejando el ancho completo al mapa mientras no hay selección.
3. **Bandas por frontera de red.** El suelo se divide en tres franjas —nube,
   sucursal (LAN) y dispositivos— y cada aplicación vive en la suya. Cruzar una
   banda significa cruzar una frontera de red, de modo que "qué sobrevive sin
   internet" se lee en la posición y no solo en el panel.
4. **Objeto temático por módulo.** Cada módulo lleva encima un objeto que declara
   su oficio (candado para autenticación, báscula para su API, antena para
   realtime, estantería para productos). No es solo ornamento: el objeto puede
   cargar significado — Inventario se representa como una **estantería vacía**,
   legible sin leyenda y sin color.
5. **Perspectiva navegable, sin rotación.** El azimut es fijo. El usuario
   **se desplaza** por el plano y la proyección en perspectiva hace el resto: lo
   cercano crece, lo lejano se encoge hacia el punto de fuga. Se descartó
   explícitamente la cámara orbital: aportaba maniobra sin aportar lectura.
6. **En pantallas estrechas, el Atlas abre en vista de lista.** El mapa queda
   disponible a un clic. Una lista bien construida es mejor herramienta que un
   mapa apretado.

Se mantienen vigentes todas las decisiones del spec original que no contradigan
lo anterior: solo lectura, exclusivo `superadmin`, manifiesto como documentación
derivada, estados con evidencia, sin Three.js ni canvas.

## Alcance

### Se conserva sin cambios funcionales

| Pieza | Motivo |
|---|---|
| `system-architecture.json` — **el inventario auditado no cambia** | Aplicaciones, módulos, estados, evidencia y conexiones se conservan tal cual; solo cambian campos de presentación (ver más abajo) |
| `scripts/validate-architecture-manifest.mjs` | Se extiende para los campos nuevos; su lógica se conserva |
| `architectureGraph.js` | Índices, adyacencias, búsqueda y URLs de fuente seguras |
| `architectureQuery.js` | Serialización de `app`, `module`, `mode`, `view` |
| `architectureRuntime.js` | Validación defensiva de la prop |
| `useArchitectureExplorer.js` | Estado de navegación y selección |
| `ArchitectureAtlasController` + ruta + gating | Sin tocar |

### Se modifica

| Pieza | Cambio |
|---|---|
| `ArchitectureExplorer.vue` (270 líneas) | Monta `SceneCanvas` en lugar de las escenas viejas; asume el umbral responsive y el arranque en lista |
| `ArchitectureListView.vue` | **Se amplía**, no solo se re-estiliza: hoy no expone conexiones, modos ni banda de red, y el diseño exige equivalencia con el mapa (ver contrato abajo) |
| `system-architecture.schema.json` | Se reescribe: `schemaVersion`, `icon`, `layer`, `facadeColumns` y el nuevo `visualLayout` |
| `ArchitectureToolbar`, `ArchitectureBreadcrumbs`, `ArchitectureDetailPanel`, `ArchitectureLegend`, `Pages/Admin/ArchitectureAtlas/Index.vue` | Re-estilizado; misma responsabilidad |

### Se reemplaza por completo

`EcosystemScene.vue`, `ApplicationBuilding.vue`, `ApplicationScene.vue`,
`ModuleRoom.vue`, `ConnectionLayer.vue`, `ArchitectureStatusPattern.vue`,
`lib/architectureGeometry.js`, `lib/architectureSceneTokens.js`,
`lib/architectureVisualTokens.js`. **2.111 líneas.**

### Fuera de alcance

- Telemetría, salud en vivo, latencias o tráfico. El Atlas muestra **estado
  documentado**, nunca estado observado.
- Rotación de cámara, vuelo libre o cualquier control 3D adicional.
- Regenerar el manifiesto automáticamente desde los repositorios.
- Verificar el manifiesto contra el código real: es el objeto de
  [las guardas de veracidad](2026-08-07-atlas-guardas-veracidad-design.md).
- Cambios en autorización, rutas, API, esquema de base de datos o negocio.

## Arquitectura

El principio de corte es aislar la proyección del significado. El motor no sabe
qué es un módulo; las capas de dominio no saben cómo se proyecta un punto.

```text
manifest (prop Inertia)
  └─ ArchitectureExplorer.vue           orquesta nivel, filtros, selección, breakpoint
       ├─ SceneCanvas.vue               viewport, gestos, foco, accesibilidad
       │    ├─ CampusLayer.vue          4 edificios + bandas + conductos entre apps
       │    └─ FloorLayer.vue           N módulos + objetos + conductos intra-app + gateways
       ├─ ArchitectureListView.vue      ruta equivalente garantizada
       └─ ArchitectureDetailPanel.vue   panel deslizante
```

### Modelo de render — decisión estructural

**El motor devuelve datos, no cadenas SVG, y `SceneCanvas` los renderiza como
nodos Vue con `:key` estable.** No se usa `innerHTML` en ningún punto.

Esto no es una preferencia de estilo: reconstruir el SVG por cadena en cada
fotograma —como hacen los prototipos— **destruye el foco y los escuchadores de
eventos en cada cuadro**, lo que es incompatible con entidades enfocables por
teclado y con `camera.focusOn()` animando mientras un elemento tiene el foco. El
prototipo podía permitírselo porque no tenía accesibilidad; la implementación no.

Cada primitiva es un objeto plano `{ id, kind, points, fill, stroke, strokeWidth }`.
La `key` de cada grupo es el identificador de la entidad del manifiesto, estable
entre fotogramas.

### El motor de escena — `lib/scene/`

Módulos puros, sin Vue, sin DOM, sin estado global.

| Archivo | Responsabilidad única |
|---|---|
| `projection.js` | Convierte un punto del mundo `(x, y, z)` en `(sx, sy, depth)` |
| `camera.js` | Posición, distancia, elevación, desplazamiento con inercia, encuadre de un objetivo |
| `box.js` | Emite las caras visibles de una caja, con culling e iluminación |
| `painter.js` | Ordena primitivas por profundidad antes de emitirlas |
| `lighting.js` | Lambert de una luz direccional fija en el espacio del mundo |
| `labels.js` | Escala, ancla y decide visibilidad de rótulos |
| `iconCatalog.js` | Definiciones declarativas de los objetos temáticos |
| `sceneConfig.js` | Todas las constantes en un solo lugar |

**Proyección.** Azimut fijo `AZ = 0.60 rad`. Para un punto `(x, y, z)` con la
cámara en `(cx, cy)`, elevación `φ` y distancia `D`:

```text
dx = x - cx                     dy = y - cy
xr =  dx·cos(AZ) + dy·sin(AZ)
yr = -dx·sin(AZ) + dy·cos(AZ)
depth = D + yr·cos(φ) - z·sin(φ)
sx = VW + FOC · xr / depth
sy = VH - FOC · (yr·sin(φ) + z·cos(φ)) / depth
```

Con `depth < 1.3` la primitiva se descarta (plano de recorte cercano). La
proyección debe devolver siempre valores finitos; un `NaN` es fallo de prueba.

**Constantes por defecto** (validadas en prototipo, todas en `sceneConfig.js`):

| Constante | Valor | Nota |
|---|---|---|
| `AZ` | `0.60 rad` | Fijo, no configurable por el usuario |
| `FOC` | `780` | Distancia focal |
| `D` | `17` | Rango 6–46 |
| `φ` | `28°` | Rango 16–60 |
| `VW` | `480` | Centro horizontal sobre `viewBox` 960 |
| `VH` | `300` | **No es alto/2**: desplaza el horizonte hacia arriba sobre `viewBox` 540 |
| `dragK` | `D · 0.00016` | Sensibilidad de arrastre, proporcional a la distancia |
| `releaseFactor` | `0.70` | Velocidad heredada al soltar |
| `damping` | `0.90` por fotograma, corte en `1e-4` | Inercia |
| `lerpElevation` | `0.14` | Suavizado de elevación |
| `lerpDistance` | `0.15` | Suavizado de distancia |

**Culling e iluminación.** Una cara lateral con normal `n` es visible cuando su
componente **rotada por el azimut** cumple `ny < -0.02`. La luminancia usa esa
**misma normal rotada** contra la luz normalizada `(-0.42, -0.58, 0.70)`:

```text
k = max(0, nRot · luz)
L = lum · (0.57 + 0.35 · k)
```

Se especifica la normal rotada, y no la del mundo, porque es lo que implementa el
prototipo aprobado; usar la sin rotar altera la luminancia de cada cara alrededor
de un 6 %.

**Orden de pintado.** Painter's algorithm por `yr` del centro de cada entidad.
Las primitivas de una misma entidad (bloque y su objeto temático) se ordenan
entre sí y se emiten como un grupo, lo que evita el entrelazado incorrecto entre
entidades vecinas.

## Cambios al manifiesto y su contrato

Todos son de presentación: **ninguno altera la semántica auditada, los estados,
la evidencia ni las conexiones**.

### Campos nuevos

| Ruta | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `modules[].icon` | `string` | sí | Clave del catálogo de objetos temáticos |
| `applications[].layer` | `"cloud" \| "lan" \| "device"` | sí | Banda del suelo |
| `applications[].facadeColumns` | `integer` 2–6 | no | Columnas de ventanas; se deriva si falta |

### `visualLayout` — forma nueva completa

Reemplaza la actual (`viewBox`, `buildings`, `rooms`, `connectionRoutes` con
`path` SVG). Las unidades son **unidades de mundo** de la escena, no píxeles:
`viewBox` desaparece porque la cámara ya no encuadra un lienzo fijo, y los
trazados SVG desaparecen porque las curvas se calculan en perspectiva.

```json
{
  "units": "world",
  "campus": {
    "bands": [
      { "layer": "cloud",  "y0": -8.2, "y1": -2.2 },
      { "layer": "lan",    "y0": -2.2, "y1":  2.8 },
      { "layer": "device", "y0":  2.8, "y1":  8.4 }
    ],
    "applications": [
      { "applicationId": "app.saas", "x": 0, "y": -5.0,
        "width": 3.4, "depth": 2.4, "height": 2.3 }
    ]
  },
  "floors": [
    { "applicationId": "app.saas", "columns": 5, "gap": 1.16,
      "modules": [ { "moduleId": "saas.core.tenancy-auth", "col": 0, "row": 0 } ] }
  ],
  "connectionRoutes": [
    { "connectionId": "conn.web.saas-inertia", "bow": 0.5 }
  ]
}
```

Reglas que el validador debe exigir:

- `bands` tiene exactamente una entrada por cada valor de `layer`, sin solapes y
  contiguas (`y1` de una es `y0` de la siguiente).
- Toda aplicación aparece en `campus.applications`, y su `y` cae dentro de la
  banda de su `layer`.
- Toda aplicación tiene una entrada en `floors`, y los módulos de ese `floor` son
  exactamente los módulos de esa aplicación — ni sobran ni faltan.
- Dentro de un `floor`, ningún par `(col, row)` se repite.
- Todo `connectionRoutes[].connectionId` existe; `bow` es finito.
- Las cajas de dos aplicaciones no se solapan en planta.

### Reglas derivadas (no se declaran, se calculan)

**Columnas de fachada.** Los módulos se reparten entre las dos caras visibles:

```text
perFace  = ceil(n / 2)
columns  = facadeColumns ?? clamp(round(sqrt(perFace × 1.6)), 2, 6)
rows     = ceil(perFace / columns)
```

**Altura del bloque de un módulo.** A partir de su peso técnico:

```text
weight = |endpointIds| + |eventIds| + |databaseTableIds|      // rango real hoy: 0–16
height = clamp(0.20 + 0.30 × weight / WEIGHT_REFERENCE, 0.20, 0.50)
```

`WEIGHT_REFERENCE = 16` es una **constante fija en `sceneConfig.js`, no el máximo
recalculado del manifiesto**. Si se derivara del conjunto, añadir un módulo
grande reescalaría todo el mapa y cambiaría la lectura de módulos que no se
tocaron.

`metadata.schemaVersion` sube a `1.1.0`. Los cuatro `snapshotRefs` no cambian.
Si las guardas de veracidad se implementan antes, esta versión absorbe también
sus dos campos de `evidence[]`.

## Sistema visual

El Atlas adopta el lenguaje de la aplicación anfitriona. Desaparecen el fondo
oscuro, la retícula tipo plano y la tipografía monoespaciada de los rótulos de
escena (se conserva monoespaciada solo para identificadores técnicos).

- Superficies claras, `slate` para estructura, el rojo de marca reservado a
  acentos y estado activo.
- Estados con la paleta existente: verde implementado, ámbar parcial, gris
  pendiente, azul en revisión, rojo con incidencias.
- Etiquetas **ancladas** al objeto con un conector visible. Nunca desancladas ni
  superpuestas al vecino.

### Rótulos y tamaño mínimo

Los rótulos escalan por profundidad, pero **por debajo de 11 px reales se ocultan
en lugar de encogerse**. Un rótulo de 7 px es ruido, no información. Los rótulos
ocultos siguen alcanzables por teclado y presentes en la vista de lista, así que
no se pierde acceso a ningún dato.

El texto que sí se muestra respeta 4.5:1 de contraste sobre su superficie real,
incluidos rótulos sobre bandas de color.

### Conductos — los nueve tipos en uso

El manifiesto usa nueve de los diez `kind` permitidos. Todos necesitan estilo
propio; el grosor varía con la profundidad.

| `kind` | Uso | Trazo | Color |
|---|---|---|---|
| `http` | 10 | continuo | gris `slate-400` |
| `websocket` | 2 | discontinuo largo | azul cielo |
| `database` | 2 | punteado fino | violeta |
| `cache` | 1 | discontinuo corto | amarillo |
| `ipc` | 1 | punteado muy fino | púrpura |
| `polling` | 1 | discontinuo espaciado | ámbar |
| `sync-outbox` | 1 | discontinuo medio | verde |
| `usb-serial` | 1 | mixto raya-punto | naranja |
| `mdns` | 1 | punteado disperso | turquesa |
| `external-link` | 0 | discontinuo largo | gris claro (reserva) |

`external-link` no se usa hoy pero se define: es el fallback ante un `kind`
desconocido, para que un manifiesto futuro no rompa la escena.

**Los conductos existen en los dos niveles.** El campus dibuja las conexiones
entre aplicaciones; la planta conserva los conductos intra-aplicación y los
gateways hacia otras aplicaciones que ya dibuja `ApplicationScene.vue` hoy. Los
tres modos de lectura filtran en ambos niveles.

### El estado no depende del color

Requisito duro. Las tramas del diseño anterior no son viables a tamaño de
ventana, así que el canal redundante es la **forma**:

| Estado | Forma |
|---|---|
| `implemented` | ventana llena |
| `partial` | ventana llena hasta la mitad |
| `pending` | ventana vacía, contorno punteado |
| `in-review` | ventana con marco doble |
| `issues` | ventana con aspa |
| `not-responsible` | ventana tapiada (diagonal llena) |
| `requires-review` / `unknown` | ventana con interrogante |

## Accesibilidad

Un mapa en perspectiva que se recorre arrastrando no es accesible por sí solo.
El diseño lo asume y responde en dos frentes.

### 1. La vista de lista es la ruta garantizada

No es un extra. Para sostener la equivalencia, `ArchitectureListView` debe
exponer, por entidad, **todo lo que el mapa comunica visualmente**:

| Lo que el mapa dice | Cómo | Lo que la lista debe exponer |
|---|---|---|
| Estado | color y forma de la ventana | estado en texto |
| Oficio del módulo | objeto temático | nombre y descripción |
| Frontera de red | banda del suelo | `layer` en texto |
| Peso técnico | altura del bloque | conteo de endpoints, eventos y tablas |
| Relaciones | conductos | conexiones entrantes y salientes, con su `kind` |
| Modo de lectura | conductos visibles | la lista respeta el modo activo |

Esta equivalencia se **verifica en pruebas**, no se declara en documentación.

### 2. El mapa también se recorre con teclado

Edificios y módulos son elementos enfocables con `role="button"` y `aria-label`
que incluye nombre y estado en texto.

**Roving tabindex.** El orden del DOM es el orden de pintado —lo impone el
painter's algorithm— y no coincide con el orden de lectura. Por eso solo **una**
entidad tiene `tabindex="0"` a la vez y el resto `tabindex="-1"`; las flechas
mueven el foco siguiendo el **orden de lectura de la lista**, gestionado por el
componente. `Home` y `End` saltan al primero y al último. No se usan `tabindex`
positivos.

Al recibir foco, `camera.focusOn(target)` encuadra el objetivo. `Enter` desciende
de nivel, `Escape` cierra el panel o sube de nivel.

Con `prefers-reduced-motion: reduce` se desactivan inercia, encuadres animados y
redibujado continuo: la escena se recompone al terminar el gesto.

## Responsive

| Ancho | Comportamiento |
|---|---|
| ≥ 1280 px | Mapa completo, panel deslizante sobre el lienzo |
| 1024–1279 px | Mapa completo, panel ocupa el borde inferior |
| < 1024 px | **Abre en vista de lista.** El mapa queda accesible con un control explícito |

El umbral vive en `sceneConfig.js`. La preferencia del usuario dentro de la
sesión gana sobre el valor por defecto.

## Rendimiento

Es el riesgo técnico principal: el campus completo supera las 600 primitivas SVG,
y la planta del SaaS —**18 módulos**, no 17— ronda las 400.

- **No se redibuja en reposo.** El bucle solo corre mientras hay gesto activo,
  inercia pendiente o transición de cámara.
- Durante el gesto se emite una **escena reducida**: se omiten objetos temáticos
  y rótulos por debajo de un umbral de profundidad, y se restauran al detenerse.
  Las entidades enfocables no se desmontan durante la reducción, para no perder
  el foco.
- Presupuesto: **fotograma bajo 16 ms** en el campus completo y en la planta del
  SaaS, en el equipo de referencia del proyecto. Si no se alcanza sin la escena
  reducida, la degradación se documenta en la doc viva.

## Pruebas

### Se retiran

- `architecture-geometry.test.mjs` (572 líneas). Prueba la geometría de rombos,
  habitaciones y etiquetas del diseño anterior.

### Se reescriben (no basta con ampliar)

- `architecture-experience.test.mjs`: **3 de sus 5 pruebas leen el código fuente
  de componentes que desaparecen** (`EcosystemScene`, `ApplicationScene`,
  `ModuleRoom`, `ApplicationBuilding`, `ConnectionLayer`). Se reescriben contra
  `SceneCanvas`, `CampusLayer` y `FloorLayer`.
- `architecture-manifest.test.mjs`: sus aserciones sobre `$defs.building` /
  `$defs.room`, la igualdad exacta de claves con el schema y los 20
  `connectionRoutes` con `path` SVG dejan de aplicar. Se reescribe esa sección
  contra el `visualLayout` nuevo; el resto de la suite se conserva.

### Cobertura que hay que recuperar

Al retirar la suite de geometría se pierde la única cobertura de cinco garantías
que siguen vigentes. **No se pierden: cambian de casa.**

| Garantía perdida | Dónde vuelve |
|---|---|
| Contraste WCAG AA por estado | `scene-contrast.test.mjs` |
| Tamaño de texto efectivo mínimo | `scene-labels.test.mjs`, ahora con el umbral de 11 px y la regla de ocultar |
| Rótulos que no intersectan su nodo ni a otros | `scene-labels.test.mjs` |
| Rutas finitas, sin `NaN` | `scene-projection.test.mjs` |
| Las 20 conexiones cubren los tres modos | `architecture-manifest.test.mjs` (se conserva ahí) |

### Suites nuevas, sobre el motor puro y sin montar componentes

| Suite | Verifica |
|---|---|
| `scene-projection.test.mjs` | Puntos conocidos proyectan a valores esperados; recorte cercano; monotonía de `depth`; ausencia de `NaN` |
| `scene-camera.test.mjs` | Desplazamiento, límites, amortiguación, `focusOn` encuadra dentro del viewport |
| `scene-box.test.mjs` | Culling: exactamente las caras esperadas por azimut; luminancia en rango, con normal rotada |
| `scene-painter.test.mjs` | Orden por profundidad; agrupación de entidad y sus objetos |
| `scene-labels.test.mjs` | Escala por profundidad, umbral de 11 px, no intersección, anclaje |
| `scene-contrast.test.mjs` | 4.5:1 en cada combinación estado × superficie |
| `scene-icons.test.mjs` | Todo `modules[].icon` existe; catálogo sin huérfanos |
| `scene-layout.test.mjs` | Reglas de `visualLayout`: bandas contiguas, apps dentro de su banda, `floors` completos, sin `(col,row)` repetidos, sin solapes |
| `scene-accessibility.test.mjs` | Cada entidad expone rol, etiqueta textual y participa del roving tabindex; el estado es deducible sin color |
| `list-equivalence.test.mjs` | La lista expone las seis dimensiones del contrato de equivalencia para toda entidad del manifiesto |

`ArchitectureAtlasAccessTest` se conserva, con el ajuste de los conteos que ya
dependen del manifiesto.

## Riesgos y limitaciones

| Riesgo | Mitigación |
|---|---|
| Rendimiento en el campus completo | Escena reducida durante el gesto; sin bucle en reposo; presupuesto explícito |
| 47 iconos asignados a mano | Catálogo cerrado y validado; un icono ausente es error de validación, no fallo silencioso. **No hay icono genérico de reserva**: es deliberado, para que añadir un módulo obligue a decidir su objeto |
| Migrar `visualLayout` a mano | `scene-layout.test.mjs` valida las reglas estructurales; una migración incompleta falla en rojo |
| La metáfora envejece con el inventario | La altura se deriva con referencia fija; `visualLayout` se valida contra el conjunto de módulos |
| Deriva del manifiesto frente al código real | **No se resuelve aquí.** Es el objeto del spec de guardas de veracidad |
| Percepción de "herramienta lúdica" | Decisión consciente y aceptada: es una herramienta interna de un solo usuario |

## Criterios de aceptación

- [ ] El campus muestra 4 edificios en sus bandas, con fachadas que reflejan el estado real de sus módulos.
- [ ] La planta muestra los módulos de una aplicación con su objeto temático, sus conductos internos y sus gateways.
- [ ] Arrastrar desplaza la cámara con perspectiva e inercia; no existe rotación.
- [ ] Los tres modos de lectura cambian los conductos visibles en campus y en planta.
- [ ] Los nueve `kind` en uso tienen estilo propio y distinguible; un `kind` desconocido cae al estilo de reserva.
- [ ] Búsqueda, filtros, breadcrumbs y URLs compartibles siguen funcionando igual.
- [ ] Cada estado es distinguible sin color.
- [ ] Ningún rótulo se dibuja por debajo de 11 px reales.
- [ ] El foco sobrevive al redibujado: ninguna parte del render usa `innerHTML`.
- [ ] Roving tabindex recorre edificios y módulos en orden de lectura, y la cámara encuadra el elemento enfocado.
- [ ] `prefers-reduced-motion` desactiva inercia y animaciones.
- [ ] Por debajo de 1024 px el Atlas abre en vista de lista.
- [ ] La lista expone las seis dimensiones del contrato de equivalencia.
- [ ] Validador y suites en verde; contraste mínimo 4.5:1.
- [ ] Cero migraciones, endpoints, escrituras o cambios de negocio.
- [ ] Sin dependencias nuevas: ni Three.js, ni canvas, ni librerías de escena.
