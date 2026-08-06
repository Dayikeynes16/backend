# Atlas vivo del sistema

**Estado:** Diseño aprobado; implementación pendiente  
**Fecha:** 2026-08-06  
**Aplicación anfitriona:** `carniceria-saas`  
**Audiencia inicial:** exclusivamente usuarios con rol `superadmin`  
**Tipo de herramienta:** documentación interactiva de solo lectura

## Resumen ejecutivo

El Atlas vivo del sistema será una herramienta visual para documentar y
explorar el ecosistema completo de Carnicería SaaS. No será un dashboard de
negocio ni una ilustración estática: representará las aplicaciones, módulos,
servicios, fuentes de datos y dispositivos como un campus isométrico 2.5D
compuesto por edificios, plantas y habitaciones seleccionables.

La herramienta se integrará dentro de `carniceria-saas` y cargará toda su
información desde un manifiesto versionado llamado
`system-architecture.json`. El manifiesto será documentación derivada del
código; nunca será fuente de verdad del negocio ni se consultará para decidir
autorizaciones, sincronización o comportamiento operativo.

El primer MVP será exclusivamente de lectura. No añadirá tablas, modelos,
mutaciones, acciones administrativas ni contratos API. La única superficie
HTTP nueva será una página Inertia bajo `/admin`, protegida por `auth` y
`role:superadmin`.

## Decisiones aprobadas

1. El Atlas vive dentro de `carniceria-saas`.
2. Solo lo puede abrir el administrador global del SaaS (`superadmin`).
3. Ningún `admin-empresa`, `admin-sucursal` o `cajero` puede ver la ruta ni el
   enlace de navegación.
4. El nombre de trabajo es **Atlas del sistema**.
5. La metáfora es un campus operativo isométrico 2.5D.
6. La implementación usa Vue 3, Inertia, Tailwind, SVG y HTML accesible.
7. No se usará Three.js, canvas ni imágenes generadas como estructura de la
   interfaz.
8. La vista de lista accesible es parte obligatoria del MVP.
9. Los estados deben incluir evidencia; ante incertidumbre se usa
   `requires-review` o `unknown`.
10. Una capacidad implementada pero apagada por feature flag sigue siendo
    `implemented`, con su disponibilidad declarada por separado.
11. Una función que deliberadamente pertenece a otra aplicación se marca como
    `not-responsible`, no como pendiente.
12. Los enlaces de código apuntarán a GitHub fijados al commit auditado; no se
    expondrán rutas locales absolutas al usuario final.

## Alcance de la auditoría

La propuesta se basa en inspección directa de los cuatro repositorios del
workspace, sus rutas, controladores, servicios, modelos, migraciones, eventos,
clientes API, mecanismos locales y documentación viva.

### Fotografía de repositorios

| Repositorio | Commit auditado | Nota |
|---|---|---|
| `carniceria-saas` | `098daec985d4baac0627da9e3552bf498c26f76a` | Rama `feat/caja-pago-link-venta`, un commit delante de `origin/main` |
| `carniceria-hub` | `cd663a6b97551c212aaf3671cbb02c2660090d5b` | Rama concurrente `feat/emparejamiento-basculas`; no es aún `main` |
| `bascula` | `a5a54da567b3048a2026e682f51a2dd0d425b3b2` | Rama `main` |
| `bascula-android` | `8828dae77ba5ad8f5e4baa26e5b01685ab52f70b` | Rama `main` |

La rama de emparejamiento del Hub avanzó durante la auditoría. En el snapshot
final contiene migración SQLite, repositorio de dispositivos, endpoints,
autenticación por token individual, IPC y vista de administración del Hub. El
flujo extremo a extremo continúa **en revisión** porque Android aún no contiene
el cliente de emparejamiento y la rama no está consolidada en `main`.

## Inventario de aplicaciones

