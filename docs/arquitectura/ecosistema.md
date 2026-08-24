# El ecosistema: las cuatro aplicaciones

Fuente de verdad del mapa del ecosistema. El producto no es una sola aplicación: son cuatro programas en cuatro repositorios que se reparten la operación de una carnicería, desde la báscula del mostrador hasta el panel del dueño.

Este documento describe **qué es cada aplicación, cómo se comunican y qué contratos no se pueden romper**. Los detalles de cada módulo viven en su doc propio; aquí solo está lo que se necesita para entender el conjunto.

## Responsabilidades

- Describir las cuatro aplicaciones y el papel de cada una.
- Documentar los caminos por los que se comunican y con qué autenticación.
- Fijar las reglas de compatibilidad entre repos, especialmente las que no se pueden romper.

**No hace:** no documenta el funcionamiento interno de ningún módulo (eso está en `docs/modulos/`), ni el detalle de cada endpoint (eso está en `docs/api/`).

## Las cuatro aplicaciones

| Repo | Qué es | Stack | Corre en |
|------|--------|-------|----------|
| `carniceria-saas` | Aplicación web y backend. El centro del sistema. | Laravel 13 · PHP 8.5 · Vue 3 + Inertia 2 · PostgreSQL 18 · Reverb | Nube (Laravel Cloud) |
| `carniceria-hub` | Hub local de sucursal, *offline-first*. Puente entre básculas y nube. | Electron · Vue 3 + Tailwind · Fastify · SQLite | PC de la sucursal |
| `bascula` | Punto de venta de escritorio con báscula por puerto serie. | Electron · Vue 3 + Pinia + Tailwind · `serialport` | Tablets Surface (Windows) |
| `bascula-android` | Punto de venta Android nativo con báscula por USB. | Kotlin · Jetpack Compose · Retrofit | Tablets Android |

### `carniceria-saas` — la aplicación web

El backend y los paneles de administración. Multi-tenant por columna (`tenant_id` + scope global), con cuatro roles y jerarquía de rutas por prefijo. Es el único que habla con la base de datos de producción y el único que emite eventos de tiempo real.

Ver [multitenant.md](multitenant.md), [roles-permisos.md](roles-permisos.md), [reverb-websockets.md](reverb-websockets.md).

### `carniceria-hub` — el hub de sucursal

Aplicación de escritorio que corre en la sucursal y sostiene la operación cuando la conexión falla. Levanta un **servidor Fastify local en el puerto 4599** al que se conectan las básculas emparejadas, guarda las ventas en una **cola local SQLite (outbox)** y las sincroniza contra la nube cuando hay conexión. Se anuncia por mDNS con el tipo de servicio `carnihub` para que las básculas lo descubran.

Consume la API del hub (`/api/v1/hub/*`) con token Sanctum. Roles soportados: **solo `admin-sucursal` y `cajero`**.

**Dirección visual (decidida 2026-07-07):** la web es la fuente de verdad de UI/UX. El hub debe alcanzar paridad visual y de comportamiento con la web en todos los flujos compartidos; sus superficies exclusivamente locales (dispositivo, conexión, sincronización, básculas, impresoras, setup) se quedan, pero dentro del mismo sistema visual. **Material Design 3 quedó descartado** — no reintroducir `@material/web`.

En el repo del hub: `carniceria-hub/docs/api-local.md` (el contrato con las básculas), `carniceria-hub/docs/sincronizacion.md` (la cola, el envío y la idempotencia), `carniceria-hub/docs/base-de-datos-local.md` (los respaldos) y `carniceria-hub/docs/direccion-visual.md` (el sistema visual).

### `bascula` — el POS de escritorio (Surface)

**Estas tablets sostienen la operación con hardware real.** Lee el peso por puerto serie con `serialport` y un adaptador propio; incluye una vista de diagnóstico para depurar la conexión en sitio.

Consume la **Scale API de la nube directamente** con `X-Api-Key` — no pasa por el hub. Usa el mismo chip CH340 que la app Android, y el instalador trae el driver (`build/drivers/CH341SER/`), instalado por `build/installer.nsh` **solo en instalación nueva**: la macro va detrás de `${ifNot} ${isUpdated}` porque `customInstall` también corre al actualizar y el driver pide elevación, lo que mostraría un UAC en cada auto-update.

> Ya **no** es una implementación de referencia con hardware simulado: `MockScaleAdapter` se eliminó el 2026-08-07.

### `bascula-android` — el POS Android

