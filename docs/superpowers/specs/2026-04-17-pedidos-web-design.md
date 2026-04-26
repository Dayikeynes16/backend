# Pedidos Web — diseño

**Fecha**: 2026-04-17
**Autor**: Brainstorming sesión (Sebastián + Claude)
**Estado**: Aprobado para implementación (rev 2 — ajustes post-review)
**Módulo afectado**: Ventas / SaaS pública / Sucursales

---

## Problema

El SaaS actual solo admite ventas originadas en el POS (`Workbench` manual) o en básculas vía API (`X-Api-Key` → `Sale` con `origin='api'`). No existe un canal de venta para el cliente final: quien quiera comprar en una carnicería del tenant debe ir presencialmente o llamar por teléfono.

Los tenants quieren ofrecer pedidos online a sus clientes con la menor fricción posible, pero no quieren convertirse en un e-commerce tradicional con carrito, login y pago electrónico. Necesitan:

1. Una página pública por tenant donde los clientes vean el menú de cada sucursal
2. Un flujo de una sola pantalla (scroll + modal, sin profundidad de navegación)
3. Coordinación final por WhatsApp — no pago electrónico
4. Reuso del Workbench y flujos existentes del admin

## Objetivo

1. Publicar una URL pública `/menu/{tenant-slug}` donde clientes anónimos puedan armar pedidos
2. Identificar al cliente por `phone + name` (sin login); crear/vincular `Customer` automáticamente
3. Calcular costo de envío con Google Distance Matrix respetando rangos configurables por sucursal
4. Crear una `Sale` con `origin='web'` + `status=pending` *antes* de abrir WhatsApp (cero pedidos fantasma)
5. Que el admin-sucursal vea el pedido en tiempo real en su Workbench normal y lo acepte o rechace
6. Que el admin-empresa controle qué sucursales aparecen online y configure sus tarifas de envío

## Decisiones tomadas (brainstorming)

| # | Decisión | Alternativas descartadas |
|---|----------|--------------------------|
| 1 | Reusar `Sale` con `origin='web'` + `status=pending`; Workbench muestra acciones Aceptar/Rechazar | Entidad `OnlineOrder` separada; no crear nada hasta aceptar |
| 2 | Una URL por tenant con selector de sucursal (Haversine sugiere la más cercana) | URL por sucursal; auto-selección rígida por ubicación |
| 3 | Delivery con tiers `[{max_km, fee}, ...]` configurables por sucursal | Tarifa plana por km; flat rate + radio; sin costo calculado |
| 4 | Configuración (visibility, tiers, horarios, coords) controlada por admin-empresa, no por admin-sucursal | admin-sucursal maneja su propia config |
| 5 | Selector simple de `payment_method` entre los `payment_methods_enabled` de la sucursal; sin cobro online | Comprobante de transferencia; sin selector |
| 6 | Lookup/create `Customer` por `(tenant_id, branch_id, phone)` con índice único en DB; sin login del cliente | Solo snapshot contact_name/phone; opt-in identificación |
| 7 | `hours` JSON opcional por sucursal; si `null`, siempre abierta para pedidos web | Obligar horarios como requisito duro |
| 8 | UX single-page con scroll + modal (inspirado en "guisogo"): cero profundidad de navegación | Múltiples rutas tipo e-commerce |
| 9 | Un solo build Vue SPA dinámico bajo prefijo `/menu/{tenant}` | Build por tenant con `VITE_TENANT_TOKEN`; catch-all route sin prefijo (conflicto) |
| 10 | Notas por producto en `sale_items.notes` (nuevo campo) | Solo nota global por pedido |
| 11 | `visible_online` nuevo flag independiente de `visibility` existente (que gobierna báscula) | Reusar `visibility`, crear valor `'online'` |
| 12 | Vue 3 composables para el estado de la SPA pública (no Pinia) | Pinia — contradice convención del proyecto (CLAUDE.md) |

## Arquitectura

### 1. Cambios al esquema

**Tabla `branches` — nuevos campos (todos nullable/default):**

