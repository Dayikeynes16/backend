# Pedidos Web / Menú Online

> **Oculto tras `FEATURE_WEB_ORDERS=false` desde 2026-07-08** (spec `../superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md`). El código y los datos siguen intactos; las ventas web históricas conservan sus badges informativos.
>
> **Cómo re-activar:** `FEATURE_WEB_ORDERS=true` en `.env` + `php artisan config:clear && php artisan route:clear` (+ rebuild de assets no es necesario: la UI lee la prop `features.webOrders`).

Canal de venta público por tenant: una SPA sin login donde el cliente final ve el menú de una sucursal, arma un carrito, cotiza el envío y confirma su pedido por WhatsApp. El pedido se persiste como `Sale` con `origin='web'` y `status=pending` **antes** de abrir WhatsApp (cero pedidos fantasma) y aparece en tiempo real en el Workbench de la sucursal.

> **Importante:** el pedido web **NO es una venta contable**. Es una comanda/referencia que después se empareja con la venta real que crea la báscula (peso pedido ≠ peso cortado). Ver [emparejar-pedido-venta.md](emparejar-pedido-venta.md) y el scope `Sale::accountable()`.

## Responsabilidades

- Servir una URL pública `/menu/{tenant-slug}` con selector de sucursal (geolocalización sugiere la más cercana por Haversine).
- Exponer el menú por sucursal: solo productos `status=active` + `visible_online=true`, con presentaciones activas.
- Cotizar costo de envío por distancia real (Google Distance Matrix) contra tiers configurables por sucursal.
- Crear la `Sale` web con recálculo de precios **server-side** (nunca confiar en los montos del carrito del cliente).
- Identificar al cliente por `phone + name` sin login: `Customer::firstOrCreate` por `(branch_id, phone)` normalizado a E.164.
- Generar el mensaje de WhatsApp con el detalle del pedido hacia el `public_phone` de la sucursal.
- Notificar a la sucursal en tiempo real (`NewExternalSale` por Reverb, igual que las ventas de báscula).
- Aplicar branding por tenant (colores, logo, imagen de producto default) vía `BrandingService` / Personalización.

**No hace:**
- No cobra online — el pago se coordina por WhatsApp/en entrega; solo se registra el `payment_method` elegido.
- No es la venta final: el cobro real ocurre en la venta de báscula emparejada (`OrderLinkService`).
- No tiene cuentas de cliente, historial de pedidos ni tracking de entrega.
- No maneja stock: un producto visible online se puede pedir aunque no haya existencia.

## Principios / Decisiones

| Decisión | Razón |
|---|---|
| Reusar `Sale` con `origin='web'` + `status=pending` | Sin entidad `OnlineOrder` paralela; el Workbench ya sabe listar/aceptar ventas pendientes. |
| `Sale` se crea **antes** de abrir WhatsApp | Cero pedidos fantasma: si el cliente no manda el mensaje, la sucursal igual ve el pedido. |
| Pedido web excluido de contabilidad (`scopeAccountable`) | Estados `pending`/`fulfilled` con `origin='web'` no cuentan en reportes ni cortes — la venta de báscula emparejada es la contable. Evita doble conteo. |
| Precios y subtotales se recalculan en servidor | El payload del cliente solo trae `product_id`/`presentation_id`/`quantity`; el precio sale de BD. |
| Middleware propio `resolve.public.tenant` (no `resolve.tenant`) | Resuelve tenant activo por `{tenantSlug}` sin sesión; `abort(404)` si no existe; `forgetParameter` igual que el flujo autenticado. |
| Controllers públicos usan `withoutGlobalScopes()` + filtro explícito `tenant_id` | No hay usuario autenticado, `TenantScope` no aplica; el filtro manual es obligatorio en cada query. |
| Delivery con tiers `[{max_km, fee}]` por sucursal | Tarifa configurable por rangos; sin flat rate ni cobro por km lineal. |
| `hours` JSON opcional; `null` = siempre abierta | No obligar horarios; si el día es `null` o faltan `open`/`close`, ese día está cerrada. |
| Identificación por teléfono, sin login | `PhoneNormalizer` lleva todo a E.164 (+52 implícito para 10 dígitos MX); `firstOrCreate` de `Customer`. |
| Folio `S-NNNNN` por conteo de ventas de la sucursal | Serializado con `pg_advisory_xact_lock(branch_id)` dentro de la transacción — dos pedidos simultáneos no duplican folio. |
| Configuración solo de admin-empresa | Toggles, tiers, horarios y coordenadas viven en *Editar Sucursal* (empresa). El admin-sucursal solo controla `visible_online` por producto y consulta su QR. |
| SPA Vue independiente (no Inertia) | App pública sin sesión, `vue-router` con base `/menu`, montada en el blade `public-spa`. |