| Aplicación | Responsabilidad real | Fuente de verdad | Conectividad y comportamiento local |
|---|---|---|---|
| `carniceria-saas` | Administración, dominio de negocio, APIs, autenticación, realtime, métricas y asistentes | PostgreSQL y reglas de dominio Laravel | Es la autoridad central. Los clientes requieren conexión para la mayoría de operaciones |
| `carniceria-hub` | Cliente de sucursal, API LAN para básculas, catálogo local y cola de ventas de báscula | Temporalmente SQLite para ventas aún no sincronizadas; después PostgreSQL | Offline parcial: solo la recepción/sincronización de ventas de báscula está encolada |
| `bascula` | Kiosco web de referencia | No conserva datos de negocio autoritativos | Requiere API disponible. Usa una báscula simulada y no tiene cola offline |
| `bascula-android` | Terminal productiva de pesaje con USB serial | Peso físico durante la captura; PostgreSQL después de registrar la venta | Puede operar contra nube o Hub. El modo offline durable depende del Hub |
| Dispositivos físicos | Peso, impresión, cámara y potenciales lectores | El dispositivo es fuente de la medición, no del registro comercial | USB serial, cámara, sistema de impresión o conectividad LAN |
| Servicios externos | IA, mapas, almacenamiento, actualización y enlaces de comunicación | Nunca son autoridad del negocio | Requieren internet y configuración externa |

### Hallazgo sobre inventario

Productos, categorías y presentaciones están implementados. No se localizaron
tablas, movimientos, existencias o almacenes que constituyan un módulo real de
inventario/stock. El Atlas debe mostrar inventario como `pending`, no como
implementado por asociación con productos.

## Inventario de módulos

### `carniceria-saas`

| Módulo | Estado auditado | Observaciones |
|---|---|---|
| Autenticación, roles y multi-tenancy | `implemented` | Cuatro roles, resolución de tenant, alcance global y pertenencia |
| Empresas, sucursales, usuarios y configuración | `implemented` | Incluye branding, configuración operativa y tickets |
| Productos, categorías y presentaciones | `implemented` | Consumido también por básculas |
| Inventario/stock | `pending` | No se localizaron existencias, movimientos o almacenes reales |
| Ventas y mesa de trabajo | `implemented` | Edición, estados, locking, historial y cancelaciones |
| Pagos y comprobantes | `implemented` | Recalculados centralmente por `SalePaymentService` |
| Caja, turnos, cortes y retiros | `implemented` | Conciliación por método de pago |
| Clientes, fiado y precios preferenciales | `implemented` | Cobro global FIFO |
| Gastos | `implemented` | Categorías, adjuntos y captura asistida |
| Compras, proveedores y cuentas por pagar | `implemented` | Folios, pagos FIFO y catálogos |
| Métricas y reportes | `implemented` | Nueve ejes y resumen de utilidad |
| Agenda | `implemented` con realtime parcial | Existe evento de asignación; la interfaz principal continúa usando polling |
| Asistente conversacional | `partial` | Núcleo, tools, borradores y confirmación existen; la documentación de voz/TTS es contradictoria |
| Pedidos web | `implemented`, desactivado | Código existente, feature flag global apagado por defecto |
| Realtime | `partial` | Buena cobertura en ventas; otros módulos no consumen eventos |
| API para básculas | `implemented` con riesgo | No se encontró idempotencia efectiva para reintentos directos desde la nube |
| API para Hub | `implemented` | Operaciones online amplias con Sanctum |
| API pública | `implemented`, desactivado | Depende del flag de pedidos web |

### `carniceria-hub`

| Módulo | Estado auditado | Observaciones |
|---|---|---|
| Shell Electron y seguridad IPC | `implemented` | `contextIsolation`, preload explícito y tokens fuera del renderer |
| Flujos compartidos con el web | `implemented` en modo online | Ventas, caja, historial, pagos, clientes, gastos, compras y proveedores |
| API local para básculas | `implemented` | Expone sucursal, catálogo, registro y consulta de ventas |
| SQLite y migraciones | `implemented` | WAL, migraciones incrementales y respaldo previo |
| Cola de salida | `partial` | Solo cubre ventas originadas en básculas |
| Catálogo local | `implemented` como caché | No es fuente de verdad |
| Realtime | `partial` | Echo/Reverb para ventas con polling de respaldo |
| Impresión | `partial` | Diálogo del sistema; no hay administración completa de impresoras |
| Descubrimiento mDNS | `implemented` | Publica el Hub en la red local |
| Emparejamiento individual de básculas | `in-review` | Hub implementado en rama; Android y consolidación pendientes |
| Gestión de usuarios | `not-responsible` | Vive intencionalmente en el web |

### `bascula`