```sql
-- Nota: `branches` ya tiene `latitude`, `longitude`, `schedule` (texto), `payment_methods_enabled`.
-- Reusamos `latitude`, `longitude`. No duplicar.

ALTER TABLE branches ADD COLUMN online_ordering_enabled boolean   DEFAULT false;
ALTER TABLE branches ADD COLUMN delivery_enabled        boolean   DEFAULT false;
ALTER TABLE branches ADD COLUMN pickup_enabled          boolean   DEFAULT true;
ALTER TABLE branches ADD COLUMN delivery_tiers          jsonb;              -- [{"max_km":2,"fee":40},{"max_km":5,"fee":70}]
ALTER TABLE branches ADD COLUMN max_delivery_km         decimal(6,3);       -- derivado de tiers, actualizado por Observer
ALTER TABLE branches ADD COLUMN min_order_amount        decimal(10,2);
ALTER TABLE branches ADD COLUMN public_phone            varchar(20);        -- E.164: +521234567890
ALTER TABLE branches ADD COLUMN hours                   jsonb;              -- {"mon":{"open":"07:00","close":"20:00"},"sun":null}
```

> **Relación `schedule` vs `hours`**: `schedule` (texto libre existente) se conserva para mostrar en tickets y UI interna. `hours` (nuevo JSON estructurado) es el dato que el backend consulta para determinar si la sucursal está abierta al momento de recibir un pedido web. Son independientes — el admin-empresa mantiene ambos si los quiere consistentes.

**Tabla `products` — nuevo campo:**
```sql
-- `visibility` ya existe (valores 'public'|'restricted') y gobierna visibilidad en API báscula.
-- Agregamos `visible_online` INDEPENDIENTE (no lo fusionamos con `visibility` para permitir
-- que un producto esté visible en báscula pero oculto en web, o viceversa).

ALTER TABLE products ADD COLUMN visible_online boolean DEFAULT false;  -- opt-in, seguro por defecto
```

**Tabla `sales` — nuevos campos:**
```sql
ALTER TABLE sales ADD COLUMN delivery_type         varchar(10);   -- 'pickup' | 'delivery'
ALTER TABLE sales ADD COLUMN delivery_address      text;
ALTER TABLE sales ADD COLUMN delivery_lat          decimal(10,7);
ALTER TABLE sales ADD COLUMN delivery_lng          decimal(10,7);
ALTER TABLE sales ADD COLUMN delivery_distance_km  decimal(6,3);
ALTER TABLE sales ADD COLUMN delivery_fee          decimal(10,2);
ALTER TABLE sales ADD COLUMN contact_name          varchar(255);  -- snapshot paralelo a customer_id
ALTER TABLE sales ADD COLUMN contact_phone         varchar(20);
ALTER TABLE sales ADD COLUMN cart_note             text;          -- nota global del cliente
```

**Tabla `sale_items` — nuevo campo:**
```sql
ALTER TABLE sale_items ADD COLUMN notes text;  -- nota del cliente por producto
```

**Tabla `customers` — unicidad:**
```sql
-- Permite firstOrCreate atómico sin duplicados bajo concurrencia.
-- Los clientes actuales podrían tener duplicados; la migración primero deduplica
-- (mantiene el más antiguo, mueve las ventas al superviviente), luego aplica el índice.
CREATE UNIQUE INDEX customers_tenant_branch_phone_uniq
    ON customers (tenant_id, branch_id, phone)
    WHERE phone IS NOT NULL;
```

**Fillable actualizado en modelos:**
- `Branch::$fillable` agrega los campos nuevos + cast `delivery_tiers`/`hours` a `array`
- `Product::$fillable` agrega `visible_online` con cast `boolean`
- `Sale::$fillable` agrega campos de delivery/contact/cart_note
- `SaleItem::$fillable` agrega `notes`

### 2. Endpoints públicos (sin auth)

Grupo en `routes/api.php`:

```php
Route::prefix('public/{tenantSlug}')
    ->where('tenantSlug', '[a-z0-9-]+')
    ->middleware(['resolve.public.tenant', 'throttle:60,1'])
    ->group(function () {
        Route::get('/', [Public\TenantController::class, 'show']);
        Route::get('branches/{branch}/menu', [Public\MenuController::class, 'show']);
        Route::post('branches/{branch}/delivery/quote', [Public\DeliveryController::class, 'quote'])
            ->middleware('throttle:20,1');
        Route::post('branches/{branch}/orders', [Public\OrderController::class, 'store'])
            ->middleware('throttle:10,1');
    });
```

Middleware nuevo **`ResolvePublicTenant`**:
1. Resuelve `{tenantSlug}` del path → `Tenant::where('slug', $slug)->where('status','active')->first()`; 404 si no existe
2. Instancia `app()->instance('tenant', $tenant)` igual que `ResolveTenant`
3. `forgetParameter('tenantSlug')` y continúa

Controllers nuevos bajo `App\Http\Controllers\Public\`:

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/public/{tenant}` | Info del tenant + sucursales con `online_ordering_enabled=true AND status='active'`: `id, name, latitude, longitude, hours, pickup_enabled, delivery_enabled, min_order_amount`. NO expone `public_phone` hasta que haya un pedido creado (anti-scrape) |
| `GET` | `/api/public/{tenant}/branches/{branch}/menu` | Categorías + productos `visible_online=true AND status='active' AND deleted_at IS NULL` de esa sucursal. Incluye `is_open` calculado server-side contra `hours` y `now()`. Valida que el `branch` pertenezca al tenant y tenga `online_ordering_enabled=true` |
| `POST` | `/api/public/{tenant}/branches/{branch}/delivery/quote` | Body: `{lat, lng}`. Haversine pre-filter (rechaza si distancia euclídea > `max_delivery_km × 1.5`). Si pasa, llama Google Matrix, cachea 24h en Redis, devuelve `{distance_km, duration_min, tier_index, fee}` |
| `POST` | `/api/public/{tenant}/branches/{branch}/orders` | Crea la `Sale`. Ver flujo abajo. Respuesta incluye `whatsapp_url` |

**Sanctum/CSRF**: estas rutas viven en el grupo `api` de Laravel que **no aplica CSRF ni Sanctum por defecto**. Al no haber sesión ni cookies confiables, cada request es stateless. Se verifica explícitamente en `bootstrap/app.php` que no se añada `EnsureFrontendRequestsAreStateful` al grupo `api`.

### 3. Flujo de creación de pedido (`POST /orders`)

Payload del cliente:
```json
{
  "items": [
    {"product_id": 42, "quantity": 2.5, "presentation_id": null, "notes": "Más limpia de grasa"}
  ],
  "delivery_type": "delivery",
  "delivery_address": "Calle X 123, col Y",
  "delivery_lat": 18.0012,
  "delivery_lng": -92.9451,
  "contact_name": "María López",
  "contact_phone": "+529931234567",
  "payment_method": "cash",
  "cart_note": "Tocar timbre 2 veces",
  "honeypot": ""
}
```

**Validaciones rápidas (antes de transacción):**
- `honeypot` debe venir vacío; si trae valor → responder `201` con payload falso (`{"folio":"S-XXXXX"}`) pero NO crear nada. No revela la trampa al bot.
- Formato de `contact_phone`: regex E.164 relajada `/^\+?[0-9]{10,15}$/`. Se normaliza antes de persistir (`normalizePhone` helper).

**Backend dentro de `DB::transaction`:**

1. `app('tenant')` ya resuelto por middleware
2. `$branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status','active')->findOrFail($branchId)` — rechaza sucursales inactivas o de otros tenants
3. Valida `$branch->online_ordering_enabled=true`
4. Si `$branch->hours` está configurado: valida que `now()` (TZ del tenant) esté dentro del rango del día; si no, devuelve 422 `{error:'closed'}`
5. Valida `delivery_type` coherente con `delivery_enabled`/`pickup_enabled` de la sucursal
6. Valida productos:
   ```php
   $products = Product::withoutGlobalScopes()
       ->where('tenant_id', $tenant->id)
       ->where('branch_id', $branch->id)
       ->whereIn('id', $productIds)
       ->where('status', 'active')
       ->where('visible_online', true)
       ->whereNull('deleted_at')   // explícito aunque SoftDeletes lo haría
       ->get()->keyBy('id');
   ```
   Si algún `product_id` del payload no está en `$products` → 422 con lista de IDs inválidos.