## Modelo de datos

No hay tablas nuevas — todo son columnas sobre entidades existentes:

```
branches   + online_ordering_enabled (bool, default false)
           + delivery_enabled (bool, default false)
           + pickup_enabled (bool, default true)
           + delivery_tiers (jsonb [{max_km, fee}...])
           + max_delivery_km (decimal 6,3)
           + min_order_amount (decimal 10,2)
           + public_phone (string 20, E.164)   ← destino del WhatsApp
           + hours (jsonb {mon..sun: {open, close}|null})

products   + visible_online (bool, default false)

sales      + delivery_type ('pickup'|'delivery')
           + delivery_address, delivery_lat, delivery_lng
           + delivery_distance_km, delivery_fee
           + contact_name, contact_phone (E.164)
           + cart_note
           + linked_order_id (FK self — emparejamiento, migración 2026-05-16)
```

Migraciones: `2026_04_17_000001..000003` + `2026_05_16_120000_add_linked_order_id_to_sales_table.php`.

### Estados del pedido web (`SaleStatus`)

```
pending    ← estado inicial al crear el pedido web
   ├→ fulfilled   al emparejarse con una venta de báscula (OrderLinkService::link)
   ├→ active      si el cajero decide trabajarlo directo
   └→ cancelled
fulfilled  → pending    (solo al desvincular — OrderLinkService::unlink)
```

`Sale::scopeAccountable()` excluye `origin='web'` con status `pending|fulfilled` — reportes, métricas y cortes de caja nunca los suman.

## Roles y permisos

| Acción | Público (anónimo) | superadmin | admin-empresa | admin-sucursal | cajero |
|---|:-:|:-:|:-:|:-:|:-:|
| Ver menú / cotizar envío / crear pedido | ✅ (rate-limited) | — | — | — | — |
| Activar pedidos online, tiers, horarios, `public_phone` | ❌ | ✅ | ✅ (*Editar Sucursal*) | ❌ | ❌ |
| Branding del menú (colores/logo) | ❌ | ✅ | ✅ (`/empresa/personalizacion`) | ❌ | ❌ |
| Marcar producto `visible_online` | ❌ | ✅ | — | ✅ (CRUD productos + toggle rápido) | ❌ |
| Ver página "Menú online" (QR + URL) | ❌ | ✅ | — | ✅ (`sucursal.menu-online`) | ❌ |
| Ver/emparejar pedidos pendientes en Workbench | ❌ | ✅ | — | ✅ | ✅ |

## Flujo

```
Cliente                      SPA pública                 Backend                       Sucursal
  │                             │                           │                             │
  ├─ escanea QR /menu/{slug} ──→│                           │                             │
  │                             ├─ GET /api/public/{slug} ─→│ TenantController            │
  │  elige sucursal (Haversine) │                           │  (branches con ordering ON) │
  │                             ├─ GET .../branches/{id}/menu → MenuController            │
  │  arma carrito (localStorage)│                           │  (branding+branch+products) │
  │  checkout: ubicación ───────├─ POST .../delivery/quote →│ DeliveryFeeService          │
  │                             │                           │  (Google Matrix + tiers)    │
  │  confirma ──────────────────├─ POST .../orders ────────→│ OrderController::store      │
  │                             │                           │  Sale pending origin=web    │
  │                             │                           ├─ NewExternalSale ──────────→│ Workbench
  │← redirect wa.me (mensaje) ──│←── {folio, whatsapp_url} ─┤                             │ (tiempo real)
  │                             │                           │                             │
  │  ...báscula pesa y crea venta real → cajero "Vincular pedido web" → OrderLinkService  │
  │      pedido pasa a fulfilled; delivery_* se copian a la venta real (+delivery_fee)    │
```