| Módulo | Estado auditado | Observaciones |
|---|---|---|
| Configuración por QR/manual | `implemented` | URL, clave y dispositivo en `localStorage` |
| Catálogo y creación de venta | `implemented` contra API central | Requiere conectividad |
| Lectura de báscula | `partial` | Solo existe `MockScaleAdapter` |
| Operación vía Hub | `issues` | La UI espera `201`; el Hub devuelve `202` y seguimiento asíncrono |
| Idempotencia y reintentos | `pending` | No envía `client_reference` ni consulta estado |
| Offline | `pending` | No existe outbox ni catálogo durable |
| Pruebas automatizadas | `unknown` | No se encontró suite significativa |

### `bascula-android`

| Módulo | Estado auditado | Observaciones |
|---|---|---|
| USB serial | `implemented` | CH34x, FTDI, CP210x, Prolific y CDC |
| Captura de peso | `implemented` | Reconexión, permisos y parsing de formatos habituales |
| Configuración QR/manual | `implemented` | Escaneo y selección del servidor |
| Descubrimiento de Hub | `implemented` | mDNS |
| Venta directa a nube | `implemented` sin offline | Depende de conexión; backend sin idempotencia confirmada |
| Venta mediante Hub | `implemented` | Acepta `202` y consulta estado de sincronización |
| Catálogo local durable | `pending` | Se conserva principalmente en memoria |
| Actualización de APK | `implemented` | Descarga, SHA-256 e instalación fuera de Play Store |
| Emparejamiento sin token manual | `pending` | El trabajo auditado existe solo en el Hub |
| Impresoras | `pending` | No se encontró integración |
| Lector de productos/códigos | `requires-review` | Cámara confirmada para QR de configuración, no para productos |

## Mapa de conexiones

```text
                    ┌──────── Servicios externos ────────┐
                    │ OpenAI · Google · Storage · Updates│
                    └────────────────┬────────────────────┘
                                     │ HTTPS
┌──────────────┐    HTTP/Inertia   ┌─▼────────────────────────┐
│ Aplicación   ├──────────────────►│ carniceria-saas          │
│ web          │◄──── Reverb ──────┤ Laravel / APIs / dominio │
└──────────────┘                   └────────────┬──────────────┘
                                               │ SQL
                                      ┌────────▼────────┐
                                      │ PostgreSQL      │
                                      │ Fuente central  │
                                      └─────────────────┘

┌──────────────┐ USB serial  ┌───────────────────┐
│ Báscula      ├────────────►│ Android           │
│ física       │             └──────┬───────┬────┘
└──────────────┘                    │       │
                         HTTP nube  │       │ HTTP LAN
                                    │       ▼
                                    │  ┌────────────────────────┐
                                    └─►│ Hub Electron           │
                                       │ Fastify + SQLite/outbox │
                                       └──────────┬─────────────┘
                                                  │ sincronización
                                                  ▼
                                          API central SaaS

┌──────────────┐
│ Báscula web  ├──────────── HTTP/X-Api-Key ─────► SaaS
└──────────────┘
```

### Tipos de conexión visual

| Tipo | Representación |
|---|---|
| HTTP/API | Camino sólido con dirección |
| WebSocket/Reverb | Línea luminosa continua |
| Polling | Línea segmentada con pulsos discretos |
| Outbox/sincronización | Cinta de paquetes o contenedores |
| USB serial | Cable corto de cobre |
| IPC Electron | Conducto interior del edificio |
| SQL | Elevador o conducto hacia la bóveda |
| mDNS | Ondas punteadas locales |
| Servicio externo | Puente que sale del campus |

## Flujos de datos

### Venta directa desde Android

1. La báscula física entrega el peso por USB.
2. Android construye la venta.
3. Envía `POST /api/v1/sales` con `X-Api-Key`.
4. Laravel valida productos y precios actuales.
5. PostgreSQL registra una venta activa y no pagada.
6. `NewExternalSale` avisa a las interfaces.
7. Web y Hub recargan la cola.

El `payment_method` enviado por una báscula no equivale a registrar un pago.

### Venta mediante Hub sin internet

1. Android envía la venta a la API LAN.
2. El Hub la guarda en `outbox_sales`.
3. Responde `202 Accepted`.
4. Android consulta el estado local.
5. El worker del Hub reintenta contra el SaaS.
6. Tras sincronizar, PostgreSQL pasa a ser la autoridad.
7. Reverb propaga la actualización.