7. Recalcula cada `subtotal = qty × price_del_catalogo` (ignora lo que mande el cliente)
8. Recalcula `delivery_fee`:
   - Si `delivery_type='pickup'`: `delivery_fee = 0`
   - Si `delivery_type='delivery'`:
     - Haversine pre-filter contra `max_delivery_km × 1.5`
     - Llama `DeliveryFeeService::quote($branch, $lat, $lng)` (cache 24h)
     - Encuentra el tier en `delivery_tiers` (primer tier cuyo `max_km >= distance`); si ninguno → 422 `{error:'out_of_range'}`
9. `total = sum(subtotals) + delivery_fee`
10. Si `$branch->min_order_amount` no-null y `sum(subtotals) < min_order_amount` → 422 `{error:'below_minimum'}`
11. **Bloqueo suave por teléfono**: si el `contact_phone` tiene ≥3 ventas con `status='cancelled'` y `origin='web'` en las últimas 24h → 429 `{error:'please_contact_branch'}`
12. Lookup/create `Customer` con unicidad garantizada por el índice único del paso 1:
    ```php
    $customer = Customer::firstOrCreate(
        ['branch_id' => $branch->id, 'phone' => $contactPhone],
        ['name' => $contactName]  // tenant_id lo llena BelongsToTenant automáticamente
    );
    ```
13. Genera folio con `pg_advisory_xact_lock($branch->id)` (patrón existente en `Api\SaleController`)
14. Crea `Sale`: `origin='web'`, `origin_name='Pedido web'`, `status=SaleStatus::Pending`, `customer_id`, `contact_name`, `contact_phone`, campos de delivery, `payment_method`, `cart_note`, `total`, `amount_paid=0`, `amount_pending=$total`
15. Crea `SaleItems` con snapshot: `product_name`, `unit_price`, `original_unit_price=unit_price`, `subtotal`, `notes` del body
16. Dispatch `NewExternalSale::dispatch($sale)` — el evento ya broadcast a `sucursal.{branch_id}`; ver sección 7 para ajustes al payload
17. Genera texto de WhatsApp con `WhatsappMessageService::buildOrderText($sale)` y URL `https://wa.me/{digitos_public_phone}?text={urlencoded(text)}`
18. Respuesta 201:
    ```json
    {"sale_id": 123, "folio": "S-00042", "whatsapp_url": "https://wa.me/...", "total": 789.00}
    ```

**Nota sobre `shift_id`**: la tabla `sales` no tiene columna `shift_id` (los turnos se asocian a `payments`, no a ventas). Un pedido web pendiente puede existir sin turno abierto; cuando el admin-sucursal lo acepta y lo cobra, cada `Payment` se asocia al turno vigente en ese momento (patrón existente — sin cambios).

### 4. Frontend — SPA pública

**Rutas en Laravel (`routes/web.php`, al inicio antes del grupo `{tenant}` para evitar colisión):**
```php
Route::get('/menu/{tenantSlug}/{any?}', fn () => view('public-spa'))
    ->where('tenantSlug', '[a-z0-9-]+')
    ->where('any', '.*')
    ->name('public.menu');
```

El prefijo `/menu` es un **segmento reservado** — garantiza cero colisión con el grupo `{tenant}` existente que maneja `/el-toro/empresa`, `/el-toro/sucursal`, `/el-toro/caja`.

**Archivos nuevos:**
- `resources/views/public-spa.blade.php` — HTML mínimo con `<div id="public-app"></div>` y `@vite('resources/js/public/main.js')`
- `resources/js/public/main.js` — entry Vue 3 + Vue Router (no Inertia)
- `vite.config.js` — registra el entry adicional en `input`