### Validaciones en `OrderController::store` (en orden)

1. **Honeypot**: si el campo oculto llega lleno, responde un `201` falso con folio aleatorio y descarta el pedido en silencio.
2. Sucursal activa del tenant con `online_ordering_enabled`; el `delivery_type` debe estar habilitado (`delivery_enabled`/`pickup_enabled`) → `422` con código (`ordering_disabled`, `delivery_not_available`, ...).
3. `isOpenNow(hours)` → `422 closed` fuera de horario.
4. **Soft-block anti-abuso**: 3+ pedidos web cancelados del mismo `contact_phone` en la sucursal en 24 h → `429 please_contact_branch`.
5. Todos los productos deben existir en la sucursal, activos, `visible_online`, no borrados → `422 invalid_products` (lista los ids). Presentación inválida → `422 invalid_presentation`.
6. Recalcula subtotales server-side (línea por peso/pieza o snapshot de presentación — mismo contrato que `WorkbenchController::store`).
7. Si es delivery: exige `lat/lng/address` y cotiza de nuevo (`out_of_range` 422 / `quote_unavailable` 503) — el fee persistido es el del servidor, no el que vio el cliente.
8. `min_order_amount` de la sucursal sobre el subtotal → `422 below_minimum`.
9. Transacción con `pg_advisory_xact_lock(branch_id)`: `Customer::firstOrCreate(branch_id, phone)`, folio `S-NNNNN`, `Sale` + items. Luego `NewExternalSale::dispatch` y arma `whatsapp_url` si hay `public_phone`.

## Rutas / Endpoints

### SPA (web.php)

```
GET /menu/{tenantSlug}/{any?}      public.menu    ← sirve el blade `public-spa`; vue-router resuelve el resto
GET /{tenant}/sucursal/menu-online sucursal.menu-online  ← página QR (MenuQrController → Sucursal/MenuQr.vue)
```

### API pública (api.php) — sin auth, grupo `resolve.public.tenant` + `throttle:60,1`

```
GET  /api/public/{tenantSlug}                              api.public.tenant.show     ← tenant + branding + sucursales online
GET  /api/public/{tenantSlug}/branches/{branch}/menu       api.public.menu            ← branding + branch + categorías + productos
POST /api/public/{tenantSlug}/branches/{branch}/delivery/quote  api.public.delivery.quote  (throttle:20,1)
POST /api/public/{tenantSlug}/branches/{branch}/orders     api.public.orders.store    (throttle:10,1)
```

Respuesta de `orders.store`: `201 { sale_id, folio, whatsapp_url, total }`.

## Servicios

| Servicio | Rol |
|---|---|
| `DeliveryFeeService` | `quote(branch, lat, lng)`: Google Distance Matrix (modo driving, timeout 5 s) con caché 24 h por par de coordenadas redondeadas a 4 decimales; valida `max_delivery_km` y resuelve el tier (`tierFee` ordena por `max_km`). Lanza `OutOfRangeException` / `QuoteUnavailableException` (sin API key, HTTP error, ruta inexistente). Incluye `haversineKm` utilitario. |
| `PhoneNormalizer` | `normalize()` → E.164: limpia separadores, respeta `+` inicial, 10 dígitos ⇒ prefijo `+52`. `digits()` para armar URLs `wa.me`. Se usa en pedidos, en `public_phone` de la sucursal y en el flujo WhatsApp de caja. |
| `WhatsappMessageService` | `buildOrderText(sale)`: mensaje al **negocio** con folio, cliente, líneas (con notas), entrega (dirección + link Google Maps + distancia + fee) o "Pasará por su pedido", método de pago, total y `cart_note`; trunca a 3 500 bytes conservando folio+total. `buildUrl(phone, text)` → `https://wa.me/...`. También `linkForSale`/`buildCustomerSaleText` (mensaje al **cliente**, usado por caja/hub — no exclusivo de pedidos web). |
| `OrderLinkService` | Emparejar/desemparejar pedido web ↔ venta de báscula. Documentado en [emparejar-pedido-venta.md](emparejar-pedido-venta.md). |
| `BrandingService` | Resuelve colores/logo/imagen default por tenant (config en `/empresa/personalizacion`). |