Este es el único flujo offline durable confirmado.

### Operación de caja desde Hub

```text
Renderer → IPC → API Hub/Sanctum → transacción Laravel
         → SalePaymentService → PostgreSQL → SaleUpdated
```

No existe cola offline general para pagos, turnos, gastos, clientes, compras o
proveedores.

### Pedido web

```text
Menú público → cotización → pedido origin=web → PostgreSQL
             → mesa de trabajo → vinculación manual
             → venta real de báscula
```

El pedido web es referencia/comanda. La venta real vinculada es la operación
contabilizable.

### Catálogo

```text
PostgreSQL → API Scale → Android en memoria
                      └→ Hub → snapshot SQLite → básculas LAN
```

El snapshot es caché y no puede sustituir al catálogo central.

### Asistente de IA

```text
Texto/voz/imagen → OpenAI → propuesta estructurada → borrador
                 → confirmación humana → revalidación Laravel
                 → servicio de dominio → PostgreSQL
```

La IA no es autoridad, no decide permisos y no escribe sin confirmación.

## Fuentes de verdad

| Información | Fuente de verdad |
|---|---|
| Negocio, usuarios, sucursales, ventas, pagos, compras y configuración | PostgreSQL |
| Reglas y transiciones del dominio | Laravel |
| Diseño y comportamiento compartido | Frontend de `carniceria-saas` |
| Peso durante la captura | Báscula física |
| Venta todavía no sincronizada | SQLite del Hub, temporalmente |
| Catálogo local del Hub | Caché no autoritativa |
| Configuración local de Android | DataStore |
| Configuración y sesión del Hub | `electron-store` y almacenamiento seguro |
| Configuración de báscula web | `localStorage` |
| Eventos Reverb | Transporte, nunca fuente de verdad |
| Manifiesto del Atlas | Catálogo documental derivado del código |

## Superficies técnicas auditadas

### APIs y autenticación

| Superficie | Rutas principales | Autenticación | Responsable |
|---|---|---|---|
| Web/Inertia | `/admin/*`, `/{tenant}/empresa/*`, `/{tenant}/sucursal/*`, `/{tenant}/caja/*`, `/{tenant}/asistente`, `/{tenant}/agenda` | Sesión web + roles + tenant | SaaS |
| API de básculas | `/api/v1/branches/me`, `/categories`, `/products`, `/sales` | `X-Api-Key`, branch-scoped | SaaS |
| API de Hub | `/api/v1/hub/*` | Sanctum + `hub.role` | SaaS |
| API pública | `/api/public/{tenantSlug}/*` | Pública, throttle y honeypot | SaaS |
| API LAN del Hub | `/api/v1/branches/me`, `/categories`, `/products`, `/sales` | Token local compartido o token individual de dispositivo en la rama auditada | Hub |

La API de Hub cubre dashboard, realtime, configuración, claves, turnos,
retiros, pagos, clientes, precios, catálogo, gastos, compras, proveedores,
ventas, locking, cancelaciones, items y comprobantes. Las operaciones de esa
superficie son online; no se deben confundir con la cola local de ventas de
báscula.

### Eventos

| Evento | Canal | Emisor/uso auditado |
|---|---|---|
| `NewExternalSale` | `sucursal.{branchId}` | Nueva venta de báscula o pedido externo; web y Hub recargan cola |
| `SaleUpdated` | `sucursal.{branchId}` | Cambios de venta/pago; consumidores recargan estado |
| `SaleLocked` | `sucursal.{branchId}` | Lock de edición |
| `SaleUnlocked` | `sucursal.{branchId}` | Liberación del lock |
| `AgendaItemAssigned` | `agenda.user.{userId}` | Emitido por agenda; consumo frontend no confirmado |

Todos son `ShouldBroadcastNow`. Reverb notifica; PostgreSQL conserva el estado.

### Persistencia central

Las familias de tablas detectadas incluyen:

- tenants, branches, users, roles y tokens;
- api keys;
- categories, products y product presentations;
- sales, sale items, payments y payment receipts;
- cash register shifts y cash withdrawals;
- customers, preferential prices, customer payments y receipts;
- expense categories/subcategories, expenses y attachments;
- providers, purchases, purchase items, provider payments y attachments;
- purchase products y categorías;
- agenda items;
- sesiones, mensajes y borradores de IA;
- assistant drafts;
- audit logs, Sanctum y tablas de Spatie Permission.

No se detectaron tablas reales de existencias, almacenes o movimientos de
inventario.

### Archivos representativos de evidencia

| Repositorio | Archivo | Papel |
|---|---|---|
| SaaS | `routes/web.php` | Jerarquía web y roles |
| SaaS | `routes/api.php` | Superficies Scale, Hub y pública |
| SaaS | `app/Http/Controllers/Api/SaleController.php` | Alta de venta desde báscula |
| SaaS | `app/Services/SalePaymentService.php` | Recalculo y transición por pagos |
| SaaS | `app/Services/OrderLinkService.php` | Vinculación pedido web ↔ venta real |
| Hub | `src/main/server/localApiServer.js` | API LAN y autenticación de básculas |
| Hub | `src/main/db/migrations.js` | Esquema SQLite incremental |
| Hub | `src/main/db/outboxRepo.js` | Cola durable de ventas |
| Hub | `src/main/auth/authService.js` | Sesión central del Hub |
| Hub | `src/main/sync/backendClient.js` | Cliente de sincronización central |
| Báscula web | `src/views/DashboardView.vue` | Operación del kiosco |
| Báscula web | `src/services/api.js` | Contrato HTTP usado por el kiosco |
| Báscula web | `src/adapters/MockScaleAdapter.js` | Hardware simulado |
| Android | `app/src/main/java/com/bascula/app/scale/UsbScaleAdapter.kt` | USB serial real |
| Android | `app/src/main/java/com/bascula/app/data/Api.kt` | Cliente Scale/Hub |
| Android | `app/src/main/java/com/bascula/app/discovery/HubDiscovery.kt` | mDNS |
| Android | `app/src/main/java/com/bascula/app/ui/pos/PosViewModel.kt` | Orquestación de venta y polling |

El manifiesto debe convertir estos paths en `sourceFiles` y fijarlos al commit
de su repositorio.

## Servicios e integraciones externas

- OpenAI para conversación, visión y transcripción.
- Google Maps/Distance Matrix para ubicación y entrega.
- almacenamiento S3-compatible para adjuntos y activos configurados.
- enlaces `wa.me` para handoff a WhatsApp; no se confirmó una integración de
  servidor con la API de WhatsApp.
- distribución externa del APK Android con verificación SHA-256.
- Redis y Reverb como infraestructura, no como fuentes de verdad.

## Riesgos técnicos detectados en la auditoría

1. **Idempotencia directa de básculas.** Android envía un
   `client_reference`, pero el controlador central de básculas no parece usarlo
   para evitar duplicados.
2. **Compatibilidad `bascula`–Hub.** La báscula web espera creación inmediata;
   el Hub usa aceptación `202` y consulta posterior.
3. **Offline del Hub sobreestimado.** La cola cubre ventas de báscula, no todas
   las operaciones del Hub.
4. **Restauración de sesión offline del Hub.** El flujo actual puede borrar la
   sesión cacheada cuando `/me` falla por red. Debe reproducirse antes de
   clasificarlo como problema confirmado.
5. **Configuración de sincronización del Hub.** Algunos clientes reciben URL y
   clave al arrancar; un cambio podría requerir reinicio.
6. **Crecimiento local.** No se encontró una política completa para depurar
   ventas sincronizadas, snapshots o respaldos SQLite.
7. **Seguridad LAN.** La API local usa HTTP. El token individual limita el
   alcance del secreto, pero no cifra el transporte.
8. **Realtime desigual.** Ventas tiene buena cobertura; otros módulos usan
   polling.
9. **Documentación divergente.** Hay contradicciones en estado inicial de
   ventas, alcance offline, roles y TTS.
10. **Trabajo concurrente.** El emparejamiento vive en una rama y no representa
    todavía el estado consolidado de producción.

Estos hallazgos se registran como riesgos o conflictos de evidencia en el
manifiesto. No se convierten silenciosamente en estados concluyentes.

## Metáfora visual

### Campus operativo seccionado