**Routes del SPA (Vue Router con `createWebHistory('/menu')`):**
```
/menu/{tenant}                       → BranchSelector.vue
/menu/{tenant}/s/{branchId}          → MenuHome.vue       ← única pantalla de menú
/menu/{tenant}/s/{branchId}/cart     → Cart.vue
/menu/{tenant}/s/{branchId}/checkout → Checkout.vue
/menu/{tenant}/s/{branchId}/ok/{saleId} → Confirmed.vue
```

**Componentes clave:**

- **`BranchSelector.vue`** — lista de sucursales. Pide geoloc (opt-in) y ordena por Haversine local (sin llamar al server). Clic → `/s/{branchId}`. Si el tenant tiene 1 sola sucursal, redirect automático.

- **`MenuHome.vue`** — única pantalla de menú. Nav sticky superior con categorías (scroll-to + `IntersectionObserver` para highlight). Grid de productos consecutivos por categoría. Buscador filtra in-place. `is_open=false` → grayscale global + botones "Agregar" disabled.

- **`ProductModal.vue`** — modal sobre MenuHome. Cantidad (respeta `unit_type`: kg acepta decimales, piece/cut enteros), presentation selector si aplica, campo `notes` libre, botón "Agregar al carrito". Cerrar no sale del menú.

- **`CartBar.vue`** — barra flotante inferior. Visible solo si hay items. Muestra "X productos · $Y · Ver carrito".

- **`Cart.vue`** — edición: cantidad, notas, eliminar. Botón "Continuar al checkout".

- **`Checkout.vue`** — selector pickup/delivery → si delivery: picker en mapa (integra `@googlemaps/js-api-loader` ya en dependencies) + dirección + llamada a `/delivery/quote`. Inputs `contact_name`, `contact_phone`. Selector `payment_method` de los disponibles. Campo `cart_note` opcional. Campo oculto `honeypot`. Botón "Confirmar pedido" → `POST /orders`.

- **`Confirmed.vue`** — pantalla final con folio, total, botón grande **"Abrir WhatsApp"** con `:href="whatsapp_url"` (binding de atributo, NUNCA `v-html`) para permitir el tap del usuario y evitar el bloqueo de popup en iOS Safari.

**Estado (composables, no Pinia — para alinear con CLAUDE.md):**
- `useTenant()` — datos del tenant + sucursales, cache en memoria + localStorage TTL 1h
- `useMenu(branchId)` — categorías + productos de la sucursal actual
- `useCart(branchId)` — persistido en `localStorage` 90 días; clave por `branchId`. Al cambiar de sucursal el carrito se resetea
- `useContact()` — `contact_name`, `contact_phone`, última dirección, persistido en `localStorage` para auto-llenar checkout en pedidos repetidos

### 5. Admin-empresa — configuración

En `resources/js/Pages/Empresa/Sucursales/Edit.vue` se agrega una sección colapsable **"Pedidos online"** con:

| Campo | Control |
|-------|---------|
| `online_ordering_enabled` | Toggle maestro; si off, colapsa el resto |
| `delivery_enabled`, `pickup_enabled` | Checkboxes |
| `latitude`, `longitude` | `MapPicker.vue` existente (ya se usa para `branches.latitude/longitude`) |
| `public_phone` | Input con validación E.164, normalizado al guardar |
| `delivery_tiers` | Tabla editable filas `[max_km] [fee]` + botón "Agregar rango" |
| `min_order_amount` | Input decimal opcional |
| `hours` | Grid 7 filas × día (checkbox `abierto` + inputs `open`/`close`) |

**`visible_online` por producto** va en el form de productos existente (`resources/js/Pages/Sucursal/Productos/Edit.vue` y `.../Create.vue`) — toggle simple junto a `visibility`, etiquetado claramente "Visible en menú web".