## Frontend (SPA pública — `resources/js/public/`)

App Vue 3 independiente de Inertia: `main.js` monta `App.vue` en `#public-app` con `vue-router` (`createWebHistory('/menu')`). `api.js` crea un axios con `baseURL /api/public/{slug}`.

| Vista (ruta) | Qué hace |
|---|---|
| `BranchSelector` (`/:tenantSlug`) | Lista sucursales online; con permiso de geolocalización ordena por cercanía (Haversine en cliente). |
| `MenuHome` (`.../s/:branchId`) | Menú por categorías, búsqueda, `ProductModal` (cantidad por peso/pieza/presentación + notas), `CartBar` sticky. |
| `Cart` (`.../cart`) | Revisión de líneas, editar/eliminar, `cart_note`. |
| `Checkout` (`.../checkout`) | Pickup/delivery, `LocationPicker` (mapa + geolocalización), cotización con re-quote si el pin se mueve, datos de contacto, método de pago (los `payment_methods_enabled` de la sucursal), **input honeypot oculto** (`tabindex=-1`), submit a `orders.store`. |
| `Confirmed` (`.../ok/:saleId`) | Folio + redirección/botón a `whatsapp_url`; si la sucursal no tiene `public_phone`, muestra "quedó registrado". |

Composables: `useTenant` / `useMenu` (fetch + estado), `useCart` (carrito por sucursal en `localStorage` `cart:{branchId}`, TTL 90 días, merge por producto+presentación+notas), `useContact` (nombre/teléfono/última dirección en `localStorage` `web_contact_v1`, TTL 90 días), `useBranding` (singleton: inyecta CSS vars `--brand-*` desde el payload de branding, calcula texto auto por contraste WCAG y variantes soft/strong/ring del color primario).

### Configuración interna (Inertia)

- `Empresa/Sucursales/Edit.vue`: toggles + `HoursEditor` + `DeliveryTiersEditor` + coordenadas. `SucursalController::validateOnlineOrderingConfig` exige `public_phone` si ordering ON, al menos pickup o delivery, y coordenadas + ≥1 tier si delivery ON. Los tiers se ordenan por `max_km` al guardar; `hours` se normaliza por día.
- `Sucursal/MenuQr.vue`: muestra QR + URL `/menu/{slug}/s/{branchId}` para imprimir; avisa si el ordering está apagado (lo enciende empresa).
- `visible_online` se edita en el CRUD de productos de sucursal (form y toggle rápido en `ProductoController`). Default `false`: nada se publica sin decisión explícita.

## Anti-spam / anti-abuso

| Mecanismo | Detalle |
|---|---|
| Honeypot | Campo oculto en Checkout; si llega con valor, `201` falso (folio aleatorio, `sale_id: 0`) sin persistir nada — el bot no aprende. |
| Rate limiting | `throttle:60,1` en el grupo público; `20,1` en cotización; `10,1` en creación de pedidos (por IP). |
| Soft-block por teléfono | 3+ pedidos web cancelados en 24 h en la sucursal ⇒ `429` con mensaje "contacta a la sucursal". |
| Recalculo server-side | Precios, fee de envío, mínimo de pedido y total se recalculan en backend; el cliente solo manda ids y cantidades. |
| Advisory lock | `pg_advisory_xact_lock(branch_id)` serializa folio + `firstOrCreate` del cliente ante pedidos concurrentes. |