- **Edificio central — SaaS**
  - Planta pública: web, autenticación y administración.
  - Planta operativa: ventas, caja, clientes, compras y gastos.
  - Planta técnica: APIs, servicios y realtime.
  - Sótano: PostgreSQL.
  - Cuarto de infraestructura: Redis y Reverb.
- **Edificio de sucursal — Hub**
  - Sala operativa: renderer Vue.
  - Pasillo interno: IPC.
  - Andén de recepción: API Fastify.
  - Almacén temporal: SQLite y outbox.
  - Sala de comunicaciones: sincronización, mDNS y Reverb.
- **Estación productiva de pesaje — Android**
  - Terminal Android, báscula USB, cámara y enlaces LAN/nube.
- **Estación de referencia — báscula web**
  - Zona menor, identificada explícitamente como referencia.
- **Anexos externos**
  - OpenAI, Google, almacenamiento y distribución de APK.

El zoom es lógico, no una cámara tridimensional continua:

1. Campus completo.
2. Edificio abierto por plantas y habitaciones.
3. Panel técnico del módulo.

### Alternativas descartadas para el MVP

| Alternativa | Ventaja | Motivo para no elegirla ahora |
|---|---|---|
| Aplicación independiente | Independencia y escaneo sencillo de repos | Introduce una quinta app, despliegue y autorización propios |
| Sitio estático generado | Documentación simple y portable | Pierde integración, control de acceso y continuidad visual |
| Three.js/3D real | Mayor profundidad visual | Más peso, interacción más compleja y peor accesibilidad |

## Niveles de navegación

### Nivel 1 — Ecosistema

Muestra aplicaciones, servicios, fuentes de datos, dispositivos físicos y
conexiones principales.

### Nivel 2 — Aplicación

Abre el edificio seleccionado y muestra sus módulos como habitaciones o zonas.

### Nivel 3 — Detalle técnico

El panel muestra:

- nombre, descripción y estado;
- aplicación responsable;
- fuentes de verdad;
- rutas y endpoints;
- controladores, servicios y modelos;
- tablas;
- eventos emitidos y escuchados;
- jobs y procesos;
- dependencias;
- permisos y feature flags;
- mecanismos de sincronización;
- capacidad online/offline;
- archivos y enlaces al código;
- capacidades implementadas y pendientes;
- riesgos, conflictos de evidencia y fecha de verificación.

## Interacciones del MVP

- Seleccionar edificios y habitaciones.
- Navegar por breadcrumb entre los tres niveles.
- Buscar aplicaciones, módulos, endpoints, modelos, eventos y archivos.
- Filtrar por aplicación, estado y tipo de conexión.
- Activar vistas de dependencias, flujo de datos y sincronización.
- Resaltar conexiones del elemento seleccionado.
- Abrir archivos relacionados mediante enlaces al repositorio.
- Consultar una leyenda de colores y trazos.
- Cambiar a una vista de lista completa.
- Compartir una URL que conserve aplicación, módulo y modo seleccionado.

## Manifiesto `system-architecture.json`

### Estructura raíz

```json
{
  "$schema": "./system-architecture.schema.json",
  "metadata": {
    "schemaVersion": "1.0.0",
    "verifiedAt": "2026-08-06",
    "snapshotRefs": {},
    "defaultView": "ecosystem",
    "caveats": []
  },
  "statuses": [],
  "repositories": [],
  "applications": [],
  "modules": [],
  "components": [],
  "connections": [],
  "dataSources": [],
  "endpoints": [],
  "events": [],
  "databaseTables": [],
  "devices": [],
  "dependencies": [],
  "permissions": [],
  "featureFlags": [],
  "sourceFiles": [],
  "risks": [],
  "evidence": [],
  "visualLayout": {
    "buildings": [],
    "rooms": [],
    "connectionRoutes": []
  }
}
```

### Contrato mínimo de un módulo