**Validación backend** (en el controller de edición de sucursal de admin-empresa):
- `delivery_tiers` ordenados ascendente por `max_km`
- Cada tier `max_km > 0`, `fee >= 0`
- Si `delivery_enabled=true`, exige `latitude`, `longitude`, `public_phone`, y al menos 1 tier
- Si `online_ordering_enabled=true`, exige `public_phone`
- `public_phone` se normaliza a E.164 al guardar (helper único reutilizado por el flujo público)

**Observer `BranchObserver::saved()`** — recomputa `max_delivery_km = max(delivery_tiers[].max_km)` y lo persiste en el mismo save (evita inconsistencias si el admin-empresa edita los tiers sin re-guardar max_delivery_km).

### 6. Admin-sucursal — Workbench

Cambios al `Workbench.vue` existente:

1. Las ventas con `origin='web'` y `status='pending'` se renderizan con un **badge naranja "Pedido web — Pendiente"**
2. Dos botones nuevos visibles sólo cuando `origin='web' && status='pending'`:
   - **Aceptar** → `PATCH /workbench/ventas/{sale}/estado` con body `{status:'active'}` (transición permitida por `SaleStatus::Pending->allowedTransitions() = [Active, Cancelled]`)
   - **Rechazar** → modal pidiendo motivo → `PATCH` con `{status:'cancelled', cancel_reason: '...'}`
3. La tarjeta muestra info extra: `contact_name`, `contact_phone` (link `tel:`), `delivery_address` si aplica, `items[].notes`, `cart_note`, `payment_method` elegido, `delivery_fee`
4. Una vez aceptada, sigue el flujo normal — el cajero la cobra como cualquier otra venta

**Verificación de `WorkbenchController::updateStatus`**: ya soporta `status` y `cancel_reason` según el enum. Revisar durante implementación si acepta `cancel_reason` desde el body o si hay que extenderlo.

### 7. Ajustes al payload de broadcast

`NewExternalSale::broadcastWith()` actualmente devuelve `SaleResource::make($this->sale)->toArray(request())`. `SaleResource` actual **no incluye** `branch_id`, `customer_id`, `contact_name`, `contact_phone`, `delivery_*`, `cart_note`, ni los `items[].notes`.

**Cambios:**
1. Extender `SaleResource` para incluir (todos opcionales/null-safe):
   - `branch_id`, `customer_id`
   - `contact_name`, `contact_phone`
   - `delivery_type`, `delivery_address`, `delivery_distance_km`, `delivery_fee`
   - `cart_note`
2. Extender `SaleItemResource` para incluir `notes`
3. Verificar que el Workbench actual no se rompa con los campos extra (JsonResource tolera campos adicionales; los listeners existentes los ignoran)

Estos cambios son aditivos y no rompen consumidores actuales de `SaleResource` (báscula, API, etc.).

### 8. Servicios nuevos

- **`App\Services\DeliveryFeeService`**
  - `haversineKm($lat1, $lng1, $lat2, $lng2): float`
  - `quote(Branch $branch, float $custLat, float $custLng): array` — pre-filter + Google Matrix call + cache 24h
  - `tierFee(array $tiers, float $distanceKm): ?array` — retorna `['tier_index', 'fee']` o null si fuera de rango
- **`App\Services\WhatsappMessageService`**
  - `buildOrderText(Sale $sale): string` — formatea el mensaje
  - `buildUrl(string $phoneE164, string $text): string` — `https://wa.me/{digitos}?text={urlencode}`
- **`App\Services\PhoneNormalizer`**
  - `normalize(string $input): string` — normaliza a E.164 mexicana por defecto (`+52` si llega sin prefijo). Helper compartido entre admin-empresa y flow público.

## Consideraciones operativas

### Google Distance Matrix