## Riesgos y limitaciones

| Riesgo / limitación | Estado |
|---|---|
| Dependencia de Google Distance Matrix (`services.google_matrix.key`) | Sin key o con error ⇒ `503 quote_unavailable`; no hay fallback a Haversine para el fee. La caché de 24 h amortigua costo/latencia. |
| `isOpenNow` no soporta horarios que cruzan medianoche | Compara `open <= now <= close` del mismo día; un horario 18:00→02:00 se evalúa mal. |
| Folio por `count()` de ventas de la sucursal | Correcto bajo el advisory lock, pero un borrado físico de ventas podría repetir folio (hoy no se borran físicamente). |
| Sin pago online | Por diseño: la coordinación/cobro es por WhatsApp y en la venta de báscula. |
| Los controllers públicos usan `withoutGlobalScopes()` | Cualquier query nueva ahí **debe** filtrar `tenant_id`/`branch_id` a mano — no hay red de seguridad del `TenantScope`. |
| Sin tests feature de los endpoints públicos | `Public/{Tenant,Menu,Delivery,Order}Controller` no tienen suite propia (gap conocido); la cobertura existente entra por el lado del emparejamiento y del scope contable. |
| El cliente puede no enviar el WhatsApp | Aceptado: la `Sale` ya existe y la sucursal la ve en el Workbench de todos modos. |

## Tests

No existe (aún) una suite dedicada a los controllers públicos; la cobertura relacionada al pedido web vive en:

```bash
php artisan test --compact tests/Unit/SaleScopeAccountableTest.php          # accountable() por origin+status
php artisan test --compact tests/Unit/Services/OrderLinkServiceTest.php     # emparejar/desemparejar
php artisan test --compact tests/Feature/Sucursal/PendingWebOrdersTest.php  # listado de pedidos web pendientes (aislamiento tenant/branch)
php artisan test --compact tests/Feature/Sucursal/LinkOrderTest.php tests/Feature/Sucursal/UnlinkOrderTest.php \
    tests/Feature/Sucursal/LinkableSalesTest.php tests/Feature/Sucursal/EndToEndLinkFlowTest.php
php artisan test --compact tests/Feature/Sucursal/HistorialFulfilledTest.php            # fulfilled en historial
php artisan test --compact tests/Feature/Sucursal/CustomerProfileExcludesWebOrdersTest.php
php artisan test --compact tests/Feature/Http/Sucursal/MenuQrTest.php                   # página QR por rol
php artisan test --compact tests/Feature/Http/Empresa/SucursalControllerTest.php        # validación config online/tiers
php artisan test --compact tests/Feature/Empresa/PersonalizacionControllerTest.php      # branding del menú
php artisan test --compact tests/Feature/Services/WhatsappCustomerSaleMessageTest.php   # mensajes WhatsApp
```

## Referencias internas

- [docs/superpowers/specs/2026-04-17-pedidos-web-design.md](../superpowers/specs/2026-04-17-pedidos-web-design.md) — spec original con las decisiones de brainstorming. **Nota:** la spec planteaba "Aceptar/Rechazar en Workbench"; la revisión de 2026-05-16 pivotó al modelo de emparejamiento.
- [docs/modulos/emparejar-pedido-venta.md](emparejar-pedido-venta.md) — doc vivo del emparejamiento (el pedido web como comanda).
- [docs/modulos/sucursales.md](sucursales.md) — CRUD de sucursales donde vive la configuración online.
- `app/Http/Controllers/Public/` — Tenant/Menu/Delivery/Order controllers.
- `app/Http/Middleware/ResolvePublicTenant.php` — resolución de tenant sin sesión.
- `app/Services/DeliveryFeeService.php`, `PhoneNormalizer.php`, `WhatsappMessageService.php`, `OrderLinkService.php`.
- `resources/js/public/` — SPA pública (Vue 3 + vue-router, sin Inertia).
