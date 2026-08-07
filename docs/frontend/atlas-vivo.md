# Atlas vivo del sistema

**Estado:** MVP de lectura implementado el 2026-08-06  
**Audiencia:** exclusivamente usuarios con rol `superadmin`  
**Ruta:** `/admin/arquitectura` (`admin.arquitectura.index`)

## Propósito

El Atlas vivo es un mapa arquitectónico 2.5D para explorar el ecosistema de
Carnicería SaaS. Representa las aplicaciones como edificios, sus módulos como
habitaciones y las relaciones técnicas como conexiones seleccionables. Su
objetivo es explicar qué existe, quién es responsable, dónde vive la fuente de
verdad, qué depende de internet, qué tiene comportamiento local y qué archivos
debe revisar una persona desarrolladora.

Es documentación derivada del código y versionada junto con el SaaS. No es una
fuente de verdad operativa y no participa en autorización, reglas de negocio,
sincronización ni persistencia.

## Acceso y seguridad

La ruta real implementada es `GET /admin/arquitectura`; no existe una ruta
`/admin/atlas`. Está dentro del grupo `/admin` con los middleware de ruta
`auth` y `role:superadmin`. Además atraviesa el grupo `web`, al que
`bootstrap/app.php` agrega `ForcePasswordChange`; por tanto, una cuenta marcada
para cambiar su contraseña debe resolver ese flujo antes de usar el Atlas.

El enlace **Atlas del sistema** solo se encuentra en `AdminLayout.vue`. Los
layouts de empresa, sucursal y caja no ofrecen ese enlace. Las cuentas
`admin-empresa`, `admin-sucursal` y `cajero` reciben `403`; una visita anónima
se redirige al inicio de sesión.

El manifiesto contiene detalles técnicos que no deben quedar en el bundle
público. Se mantiene esta invariante:

1. `ArchitectureAtlasController` lee el JSON desde `resources/` en el servidor,
   después de superar autenticación y rol.
2. El controlador lo entrega como prop de la página Inertia autorizada.
3. Vue consume esa prop; ningún componente importa directamente el JSON.
4. `system-architecture.json` y sus identificadores privados no deben aparecer
   en `public/build/assets`.

## Estructura de implementación

El flujo de renderizado es:

```text
GET /admin/arquitectura
  → ArchitectureAtlasController
  → prop Inertia `manifest`
  → Pages/Admin/ArchitectureAtlas/Index.vue
  → ArchitectureExplorer.vue
  → mapa o lista + panel de detalle
```

| Pieza | Responsabilidad |
|---|---|
| `app/Http/Controllers/Admin/ArchitectureAtlasController.php` | Lee y decodifica el manifiesto en el servidor y renderiza la página Inertia. |
| `resources/js/Pages/Admin/ArchitectureAtlas/Index.vue` | Integra el Atlas en `AdminLayout` y recibe la prop protegida. |
| `ArchitectureExplorer.vue` | Orquesta nivel, foco, filtros, representación, escenas, detalle y fallback de manifiesto inválido. |
| `EcosystemScene.vue` / `ApplicationBuilding.vue` | Renderizan el campus y los edificios SVG seleccionables. |
| `ApplicationScene.vue` / `ModuleRoom.vue` | Renderizan la planta técnica, habitaciones y gateways entre aplicaciones. |
| `ConnectionLayer.vue` | Dibuja las conexiones visibles según tipo y modo de lectura. |
| `ArchitectureToolbar.vue` | Búsqueda, filtros por estado y conexión, modos y selector mapa/lista. |
| `ArchitectureListView.vue` | Alternativa accesible con información equivalente para recorrer aplicaciones y módulos. |
| `ArchitectureDetailPanel.vue` | Presenta estado, evidencia, autoridad, conectividad, endpoints, eventos, tablas, dependencias, controles, riesgos y archivos. |
| `ArchitectureBreadcrumbs.vue` / `ArchitectureLegend.vue` | Navegación por niveles y explicación no dependiente únicamente del color. |
| `useArchitectureExplorer.js` | Estado reactivo, búsqueda combinada, selección y sincronización segura con la URL. |
| `architectureGraph.js` | Índices, adyacencias, búsqueda técnica, conexiones visibles y URLs de fuente seguras. |
| `architectureQuery.js` | Lee y serializa `app`, `module`, `mode` y `view` en el query string. |
| `architectureGeometry.js` y archivos `*Tokens.js` | Geometría determinista y vocabulario visual compartido. |
| `architectureRuntime.js` | Valida la forma mínima en ejecución y evita romper la vista ante una prop inválida. |