- **Costo**: $5 USD por cada 1000 elementos. Típicamente 1 elemento por quote
- **Cache**: Redis con clave `matrix:{round(branch_lat,4)},{round(branch_lng,4)}:{round(cust_lat,4)},{round(cust_lng,4)}` TTL 24h. Redondear a 4 decimales ≈ 11 m de precisión, suficiente para tarifas por km
- **Pre-filter Haversine**: si distancia euclídea > `max_delivery_km × 1.5`, rechazar sin llamar Google
- **Fallback si Google falla**: 503 con `{error:'quote_unavailable'}`. Mejor rechazar que cobrar mal. No estimar con Haversine para evitar disparidad
- **API key**: nueva `GOOGLE_MATRIX_API_KEY` en `.env` (distinta de la pública `VITE_GOOGLE_MAPS_KEY`). Restringir por IP en Google Cloud Console

### Rate limiting y anti-abuso

- **Throttling Laravel**: grupo `throttle:60,1`; `POST /delivery/quote` `throttle:20,1`; `POST /orders` `throttle:10,1`
- **Honeypot**: `201` con folio ficticio (respuesta idéntica a éxito) si el campo viene con valor. No revela la trampa
- **Bloqueo suave por teléfono**: 3+ ventas canceladas `origin='web'` en 24h → 429
- **Validación de formato de teléfono**: regex `^\+?[0-9]{10,15}$`; normalización previa a persistir

### WhatsApp (`wa.me`)

- Es solo un deep link (no WhatsApp Business API oficial)
- Texto generado server-side, viaja al cliente en la respuesta de `/orders` (filosofía "server manda")
- `Confirmed.vue` NO hace `window.open` automático; muestra botón grande con `:href="whatsapp_url"` para que el tap del usuario abra WhatsApp (bypass bloqueo popup iOS)
- **XSS**: `whatsapp_url` SIEMPRE se renderiza via `:href`, nunca con `v-html`. El texto del mensaje usa `encodeURIComponent` server-side antes de concatenar
- **Límite de URL**: `wa.me` tolera ~4 KB de texto. Si un carrito con muchos items con notas excede ese límite, truncar el texto (ordenar por longitud de nota ascendente y cortar notas si es necesario, siempre incluir el folio y el total). `WhatsappMessageService` maneja esto.

### Reverb

- Cero código nuevo del lado del admin. Canal `sucursal.{branchId}` y evento `NewExternalSale` ya funcionan para básculas; se reusan tal cual
- Si Reverb está caído, el pedido sigue guardado en DB. Admin lo verá al refrescar Workbench
- El payload extendido (sección 7) incluye los campos que el Workbench necesita para renderizar pedidos web sin re-fetch

### Multi-tenancy

- `BelongsToTenant` se dispara automáticamente al crear `Sale`/`SaleItem`/`Customer` porque `app('tenant')` está establecido por `ResolvePublicTenant`
- Validación extra en `OrderController`: verificar que el `{branch}` del path pertenezca al `$tenant` antes de crear nada (defensa en profundidad)

## Seguridad

- **Anti-tampering**: el backend ignora `unit_price`, `subtotal`, `delivery_fee`, `total` del cliente. Todo se recalcula dentro de la transacción
- **Snapshots**: `SaleItems` guarda `product_name`, `unit_price`, `original_unit_price`. Cambios futuros al catálogo no alteran reportes históricos
- **Sin sesión = sin CSRF attacks en flujo público**: el grupo `api` está desacoplado de Sanctum. Verificar en `bootstrap/app.php` durante implementación
- **Tenant slug regex**: todas las rutas públicas usan `->where('tenantSlug', '[a-z0-9-]+')` para rechazar inputs extraños antes de la DB
- **Branch cross-tenant**: el `OrderController` valida que `$branch->tenant_id === app('tenant')->id` antes de aceptar el pedido
- **Soft-delete de productos**: la query valida `whereNull('deleted_at')` explícitamente aunque el trait ya lo aplique — doble protección ante `withoutGlobalScopes()`
- **CORS**: si en el futuro la SPA se sirve desde dominio distinto al backend, configurar `config/cors.php` para permitir sólo el dominio del tenant

## Testing

