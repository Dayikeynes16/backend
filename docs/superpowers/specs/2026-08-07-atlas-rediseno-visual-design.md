# Rediseño visual del Atlas del sistema

**Estado:** Diseño aprobado; implementación pendiente
**Fecha:** 2026-08-07
**Aplicación anfitriona:** `carniceria-saas`
**Rama:** `feat/atlas-rediseno-visual` (parte de `feat/atlas-vivo`, commit `58f2f6f`)
**Doc viva del módulo:** [atlas-vivo.md](../../frontend/atlas-vivo.md)
**Spec del MVP original:** [2026-08-06-atlas-vivo-sistema-design.md](2026-08-06-atlas-vivo-sistema-design.md)

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

Tomadas con el usuario sobre prototipos interactivos (sesión de brainstorming del
2026-08-07, mockups en `.superpowers/brainstorm/65494-1786066245/`).

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
| `system-architecture.json` (contenido) | El inventario auditado es válido; solo se le añaden campos de presentación |
| `scripts/validate-architecture-manifest.mjs` | Se extiende para los campos nuevos; su lógica se conserva |
| `architectureGraph.js` | Índices, adyacencias, búsqueda y URLs de fuente seguras |
| `architectureQuery.js` | Serialización de `app`, `module`, `mode`, `view` |
| `architectureRuntime.js` | Validación defensiva de la prop |
| `useArchitectureExplorer.js` | Estado de navegación y selección |
| `ArchitectureAtlasController` + ruta + gating | Sin tocar |

### Se re-estiliza (misma responsabilidad, nueva piel)

`ArchitectureToolbar`, `ArchitectureBreadcrumbs`, `ArchitectureDetailPanel`,
`ArchitectureListView`, `ArchitectureLegend`, `Pages/Admin/ArchitectureAtlas/Index.vue`.

### Se reemplaza por completo

`EcosystemScene.vue`, `ApplicationBuilding.vue`, `ApplicationScene.vue`,
`ModuleRoom.vue`, `ConnectionLayer.vue`, `ArchitectureStatusPattern.vue`,
`lib/architectureGeometry.js`, `lib/architectureSceneTokens.js`,
`lib/architectureVisualTokens.js`. Aproximadamente 1.900 líneas.

### Fuera de alcance

- Telemetría, salud en vivo, latencias o tráfico. El Atlas muestra **estado
  documentado**, nunca estado observado. Un indicador "saludable" que no se
  verifica contra nada sería una mentira mantenida por la interfaz.
- Rotación de cámara, vuelo libre o cualquier control 3D adicional.
- Regenerar el manifiesto automáticamente desde los repositorios.
- Cambios en autorización, rutas, API, esquema de base de datos o negocio.

## Arquitectura

El principio de corte es aislar la proyección del significado. El motor no sabe
qué es un módulo; las capas de dominio no saben cómo se proyecta un punto.

```text
manifest (prop Inertia)
  └─ ArchitectureExplorer.vue           orquesta nivel, filtros, selección
       ├─ SceneCanvas.vue               viewport, gestos, foco, accesibilidad
       │    ├─ CampusLayer.vue          4 edificios + bandas + conductos
       │    └─ FloorLayer.vue           N módulos + objetos temáticos
       ├─ ArchitectureListView.vue      ruta equivalente garantizada
       └─ ArchitectureDetailPanel.vue   panel deslizante
```

### El motor de escena — `lib/scene/`

Módulos puros, sin Vue, sin DOM, sin estado global. Es la única pieza
verdaderamente nueva y la única que necesita pruebas numéricas densas.

| Archivo | Responsabilidad única |
|---|---|
| `projection.js` | Convierte un punto del mundo `(x, y, z)` en `(sx, sy, depth)` |
| `camera.js` | Posición, distancia, elevación, desplazamiento con inercia, encuadre de un objetivo |
| `box.js` | Emite las caras visibles de una caja, con culling e iluminación |
| `painter.js` | Ordena primitivas por profundidad antes de emitirlas |
| `lighting.js` | Lambert de una luz direccional fija en el espacio del mundo |
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

Con `depth < 1.3` la primitiva se descarta (plano de recorte cercano).

**Valores por defecto** (validados en prototipo, ajustables en `sceneConfig.js`):
`FOC = 780`, `D = 17` (rango 6–46), `φ = 28°` (rango 16–60), sensibilidad de
arrastre `k = D · 0.00016`, amortiguación de inercia `0.90` por fotograma con
corte en `1e-4`.