```json
{
  "id": "hub.sync.scale-sales",
  "applicationId": "app.hub",
  "name": "Sincronización de ventas de báscula",
  "description": "Conserva ventas locales y las reintenta contra la API central.",
  "status": {
    "id": "partial",
    "determination": "verified",
    "confidence": "high",
    "evidenceIds": [
      "ev.hub.outbox-repository",
      "ev.hub.sync-worker"
    ]
  },
  "responsibleApplicationId": "app.hub",
  "sourceOfTruthIds": [
    "data.hub.sqlite.unsynced-sales",
    "data.saas.postgresql.sales"
  ],
  "internetRequirement": {
    "requiredForCapture": false,
    "requiredForFinalSync": true
  },
  "syncProfile": {
    "kind": "outbox",
    "durable": true,
    "scope": "scale-sales"
  },
  "implementedCapabilities": [],
  "pendingCapabilities": [],
  "endpointIds": [],
  "dependencyIds": [],
  "sourceFileIds": [],
  "riskIds": []
}
```

### Reglas del manifiesto

- Los IDs son estables y no dependen de la etiqueta visible.
- Todo estado concluyente tiene evidencia.
- La evidencia registra archivo, símbolo, commit, fecha y confianza.
- Los conflictos entre documentación y código se conservan.
- El layout manual no se deriva del orden de las entidades.
- Las relaciones deben pasar validación de integridad referencial.
- El MVP usa un solo manifiesto; si la automatización futura crece, el layout
  podrá extraerse sin cambiar los IDs semánticos.
- Un analizador futuro genera un archivo detectado o un diff. Nunca sobrescribe
  directamente descripciones, estado o layout curados.

## Arquitectura técnica del MVP

### Ruta y autorización

La página se sirve mediante:

```text
GET /admin/arquitectura    admin.arquitectura.index
```

La ruta vive dentro del grupo ya existente:

```php
Route::prefix('admin')
    ->middleware(['auth', 'role:superadmin'])
```

No existe variante tenant ni ruta bajo `empresa`, `sucursal` o `caja`. La
autorización se prueba en servidor; ocultar el enlace no es el control de
acceso.

### Carga de datos

El manifiesto se importa en el bundle de Vite. El controlador Inertia no lee
PostgreSQL ni entrega datos de arquitectura desde una API. La herramienta es
reproducible a partir del commit de código.

### Componentes

```text
Admin/ArchitectureAtlas/Index.vue
└── ArchitectureExplorer.vue
    ├── ArchitectureBreadcrumbs.vue
    ├── ArchitectureToolbar.vue
    ├── EcosystemScene.vue
    │   ├── ApplicationBuilding.vue
    │   └── ConnectionLayer.vue
    ├── ApplicationScene.vue
    │   ├── ModuleRoom.vue
    │   └── ConnectionLayer.vue
    ├── ArchitectureListView.vue
    ├── ArchitectureDetailPanel.vue
    └── ArchitectureLegend.vue
```

La página Inertia es una superficie de composición. El estado, filtrado,
búsqueda y grafo viven en composables y funciones puras, no dentro de una mega
plantilla.

### Tecnología visual

- SVG responsivo para edificios y conexiones.
- HTML semántico para toolbar, búsqueda, filtros, paneles y lista.
- Botones o grupos SVG enfocados por teclado con nombre accesible.
- `vector-effect="non-scaling-stroke"` para conexiones.
- CSS transforms limitados a la ilusión isométrica.
- Sin render loop ni animaciones permanentes.
- Modo de movimiento reducido respetado.
- Panel lateral en escritorio y panel inferior en tablet.

### Búsqueda y filtros

Al cargar el manifiesto se construyen:

- mapas `id → entidad`;
- módulos agrupados por aplicación;
- adyacencia de conexiones;
- índice normalizado de términos buscables.

La búsqueda inicial no requiere una dependencia externa. Se normaliza a
minúsculas y sin diacríticos, y se consulta sobre nombre, descripción, IDs,
rutas, símbolos y archivos.

### URL compartible

La selección se conserva en query string:

```text
/admin/arquitectura?app=app.hub&module=hub.sync.scale-sales&mode=sync
```

Los parámetros inválidos se ignoran y la interfaz vuelve al nivel de
ecosistema sin romper la página.

### Apertura de archivos

Cada repositorio declara URL remota y commit. Los enlaces se forman como:

```text
https://github.com/{owner}/{repo}/blob/{commit}/{path}
```

No se usan `file://`, rutas absolutas del equipo ni `vscode://` en producción.

## Accesibilidad y adaptación