- **Feature** (`tests/Feature/Public/`):
  - `CreateOrderTest` — happy path, anti-tampering de precios, validación de productos (incluyendo soft-deleted), delivery fee calc, min order, closed branch, cross-tenant branch rejection
  - `DeliveryQuoteTest` — Haversine pre-filter, Google Matrix mock, cache hit, out of range
  - `MenuTest` — sólo `visible_online=true`, respeto de `status='active'`, exclusión de productos borrados
  - `HoneypotTest` — 201 fake success sin crear sale
  - `PhoneBlockTest` — 429 tras 3 cancelaciones en 24h
- **Unit**:
  - `WhatsappMessageServiceTest` — formato con delivery/pickup/notas/cart_note/truncado
  - `DeliveryFeeServiceTest` — tier selection edge cases, pre-filter
  - `PhoneNormalizerTest` — entradas mexicanas con/sin prefijo

## Rollout

1. Migraciones (todas los campos son nullable/default → cero downtime)
   - Customers: deduplicación previa al índice único (seed de prod actual puede tener duplicados); script de preparación antes del índice
2. Modelos + extensión de `SaleResource`/`SaleItemResource`
3. Servicios (`DeliveryFeeService`, `WhatsappMessageService`, `PhoneNormalizer`)
4. Endpoints públicos `+` middleware `ResolvePublicTenant`
5. Admin-empresa UI (config de sucursal + `visible_online` en productos)
6. Workbench: badge + acciones Aceptar/Rechazar
7. SPA pública (nuevo entry + Vue Router + componentes)
8. Observer `BranchObserver` para `max_delivery_km`
9. Tests feature y unit
10. Activación gradual: primer tenant con `online_ordering_enabled=true`, luego escalar

## Fuera de alcance (v2+)

- Cupones / códigos promocionales
- Modifiers por producto
- Pedidos programados para hora futura
- Comprobante de transferencia (foto)
- Kanban dedicado — reusa Workbench
- WhatsApp Business API oficial
- Build per-tenant con `VITE_TENANT_TOKEN`
- Notificaciones push al admin cuando no está en el Workbench
- Integración con Stripe/Conekta para cobro online real
- Tracking del repartidor en tiempo real
- Paridad del campo `notes` en `sale_items` para el flujo de báscula (actual `Api\SaleController` no lo persiste — decisión deliberada para no cambiar contrato de la báscula ahora)

## Checklist del diseño

- [x] Pedido se crea en DB antes de WhatsApp — cero pedidos fantasma
- [x] Server recalcula todo — anti-tampering estricto
- [x] Snapshot en `SaleItems` — ya existe (`original_unit_price`, `product_name`)
- [x] Reusa `Sale`, `SaleItem`, `Customer`, Workbench, Reverb
- [x] Single-page menu con modal — cero profundidad de navegación
- [x] Notas por producto — `sale_items.notes`
- [x] Admin-empresa controla visibilidad y config de envío
- [x] Admin-sucursal acepta/rechaza desde Workbench normal
- [x] Horarios opcionales — no bloquean onboarding
- [x] Rate limiting + honeypot + bloqueo por teléfono — anti-abuso
- [x] Cache de Google Matrix — control de costos
- [x] Customer con índice único `(tenant_id, branch_id, phone)` — atomicidad
- [x] Prefijo `/menu/` — sin colisión con rutas del grupo `{tenant}`
- [x] `visible_online` independiente de `visibility` existente — control granular
- [x] Composables (no Pinia) — alineado con CLAUDE.md
- [x] SaleResource extendido — Workbench renderiza pedidos web sin re-fetch
- [x] Soft-deletes de productos explícitos en query pública
- [x] `latitude`/`longitude` existentes reutilizados (no duplicados)
- [x] Phone normalizer compartido entre admin y flujo público


gcloud services api-keys create --project=carniceria-el-puebla --display-name="Carniceria SaaS Maps"--allowed-referrers="https://backend-main-98d0p8.laravel.cloud/*","http://localhost:*/*","http://127.0.0.1:*/*"--api-target=service=maps-backend.googleapis.com--api-target=service=distance-matrix-backend.googleapis.com--api-target=service=places-backend.googleapis.com --api-target=service=geocoding-backend.googleapis.com