**Culling e iluminación.** Una cara lateral con normal `n` es visible cuando su
componente rotada `ny < -0.02`. Su luminancia es
`L = lum · (0.57 + 0.35 · max(0, n·luz))` con la luz normalizada
`(-0.42, -0.58, 0.70)` fija en el espacio del mundo — al desplazarse la cámara la
iluminación permanece coherente, que es lo que da sensación de objeto sólido.

**Orden de pintado.** Painter's algorithm por `yr` del centro de cada entidad.
Las primitivas de una misma entidad (bloque y su objeto temático) se ordenan
entre sí y se emiten como un grupo, lo que evita el entrelazado incorrecto entre
entidades vecinas.

## Cambios al manifiesto y su contrato

Tres campos nuevos, más el reemplazo de `visualLayout`. Todos son de
presentación: **ninguno altera la semántica auditada ni los estados**.

| Ruta | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `modules[].icon` | `string` | sí | Clave del catálogo de objetos temáticos |
| `applications[].layer` | `"cloud" \| "lan" \| "device"` | sí | Banda del suelo |
| `applications[].facadeColumns` | `integer` | no | Columnas de ventanas; se deriva si falta |
| `visualLayout` | objeto | sí | Reemplazado: posiciones en plano continuo |

El catálogo de iconos vive en `lib/scene/iconCatalog.js` como definiciones
declarativas de cajas relativas. El validador exige que todo `modules[].icon`
exista en el catálogo, y que el catálogo no tenga entradas huérfanas.

La altura de cada bloque **se deriva**, no se declara: es función del número de
endpoints, tablas y eventos del módulo. Declararla a mano sería un dato más que
mantener y que se desincronizaría.

**`metadata.schemaVersion` sube a `1.1.0`.** Los cuatro `snapshotRefs` no cambian
en este trabajo: el inventario auditado es el mismo.

## Sistema visual

El Atlas adopta el lenguaje de la aplicación anfitriona. Desaparecen el fondo
oscuro, la retícula tipo plano y la tipografía monoespaciada de los rótulos de
escena (se conserva monoespaciada solo para identificadores técnicos).

- Superficies claras, `slate` para estructura, el rojo de marca reservado a
  acentos y estado activo.
- Estados con la paleta existente: verde implementado, ámbar parcial, gris
  pendiente, azul en revisión, rojo con incidencias.
- Etiquetas **ancladas** al objeto con un conector visible, escaladas por
  profundidad y atenuadas al fondo. Nunca desancladas ni superpuestas al vecino.
- Conductos por tipo de conexión, con el grosor variando por profundidad:
  HTTP gris continuo, WebSocket azul discontinuo largo, mDNS turquesa punteado,
  USB serial naranja mixto, outbox verde discontinuo.

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
El diseño lo asume y responde en dos frentes:

1. **La vista de lista es la ruta garantizada**, no un extra. Toda pregunta que
   el mapa responde, la lista la responde. Esta equivalencia se verifica en
   pruebas, no se declara en documentación.
2. **El mapa también se recorre con teclado.** Edificios y módulos son elementos
   enfocables (`role="button"`, `tabindex="0"`, `aria-label` con nombre y estado
   en texto). Al recibir foco, **la cámara encuadra el objetivo** mediante
   `camera.focusOn(target)`; `Enter` desciende de nivel, `Escape` cierra el panel
   o sube de nivel. El orden de tabulación sigue el orden de lectura de la lista,
   no el orden de pintado.

Con `prefers-reduced-motion: reduce` se desactivan inercia, encuadres animados y
redibujado continuo: la escena se recompone al terminar el gesto.

Contraste mínimo 4.5:1 en todo texto sobre su superficie real, incluidos rótulos
sobre bandas de color.

## Responsive

| Ancho | Comportamiento |
|---|---|
| ≥ 1280 px | Mapa completo, panel deslizante sobre el lienzo |
| 1024–1279 px | Mapa completo, panel ocupa el borde inferior |
| < 1024 px | **Abre en vista de lista.** El mapa queda accesible con un control explícito |

El umbral vive en `sceneConfig.js`. La preferencia del usuario dentro de la
sesión gana sobre el valor por defecto.

## Rendimiento