Aplicación nativa, **no** un envoltorio de WebView. Habla la misma Scale API que `bascula`, con el mismo chip CH340 por USB. Además de la nube, puede **emparejarse con un hub** (descubrimiento mDNS + aprobación en el hub).

## Cómo se comunican

```
   ┌──────────────┐                      ┌──────────────────┐
   │   bascula    │                      │  bascula-android │
   │  (Surface)   │                      │    (tablet)      │
   └──────┬───────┘                      └────┬────────┬────┘
          │                                   │        │
          │ Scale API                         │        │ API local
          │ X-Api-Key                         │        │ (mDNS + token
          │                        Scale API  │        │  por equipo)
          │                        X-Api-Key  │        │
          │                                   │        ▼
          │                                   │   ┌─────────────────┐
          │                                   │   │  carniceria-hub │
          │                                   │   │  Fastify :4599  │
          │                                   │   │  outbox SQLite  │
          │                                   │   └────────┬────────┘
          │                                   │            │ API del hub
          │                                   │            │ Sanctum
          ▼                                   ▼            ▼
   ┌────────────────────────────────────────────────────────────────┐
   │                       carniceria-saas                          │
   │              PostgreSQL · Reverb (tiempo real)                 │
   └────────────────────────────────────────────────────────────────┘
```

Una báscula Android puede hablar con la nube, con el hub, o con ambos según su configuración. Las Surface hablan siempre directo con la nube.

## Superficies de API

Cuatro contratos distintos, con cuatro modelos de autenticación:

| Superficie | Ruta | Auth | Endpoints | Quién la consume |
|-----------|------|------|-----------|------------------|
| **Scale API** | `/api/v1/*` | `X-Api-Key` (SHA-256, por sucursal), 60 req/min | 7 | `bascula`, `bascula-android` |
| **API del hub** | `/api/v1/hub/*` | Sanctum Bearer + middleware `hub.role` | 112 | `carniceria-hub` |
| **API pública** | `/api/public/{tenant}/*` | Sin auth, con throttle y honeypot | 4 | Menú QR (tras `FEATURE_WEB_ORDERS`) |
| **API local del hub** | `:4599/api/v1/*` | Token por equipo emparejado | 8 | Básculas de la sucursal |

Detalle en [endpoints.md](../api/endpoints.md), [hub.md](../api/hub.md) y [autenticacion-apikey.md](../api/autenticacion-apikey.md). La API local del hub está documentada en su propio repo: `carniceria-hub/docs/api-local.md`.

## Reglas de compatibilidad

> ### La Scale API no admite cambios incompatibles
>
> Hay básculas antiguas en producción que **no se auto-actualizan**. Un cambio incompatible en `/api/v1/*` — quitar un campo, volver requerido uno opcional, cambiar un formato de respuesta — deja equipos sin poder vender, y el fallo se detecta en el mostrador, no en las pruebas.
>
> Todo cambio en esa superficie debe ser **aditivo**: campos nuevos opcionales, con valor por defecto para quien no los mande. La garantía que se aplicó al añadir el nombre de venta (2026-08-13) es el precedente: *las básculas viejas no mandan el campo y todo sigue funcionando igual*.

Otras reglas del conjunto:

- **El hub no añade roles ni permisos propios.** Solo `admin-sucursal` y `cajero`, y ningún feature flag más allá de los de la web.
- **Los pagos y las ventas del hub son idempotentes por `client_reference`** — es lo que hace seguro reintentar desde el outbox al reconectar. Sin eso, una venta cuya respuesta se perdía por el camino se creaba dos veces. Ver `carniceria-hub/docs/sincronizacion.md`.
- **Las tres apps cliente se firman y publican por tag `vX.Y.Z`.** La versión sale del `package.json` (o del `build.gradle.kts`), no del tag: hay que bumpearla antes de tagear.

## Qué se rompe si cambias esto

Los cuatro repositorios comparten contratos, y nada avisa cuando uno cambia bajo los pies de otro. Antes de tocar algo de esta tabla, mira quién lo consume.