Los componentes Vue usan Composition API con `<script setup>`, Tailwind y SVG;
el MVP no incorpora Three.js, canvas ni imágenes estructurales.

## Manifiesto versionado

El catálogo vive en:

```text
resources/js/Features/Architecture/data/system-architecture.json
```

Su contrato JSON Schema vive junto a él en
`system-architecture.schema.json`. La versión vigente es `1.0.0`. Las raíces
requeridas son:

```text
metadata, statuses, repositories, applications, modules, components,
connections, dataSources, endpoints, events, databaseTables, devices,
dependencies, permissions, featureFlags, sourceFiles, risks, evidence,
visualLayout
```

Cada entidad tiene un `id` estable. Las relaciones se expresan con esos IDs,
por ejemplo `applicationId`, `responsibleApplicationId`, `sourceOfTruthIds`,
`endpointIds`, `eventIds`, `sourceFileIds` y `evidenceIds`. Cambiar un ID rompe
referencias históricas, enlaces compartidos y el layout; una actualización
normal debe conservarlo.

`metadata.snapshotRefs` fija los commits auditados de los cuatro repositorios y
`metadata.verifiedAt` registra la fecha de la última revisión. El snapshot
actual contiene 4 aplicaciones, 47 módulos y 20 conexiones.

### Estados y evidencia

| ID | Uso |
|---|---|
| `implemented` | La capacidad fue verificada en el snapshot auditado. |
| `partial` | Existe, pero su cobertura o integración es incompleta. |
| `pending` | No está implementada. |
| `in-review` | Hay trabajo que aún requiere consolidación o validación. |
| `issues` | Existe, pero presenta un problema conocido. |
| `requires-review` | La evidencia disponible no permite concluir. |
| `unknown` | No se encontró evidencia suficiente para determinar el estado. |
| `not-responsible` | La capacidad pertenece deliberadamente a otra aplicación. |

El estado de módulos y conexiones incluye `determination`, `confidence` y
`evidenceIds`. Todo estado concluyente debe apuntar a evidencia válida. La
evidencia identifica archivo, símbolo, commit, fecha, confianza y resumen.

No se infieren estados por parecido de nombres ni por intención de producto.
Si el código y su documentación no permiten concluir, se usa
`requires-review` o `unknown`. Una función implementada pero desactivada por un
feature flag sigue siendo `implemented`; su disponibilidad se declara aparte
mediante `featureFlagIds` y el valor predeterminado del flag. Del mismo modo,
`internetRequirement`, `offlineCapability` y `syncProfile` describen operación
y conectividad sin alterar el estado de implementación.

### Conexiones y modos de lectura

`connections[].kind` distingue `http`, `websocket`, `polling`, `ipc`,
`usb-serial`, `mdns`, `database`, `sync-outbox`, `cache` y `external-link`.
Cada conexión puede declarar dirección, autenticación, requisito de conexión,
durabilidad, endpoints, eventos y evidencia.

`viewModes` decide en qué lectura aparece una conexión:

| Modo | Pregunta que responde |
|---|---|
| `dependencies` | ¿Qué necesita el componente para funcionar? |
| `data` | ¿Dónde se origina, persiste y consume la información? |
| `sync` | ¿Qué se conserva localmente y cuándo viaja al backend? |

## Enlaces a código auditado

Los archivos relevantes no abren rutas locales. `buildSourceUrl()` combina el
`webUrl` HTTPS de GitHub del repositorio, su `commit` auditado y la ruta relativa
de `sourceFiles` para construir enlaces del tipo:

```text
https://github.com/{organización}/{repositorio}/blob/{commit}/{ruta}
```

La función rechaza hosts distintos de GitHub, credenciales, query strings,
fragments y segmentos de ruta `.` o `..`. Al actualizar el catálogo debe
actualizarse el commit del repositorio y la evidencia correspondiente; nunca se
debe sustituir por una referencia móvil como `main`.

## Capacidades del MVP

- Campus general con las cuatro aplicaciones y conexiones principales.
- Navegación lógica ecosistema → aplicación → módulo mediante mapa, lista,
  breadcrumbs y teclado.
- Habitaciones para los 47 módulos y panel lateral de detalle técnico.
- Búsqueda sobre módulos, aplicaciones, endpoints, eventos, tablas y archivos.
- Filtros combinables por aplicación, estado y tipo de conexión.
- Lecturas de dependencias, flujo de datos y sincronización.
- Estado visual con texto, borde y trama, además de leyenda.
- Información de autoridad, internet/offline, capacidades, riesgos, permisos,
  flags, dispositivos y evidencia.
- URLs compartibles mediante parámetros de navegación válidos.

## Límites del MVP

- Es exclusivamente de lectura: no crea, edita ni elimina datos o
  configuraciones del negocio.
- No agrega endpoints API, tablas, modelos ni una fuente de verdad paralela.
- El catálogo se actualiza manualmente; todavía no existe un analizador que lo
  regenere desde los repositorios.
- Representa el snapshot declarado, no el estado en tiempo real de procesos o
  dispositivos.
- La vista 2.5D prioriza escritorio. En tablet se conserva y se recomienda la
  vista de lista para un recorrido más cómodo.
- Los enlaces a GitHub requieren acceso al repositorio correspondiente.

## Actualización manual del catálogo

Cuando cambie cualquiera de `carniceria-saas`, `carniceria-hub`, `bascula` o
`bascula-android`, se debe revisar el Atlas completo, aunque el cambio parezca
afectar una sola aplicación. El procedimiento es obligatorio y mantiene una
fotografía consistente entre repositorios:

1. Fijar `HEAD` de los cuatro repositorios.
2. Inspeccionar código y documentación relevante.
3. Actualizar entidades y evidencia conservando IDs estables.
4. Ejecutar `npm run validate:architecture`.
5. Ejecutar `npm run test:architecture`.
6. Revisar visualmente las tres vistas de conexión.
7. Registrar `metadata.verifiedAt` y los nuevos `snapshotRefs`.

En el paso 2 se revisan, según corresponda, rutas, controladores, servicios,
modelos, migraciones, tablas, eventos, listeners, jobs, clientes HTTP,
persistencia local, IPC, hardware y documentación viva. En el paso 3 también se
actualizan capacidades implementadas y pendientes, fuentes de verdad,
conectividad, flags, permisos, riesgos y `visualLayout` cuando cambia el
inventario de módulos.

Antes de aceptar una actualización se ejecuta además:

```bash
php artisan test --filter=ArchitectureAtlasAccessTest
npm run validate:architecture
npm run test:architecture
npm run build
./vendor/bin/pint --dirty
git diff --check
```

También se debe comprobar que ningún identificador único del manifiesto esté
embebido en `public/build/assets` y auditar que el cambio no introduzca
escrituras, contratos API, esquema de datos o lógica de sincronización.

La revisión visual del paso 6 se realiza manualmente en un navegador con una
cuenta `superadmin`: se recorren campus, aplicaciones, módulos, vista de lista,
panel, búsqueda, filtros y los modos Dependencias, Flujo de datos y
Sincronización en escritorio y tablet. También se comprueba `403` con
`admin-empresa`, `admin-sucursal` y `cajero`. En la sesión de cierre inicial la
integración de navegador no estuvo disponible por un problema de inicialización
del entorno; por ello esa matriz visual queda como comprobación manual y no se
declara ejecutada.