Es el riesgo técnico principal: el campus completo puede superar las 600
primitivas SVG, y la planta del SaaS con 17 objetos temáticos ronda las 400.

- **No se redibuja por fotograma en reposo.** El bucle solo corre mientras hay
  gesto activo, inercia pendiente o transición de cámara. En reposo, cero
  trabajo.
- Durante el gesto se emite una **escena reducida**: se omiten objetos temáticos
  y rótulos por debajo de un umbral de profundidad, y se restauran al detenerse.
- Presupuesto: **fotograma bajo 16 ms** en el campus completo y en la planta del
  SaaS, en el equipo de referencia del proyecto. Si no se alcanza sin la escena
  reducida, la degradación se documenta en la doc viva.
- Se evita `innerHTML` completo en cada cuadro cuando el perfilado demuestre que
  domina el costo.

## Pruebas

**Se retiran** las suites de la geometría anterior —`architecture-geometry.test.mjs`
(572 líneas) y las partes de `architecture-experience.test.mjs` que afirman sobre
rombos y etiquetas de habitación—. Prueban un contrato que deja de existir.

**Se conservan** `architecture-manifest.test.mjs`, `architecture-graph.test.mjs`,
`architecture-query.test.mjs` y `architecture-runtime.test.mjs`, ampliadas con los
campos nuevos.

**Se añaden**, sobre el motor puro y sin montar componentes:

| Suite | Verifica |
|---|---|
| `scene-projection.test.mjs` | Puntos conocidos proyectan a valores esperados; recorte cercano; monotonía de `depth` |
| `scene-camera.test.mjs` | Desplazamiento, límites, amortiguación de inercia, `focusOn` encuadra dentro del viewport |
| `scene-box.test.mjs` | Culling: exactamente las caras esperadas por azimut; luminancia dentro de rango |
| `scene-painter.test.mjs` | Orden por profundidad; agrupación correcta de entidad y sus objetos |
| `scene-icons.test.mjs` | Todo `modules[].icon` existe; catálogo sin huérfanos |
| `scene-accessibility.test.mjs` | Cada entidad expone rol, `tabindex` y etiqueta textual; el estado es deducible sin color |

`ArchitectureAtlasAccessTest` se conserva, con el ajuste de los conteos que ya
dependen del manifiesto.

## Riesgos y limitaciones

| Riesgo | Mitigación |
|---|---|
| Rendimiento en el campus completo | Escena reducida durante el gesto; sin bucle en reposo; presupuesto explícito |
| 47 iconos asignados a mano | Catálogo cerrado y validado; un icono ausente es error de validación, no fallo silencioso |
| La metáfora envejece con el inventario | La altura se deriva y `visualLayout` se valida contra el conjunto de módulos |
| Deriva del manifiesto frente al código real | **No se resuelve aquí.** El validador sigue comprobando solo coherencia interna; nunca lee el código de los repositorios |
| Percepción de "herramienta lúdica" | Decisión consciente y aceptada: es una herramienta interna de un solo usuario |

Los prototipos que sustentan estas decisiones viven en
`.superpowers/brainstorm/65494-1786066245/` (`campus.html`, `perspectiva.html`,
`isometrica-tematica.html`). Ese directorio está en `.gitignore`; conviene
preservar `campus.html` como referencia antes de limpiar la sesión.

## Criterios de aceptación

- [ ] El campus muestra 4 edificios en sus bandas, con fachadas que reflejan el estado real de sus módulos.
- [ ] La planta muestra los módulos de una aplicación con su objeto temático.
- [ ] Arrastrar desplaza la cámara con perspectiva e inercia; no existe rotación.
- [ ] Los tres modos de lectura cambian los conductos visibles.
- [ ] Búsqueda, filtros, breadcrumbs y URLs compartibles siguen funcionando igual.
- [ ] Cada estado es distinguible sin color.
- [ ] `Tab` recorre edificios y módulos, y la cámara encuadra el elemento enfocado.
- [ ] `prefers-reduced-motion` desactiva inercia y animaciones.
- [ ] Por debajo de 1024 px el Atlas abre en vista de lista.
- [ ] La vista de lista ofrece información equivalente al mapa.
- [ ] Validador y suites en verde; contraste mínimo 4.5:1.
- [ ] Cero migraciones, endpoints, escrituras o cambios de negocio.
- [ ] Sin dependencias nuevas: ni Three.js, ni canvas, ni librerías de escena.