- Todos los estados combinan texto, color e icono/patrón.
- Orden de tabulación coherente.
- Foco visible en edificios, habitaciones y controles.
- `Escape` cierra el panel de detalle en móvil/tablet.
- Breadcrumb y encabezados describen el nivel actual.
- Vista de lista funcional sin depender de la metáfora.
- Objetivo táctil mínimo de 44 × 44 px.
- En tablet se evita una cámara con pan obligatorio.
- La herramienta conserva lectura útil cuando SVG no está disponible.

## Rendimiento

- Índice y mapas calculados una vez.
- Conexiones filtradas antes de renderizar.
- Solo se muestran detalles del elemento activo.
- Sin animaciones continuas.
- El manifiesto puede dividirse por aplicación en una versión futura si su
  tamaño afecta el bundle.

## Validación y pruebas

### Backend

- Invitado: redirección a login.
- `superadmin`: respuesta 200 y componente Inertia correcto.
- `admin-empresa`, `admin-sucursal` y `cajero`: 403.
- No existe ruta tenant equivalente.

### Manifiesto

- JSON válido y `schemaVersion` soportada.
- IDs únicos por colección.
- Estados permitidos.
- Referencias existentes.
- Evidencia obligatoria para estados concluyentes.
- Todos los módulos tienen aplicación y layout.
- Todas las conexiones tienen origen, destino y tipo válido.

### Frontend

- Build Vite en verde.
- Pruebas unitarias con `node:test` para funciones puras del grafo, búsqueda y
  filtros; no se añade un framework de pruebas Vue al MVP.
- Recorrido manual por teclado, desktop y tablet.
- Verificación de `prefers-reduced-motion`.

## Fuera de alcance del MVP

- Acciones administrativas o mutaciones.
- Lectura desde PostgreSQL.
- Endpoint JSON de arquitectura.
- Edición del manifiesto desde la interfaz.
- Escaneo automático de repositorios.
- Three.js o escenas 3D.
- Visibilidad para dueños de empresa o personal de sucursal.
- Cambios a contratos API, sincronización o base de datos.
- Resolver los riesgos técnicos auditados.

## Riesgos del Atlas

1. El manifiesto puede quedar obsoleto sin responsable y revisión periódica.
2. Los estados pueden ser subjetivos sin evidencia obligatoria.
3. Una metáfora demasiado literal puede saturar la escena.
4. Rutas, endpoints y archivos son información sensible; por eso el MVP es
   exclusivo de `superadmin`.
5. Los enlaces a líneas envejecen; se prefieren archivo, símbolo y commit.
6. Un analizador no puede decidir por sí solo si una función cumple criterios
   de negocio.
7. Un JSON exhaustivo puede aumentar el bundle.
8. La visualización por color requiere equivalentes textuales.
9. El manifiesto no debe convertirse en configuración operativa paralela.
10. Los repositorios pueden moverse mientras se auditan; cada evidencia debe
    conservar su commit.

## Limitaciones de verificación de la auditoría

- Laravel no pudo ejecutar pruebas porque `php` no estaba disponible en el
  entorno de auditoría.
- Gradle fue bloqueado antes de ejecutar pruebas por el acceso restringido a
  `~/.gradle`.
- En Hub, parte de la suite se cargó correctamente y parte falló antes de
  ejecutar por incompatibilidad ABI entre `better-sqlite3` y Node. No demuestra
  un fallo funcional del producto.
- `bascula` no ofrece cobertura automatizada suficiente para confirmar su
  compatibilidad con Hub.

## Criterios de aceptación del MVP

Una persona con rol `superadmin` puede:

1. Abrir el Atlas desde la navegación de administración.
2. Reconocer las cuatro aplicaciones y la infraestructura principal.
3. Seleccionar una aplicación y ver sus módulos reales.
4. Seleccionar un módulo y consultar estado, responsabilidad, fuente de verdad,
   conectividad, archivos y relaciones.
5. Buscar por módulo, endpoint, modelo, evento o archivo.
6. Filtrar por aplicación, estado y conexión.
7. Cambiar entre dependencias, datos y sincronización.
8. Comprender qué funciona offline y qué requiere backend.
9. Abrir un enlace estable al código auditado.
10. Obtener la misma información mediante la vista de lista y teclado.

Un usuario que no sea `superadmin` recibe 403 aunque conozca la URL. El MVP no
realiza escrituras ni modifica comportamiento del negocio.