| Si cambias… | Se rompe… | Cuidado |
|-------------|-----------|---------|
| Un endpoint o campo de `/api/v1/*` | `bascula` y `bascula-android`, **incluidos los equipos que no se actualizan** | Solo cambios aditivos. Ver la regla de arriba |
| La forma de `POST /api/v1/sales` | Además el outbox del hub, que reenvía ese mismo cuerpo | El `client_reference` debe seguir viajando intacto |
| Un endpoint de `/api/v1/hub/*` | `carniceria-hub` | Se actualiza solo, pero una versión vieja sigue viva hasta que la sucursal reinicie |
| El nombre o la carga de un evento de broadcast | La mesa de trabajo web **y** la del hub | Los dos escuchan `sucursal.{branchId}` |
| La autorización de un canal | Web y hub a la vez | El canal se autoriza con guard `web` y con `sanctum` |
| Un feature flag de `branches` | La navegación de la web **y** la del hub | El hub los lee del login y oculta pantallas con ellos |
| Un rol, o quién puede entrar al hub | `EnsureHubRole` | El hub solo admite `admin-sucursal` y `cajero` |
| La API local del hub (`:4599`) | Las básculas emparejadas de esa sucursal | Mismo criterio aditivo que la Scale API |
| El esquema de `hub.sqlite` | Nada fuera del hub | Pero **una migración mal hecha pierde ventas sin sincronizar** |

**Regla práctica:** un cambio que toque una fila de esta tabla debería probarse contra la aplicación que lo consume antes de publicarse, no solo contra sus propias pruebas.

## Publicación y auto-actualización

Las tres aplicaciones cliente se publican con GitHub Actions al empujar un tag, suben el artefacto al bucket R2 y crean el Release. Las instalaciones existentes se actualizan solas.

| App | Artefacto | Ruta en R2 | Runbook |
|-----|-----------|-----------|---------|
| `bascula` | Instalador NSIS (Windows) | `bascula/win/` | `bascula/docs/releases.md` |
| `bascula-android` | APK firmado (fuera de Play Store) | `android/` | `bascula-android/docs/releases.md` |
| `carniceria-hub` | Instalador NSIS (Windows) | — | `carniceria-hub/docs/superpowers/` |

Notas operativas que cuestan caro descubrir a mano:

- **Android:** instalar siempre desde la URL versionada — Laravel Cloud reescribe el `Cache-Control` de los `.apk`.
- **`bascula`:** `perMachine` pasó de `true` a `false` para que el updater no pida permisos de administrador en cada actualización. Las Surface con la instalación vieja (HKLM) probablemente necesiten desinstalarla a mano una vez, o convivirán dos copias.
- **Hub:** la versión del instalador sale de `package.json`, no del tag.

## Decisiones estructurales

Las decisiones que explican por qué el sistema tiene esta forma:

| Decisión | Razón |
|----------|-------|
| Multitenancy por columna, no por base de datos | Simplicidad operativa; el aislamiento se garantiza con un scope global y un middleware. |
| Autorización solo por rol, sin permisos granulares | El control fino se hace con *feature flags por sucursal*, que el dueño enciende y apaga sin tocar código. |
| El pedido web **no** es una venta contable | Es una comanda que se empareja a mano con la venta real de la báscula. La venta siempre nace en el mostrador. |
| La IA nunca autoriza ni escribe directo | Toda escritura pasa por borrador + confirmación humana que re-valida en el servidor. La IA tampoco genera cifras finales. |
| Compras separado de Gastos | Costo de mercancía (CMV) contra gasto operativo (OPEX). Sin inventario todavía, por diseño. |
| Broadcasting inmediato, sin colas | `ShouldBroadcastNow` por canal privado de sucursal: el cajero ve la venta en el momento. |
| La web es la fuente de verdad visual del hub | Un solo lenguaje de interfaz para el personal que usa las dos. |

## Trabajo pospuesto

**Atlas de arquitectura** — una vista navegable del ecosistema generada desde un manifiesto (`system-architecture.json`) con sus validadores. Está construido en las ramas `feat/atlas-vivo` y `feat/atlas-rediseno-visual`, **sin fusionar a `main`**.

Decisión (2026-08-23): **se pospone**. Mantener el manifiesto al día con el ritmo de cambio actual costaría más de lo que aporta hoy, y un Atlas desactualizado es peor que no tenerlo. Cuando el ritmo baje, se retoma; hasta entonces **este documento es la fuente de verdad del ecosistema** y no hay obligación de actualizar ningún manifiesto.

Otros pendientes conocidos: inventario/stock (no iniciado, por diseño) y el modo offline completo del hub.

## Dónde seguir leyendo

- Índice general de la documentación: [docs/README.md](../README.md)
- Arquitectura del backend: [multitenant.md](multitenant.md) · [roles-permisos.md](roles-permisos.md) · [reverb-websockets.md](reverb-websockets.md)
- Contratos de API: [endpoints.md](../api/endpoints.md) · [hub.md](../api/hub.md) · [errores.md](../api/errores.md)
- Módulos de negocio: [docs/modulos/](../modulos/)
