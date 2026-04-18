# Plan de Implementación — Pedidos Web

**Fecha**: 2026-04-17
**Spec**: `docs/superpowers/specs/2026-04-17-pedidos-web-design.md`
**Estado**: Listo para ejecución

> Cada fase es un bloque de 2–4 horas, independientemente desplegable. Se respeta el orden: migraciones → modelos → resources → servicios → endpoints públicos → admin UI → workbench → SPA pública → observer → tests cross-cutting.

---

## Fase 0 — Pre-requisito: dedup de Customers

La tabla `customers` ya tiene `unique(['phone', 'branch_id'])` pero **no incluye `tenant_id`** ni filtra por `phone IS NOT NULL`. Antes de aplicar el nuevo índice parcial hay que limpiar duplicados.

**Archivos a crear:**
- `app/Console/Commands/DedupCustomers.php` — comando `customers:dedup` que agrupa por `(tenant_id, branch_id, phone)`, deja el más antiguo, reasigna `sales.customer_id` y `customer_product_prices.customer_id` al sobreviviente, borra duplicados. Flag `--dry-run` default true.

**Tests mínimos:**
- `tests/Feature/Console/DedupCustomersTest.php`: con factories crea 3 clientes duplicados con sales/prices; corre `--dry-run=false`; queda 1 cliente y relaciones apuntan al sobreviviente.

**Criterio de hecho:** `php artisan customers:dedup --dry-run=false` corre limpio y reporta `0 duplicados restantes`.

---

## Fase 1 — Migraciones de esquema

Todas nullable/default → cero downtime. Cada migración separada para rollback granular.

**Archivos a crear:**
- `database/migrations/2026_04_17_000001_add_online_ordering_to_branches_table.php` — `online_ordering_enabled, delivery_enabled, pickup_enabled, delivery_tiers (jsonb), max_delivery_km, min_order_amount, public_phone, hours (jsonb)`
- `database/migrations/2026_04_17_000002_add_visible_online_to_products_table.php` — `visible_online bool default false`
- `database/migrations/2026_04_17_000003_add_web_order_fields_to_sales_table.php` — `delivery_type, delivery_address, delivery_lat, delivery_lng, delivery_distance_km, delivery_fee, contact_name, contact_phone, cart_note`
- `database/migrations/2026_04_17_000004_add_notes_to_sale_items_table.php` — `notes text nullable`
- `database/migrations/2026_04_17_000005_replace_customers_phone_unique_index.php` — drop del unique `(phone, branch_id)`, crea `customers_tenant_branch_phone_uniq` parcial `WHERE phone IS NOT NULL`. Header documenta que Fase 0 debe haberse corrido.

**Tests mínimos:**
- `tests/Feature/Migrations/SchemaTest.php`: `RefreshDatabase`, `Schema::hasColumn()` de cada columna nueva, `pg_indexes` muestra el índice parcial.

**Criterio de hecho:** `sail artisan migrate` aplica las 5 sin error; rollback funciona.

---

## Fase 2 — Modelos + BranchObserver

**Archivos a modificar:**
- `app/Models/Branch.php` — Fillable + casts para los 8 campos nuevos (`delivery_tiers => 'array'`, `hours => 'array'`, bools, decimals)
- `app/Models/Product.php` — `visible_online` en Fillable + cast `'boolean'`
- `app/Models/Sale.php` — campos delivery/contact/cart_note en Fillable + casts de decimales
- `app/Models/SaleItem.php` — `notes` en Fillable

**Archivos a crear:**
- `app/Observers/BranchObserver.php` — `saving()` recomputa `max_delivery_km = max(delivery_tiers[].max_km)`
- Registro en `app/Providers/AppServiceProvider.php::boot()` con `Branch::observe(BranchObserver::class)`

**Tests mínimos:**
- `tests/Unit/BranchObserverTest.php`: tiers `[{max_km:2,fee:40},{max_km:5,fee:70}]` → `max_delivery_km=5.0`; tiers vacíos → null.

**Criterio de hecho:** En tinker, actualizar `delivery_tiers` sincroniza `max_delivery_km` automáticamente.

---

## Fase 3 — Extender SaleResource y SaleItemResource

Pre-requisito para que el Workbench renderice pedidos web sin re-fetch (el broadcast lleva el resource al cliente).

**Archivos a modificar:**
- `app/Http/Resources/SaleResource.php` — agrega `branch_id, customer_id, contact_name, contact_phone, delivery_type, delivery_address, delivery_distance_km, delivery_fee (float null-safe), cart_note`
- `app/Http/Resources/SaleItemResource.php` — agrega `notes`

**Tests mínimos:**
- `tests/Unit/SaleResourceTest.php`: crear `Sale` con y sin campos web; el array devuelto contiene las keys nuevas con valores correctos o null.

**Criterio de hecho:** Workbench actual sigue funcionando; consumidores ignoran campos extra.

---

## Fase 4 — Servicios compartidos

**Archivos a crear:**
- `app/Services/PhoneNormalizer.php` — `normalize(string): string` → E.164 (MX default `+52`)
- `app/Services/DeliveryFeeService.php`:
  - `haversineKm(lat1, lng1, lat2, lng2): float`
  - `quote(Branch, custLat, custLng): array` — pre-filter Haversine vs `max_delivery_km * 1.5`; Google Matrix via `Http::get()`; cache 24h Redis con clave `matrix:{round(4)}:{round(4)}`; tira `OutOfRangeException`/`QuoteUnavailableException`
  - `tierFee(tiers, distanceKm): ?array` — primer tier con `max_km >= distance`
- `app/Services/WhatsappMessageService.php`:
  - `buildOrderText(Sale): string` — formato con folio, items (nombre, qty, unit_type, notes), cart_note, delivery/pickup, fee, total, payment_method. Trunca notas si >3.5 KB manteniendo folio y total
  - `buildUrl(phoneE164, text): string` — `https://wa.me/{digitos}?text={urlencoded}`
- `app/Exceptions/Public/OutOfRangeException.php`, `QuoteUnavailableException.php`, `ClosedBranchException.php`
- `config/services.php` — bloque `'google_matrix' => ['key' => env('GOOGLE_MATRIX_API_KEY')]`
- `.env.example` — `GOOGLE_MATRIX_API_KEY=`

**Tests mínimos:**
- `tests/Unit/PhoneNormalizerTest.php`: 10 dígitos → +52…, 12 dígitos sin + → con +, idempotente, limpia espacios/guiones
- `tests/Unit/DeliveryFeeServiceTest.php`: Haversine con coordenadas conocidas; `tierFee()` edge cases; `Http::fake()` mock de Matrix; cache hit segundo request
- `tests/Unit/WhatsappMessageServiceTest.php`: pickup vs delivery; item con notes; cart_note; truncado conservando folio/total

**Criterio de hecho:** Tres servicios con unit tests y disponibles vía DI.

---

## Fase 5 — Middleware ResolvePublicTenant + endpoint de tenant info

Plumbing mínimo para validar el flujo completo end-to-end.

**Archivos a crear:**
- `app/Http/Middleware/ResolvePublicTenant.php` — copia patrón de `ResolveTenant.php`; resuelve por `tenantSlug`; `forgetParameter`; no requiere auth
- Alias `'resolve.public.tenant'` en `bootstrap/app.php`
- `app/Http/Controllers/Public/TenantController.php::show()` — devuelve `{tenant: {name, slug}, branches: [...]}`. Filtra `online_ordering_enabled=true AND status='active'`. **No expone `public_phone`**
- Verificar que grupo `api` no aplica Sanctum/CSRF (default Laravel 13 OK)

**Archivos a modificar:**
- `routes/api.php` — nuevo grupo `public/{tenantSlug}` con `where('tenantSlug','[a-z0-9-]+')`, middleware `['resolve.public.tenant','throttle:60,1']`; primer endpoint `GET /`

**Tests mínimos:**
- `tests/Feature/Public/TenantInfoTest.php`: tenant inactivo → 404; existente → 200 con sucursales filtradas; `online_ordering_enabled=false` no aparece; `public_phone` NO en JSON

**Criterio de hecho:** `curl http://localhost/api/public/{slug}` devuelve las sucursales habilitadas.

---

## Fase 6 — Endpoints públicos de menú y delivery quote

**Archivos a crear:**
- `app/Http/Controllers/Public/MenuController.php::show(Request, int $branchId)` — valida `branch->tenant_id === app('tenant')->id` y `online_ordering_enabled=true`; retorna categorías + productos (`visible_online=true`, `status='active'`, soft-deleted excluidos explícito); incluye `is_open` calculado contra `hours` en TZ del tenant
- `app/Http/Controllers/Public/DeliveryController.php::quote(Request, int $branchId)` — valida `{lat, lng}`; llama `DeliveryFeeService::quote()`; maneja `OutOfRangeException` → 422, `QuoteUnavailableException` → 503

**Archivos a modificar:**
- `routes/api.php` — agrega `GET branches/{branch}/menu` y `POST branches/{branch}/delivery/quote` (`throttle:20,1`)

**Tests mínimos:**
- `tests/Feature/Public/MenuTest.php`: sólo `visible_online=true`; soft-deleted excluidos; cross-tenant → 403/404; `is_open=false` cuando `hours` indica cerrado
- `tests/Feature/Public/DeliveryQuoteTest.php`: pre-filter rechaza out-of-range sin llamar Google; cache hit segundo request; 422 out-of-range; 503 quote unavailable

**Criterio de hecho:** Cliente externo puede listar menú y cotizar envío, con throttling activo.

---

## Fase 7 — Endpoint público de creación de pedido

El núcleo. Depende de Fases 3 (resource extendido) y 4 (servicios).

**Archivos a crear:**
- `app/Http/Controllers/Public/OrderController.php::store()`:
  1. Validación payload (incluye `honeypot` y `contact_phone` regex `^\+?[0-9]{10,15}$`)
  2. Honeypot truthy → 201 con folio fake `S-XXXXX`, sin crear nada
  3. `Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status','active')->findOrFail($branchId)`
  4. Valida `online_ordering_enabled`; `delivery_type` coherente con flags; `hours` vs `now()` TZ tenant → 422 `closed`
  5. Bloqueo suave: ≥3 cancelaciones web de ese `contact_phone` en 24h → 429 `please_contact_branch`
  6. Carga productos con `withoutGlobalScopes` + `branch_id`, `status='active'`, `visible_online=true`, `whereNull('deleted_at')`; faltantes → 422
  7. Recalcula subtotales; si delivery: `DeliveryFeeService::quote(...)` (pickup → fee 0)
  8. `min_order_amount` → 422 `below_minimum`
  9. `DB::transaction` con `pg_advisory_xact_lock(branch_id)`: `Customer::firstOrCreate([branch_id, phone], [name])`; folio incremental; crea `Sale` con `origin='web'`, `status=Pending`, `customer_id`, `contact_*`, delivery, `payment_method`, `cart_note`; `SaleItems` con snapshot + `notes`
  10. `NewExternalSale::dispatch($sale)`
  11. Responde `{sale_id, folio, whatsapp_url, total}`

**Archivos a modificar:**
- `routes/api.php` — `POST branches/{branch}/orders` (`throttle:10,1`)

**Tests mínimos (en `tests/Feature/Public/CreateOrderTest.php`):**
- Happy path pickup y delivery
- Anti-tampering: cliente manda `unit_price` distinto, server lo ignora
- Producto con `visible_online=false` → 422
- Producto soft-deleted → 422
- Cross-tenant → 404/403
- Sucursal cerrada por `hours` → 422 `closed`
- Honeypot truthy → 201 fake, sale count no cambia
- 3 cancelaciones previas → 429
- `firstOrCreate` de customer atómico (no duplicados)
- Broadcast `NewExternalSale` con payload extendido

**Criterio de hecho:** `POST` válido persiste `Sale` con `origin='web' status='pending'`, retorna `whatsapp_url` correcta, dispara broadcast.

---

## Fase 8 — Admin-empresa: config de sucursal + `visible_online` por producto

**Archivos a modificar:**
- `app/Http/Controllers/Empresa/SucursalController.php` — extender `store()` y `update()` con validación de los nuevos campos (tiers ordenados asc; cada `max_km>0` y `fee>=0`; si `delivery_enabled` → exige `latitude/longitude/public_phone` + ≥1 tier; si `online_ordering_enabled` → exige `public_phone`); `public_phone` normalizado vía `PhoneNormalizer` antes de guardar
- `app/Http/Controllers/Sucursal/ProductoController.php` — agrega `visible_online` al validate y al payload en `store`/`update`
- `resources/js/Pages/Empresa/Sucursales/Edit.vue` — sección colapsable **"Pedidos online"**: toggle maestro `online_ordering_enabled`, checkboxes `delivery_enabled`/`pickup_enabled`, `MapPicker` (reusado) para lat/lng, input `public_phone` con validación E.164 client-side, tabla editable de tiers, `min_order_amount`, grid `hours` por día
- `resources/js/Pages/Sucursal/Productos/Create.vue` y `.../Edit.vue` — toggle "Visible en menú web" junto a `visibility`

**Tests mínimos:**
- `tests/Feature/Empresa/SucursalConfigTest.php`: admin-empresa actualiza config online; `delivery_enabled=true` sin lat/lng → 422; tiers desordenados → 422; `public_phone` se normaliza; `BranchObserver` actualiza `max_delivery_km`
- `tests/Feature/Sucursal/ProductoVisibleOnlineTest.php`: admin-sucursal toggle `visible_online`; default `false`

**Criterio de hecho:** Admin-empresa configura pedidos web; admin-sucursal marca productos.

---

## Fase 9 — Workbench: badge + Aceptar/Rechazar

**Archivos a modificar:**
- `app/Http/Controllers/Sucursal/WorkbenchController.php` — verificar que `updateStatus()` ya acepta `cancel_reason` para `Pending → Cancelled` (ya lo hace). Sin cambios funcionales
- `resources/js/Pages/Sucursal/Workbench.vue`:
  - Badge naranja "Pedido web — Pendiente" cuando `sale.origin === 'web' && sale.status === 'pending'`
  - Mostrar `contact_name`, `contact_phone` (link `tel:`), `delivery_address` si delivery, `delivery_fee`, `cart_note`, `payment_method`, items con `notes`
  - Botones **Aceptar** (`PATCH workbench.update-status {status:'active'}`) y **Rechazar** (`CancelSaleDialog` existente → `PATCH {status:'cancelled', cancel_reason}`); visibles sólo si `origin==='web' && status==='pending'`

**Tests mínimos:**
- `tests/Feature/Sucursal/AcceptWebOrderTest.php`: venta `web+pending`; admin acepta → `Active`; cancela → `Cancelled` con motivo; `SaleUpdated` broadcast

**Criterio de hecho:** Workbench recibe venta web vía broadcast, renderiza datos extendidos, acepta/rechaza.

---

## Fase 10 — SPA pública: scaffolding + Vue Router

**Archivos a crear:**
- `resources/views/public-spa.blade.php` — HTML mínimo con `<div id="public-app"></div>` + `@vite(['resources/js/public/main.js'])`
- `resources/js/public/main.js` — entry: Vue + Vue Router, monta `App.vue`
- `resources/js/public/App.vue` — wrapper con `<router-view />`
- `resources/js/public/router.js` — `createRouter({history: createWebHistory('/menu'), routes: [...]})` con las 5 rutas del spec
- `resources/js/public/api.js` — wrapper axios con `baseURL` resuelto dinámicamente
- `resources/js/public/composables/useTenant.js`, `useMenu.js`, `useCart.js`, `useContact.js` — persistencia `localStorage` según TTL

**Archivos a modificar:**
- `vite.config.js` — `input: ['resources/js/app.js', 'resources/js/public/main.js']`
- `routes/web.php` — al inicio (antes del grupo `{tenant}`):
  ```php
  Route::get('/menu/{tenantSlug}/{any?}', fn () => view('public-spa'))
      ->where('tenantSlug', '[a-z0-9-]+')
      ->where('any', '.*')
      ->name('public.menu');
  ```
- `package.json` — confirmar/agregar `vue-router`

**Tests mínimos:**
- `tests/Feature/Public/SpaShellTest.php`: `GET /menu/{slug}` → 200, contiene `<div id="public-app">` y hash Vite
- Smoke manual: `npm run dev` → abrir `/menu/{tenant}` muestra placeholder de cada ruta

**Criterio de hecho:** Vite genera dos bundles; navegar `/menu/{tenant}/...` carga SPA sin colisión con rutas existentes.

---

## Fase 11 — SPA pública: componentes funcionales

**Archivos a crear** (bajo `resources/js/public/views/` y `components/`):
- `views/BranchSelector.vue` — lista sucursales; opt-in geoloc + Haversine local; redirect si sólo 1 sucursal
- `views/MenuHome.vue` — nav sticky categorías con `IntersectionObserver`, grid productos, search local, grayscale + disabled si `is_open=false`
- `components/ProductModal.vue` — sobre MenuHome; cantidad respeta `unit_type`; presentation selector si aplica; textarea `notes`; botón "Agregar al carrito"
- `components/CartBar.vue` — flotante inferior si `cart.items.length > 0`
- `views/Cart.vue` — edición items (qty, notas, eliminar); botón "Continuar"
- `views/Checkout.vue` — selector pickup/delivery; si delivery: `@googlemaps/js-api-loader`, map picker, `POST /delivery/quote`; inputs contact_name/phone; selector payment_method (filtra `branch.payment_methods_enabled`); textarea cart_note; honeypot oculto; botón "Confirmar" → `POST /orders`
- `views/Confirmed.vue` — folio + total + botón grande `:href="whatsapp_url"` (NUNCA `v-html`); `tel:` fallback

**Tests mínimos:**
- Unit tests de composables (Vitest/Jest): `useCart` persiste y resetea por `branchId`; `useContact` auto-llena
- Smoke E2E manual: pedido pickup + delivery en mobile; `whatsapp_url` se abre con texto correcto

**Criterio de hecho:** Cliente arma pedido completo en mobile, recibe deep link WhatsApp; pedido aparece en Workbench en tiempo real.

---

## Fase 12 — Tests cross-cutting y rollout gradual

**Archivos a crear:**
- `tests/Feature/Public/EndToEndOrderFlowTest.php` — happy path completo: cliente → `POST /orders` → broadcast → Workbench → `active` → cobro → `Completed`
- `tests/Feature/Public/HoneypotTest.php` — honeypot truthy → 201 fake, `Sale::count()` intacto
- `tests/Feature/Public/PhoneBlockTest.php` — 3 cancelaciones 24h → 429 al cuarto
- `tests/Feature/Public/RateLimitTest.php` — 11 POST `/orders` en 1 min → último 429
- `tests/Feature/Public/CrossTenantSecurityTest.php` — branch de tenant A con slug de B → 404; producto de otro tenant → 422

**Activación gradual:**
- Smoke en staging con tenant piloto
- Activar `online_ordering_enabled=true` para 1 sucursal real; monitorear cache hit Matrix, 503 quote_unavailable, throttle hits
- Iterar antes de escalar

**Criterio de hecho:** CI verde con cobertura ≥80% en paths nuevos; smoke en staging OK.

---

## Orden y dependencias

```
Fase 0  (Dedup customers — preparación de datos)
   ↓
Fase 1  (Migraciones)  ──────────────► Fase 2  (Modelos + Observer)
                                            ↓
                                       Fase 3  (Resources extendidos)
                                            ↓
                                       Fase 4  (Servicios)
                                            ↓
                          ┌─────────────────┼─────────────────┐
                          ↓                 ↓                 ↓
                       Fase 5         Fase 8 (Admin UI)  Fase 9 (Workbench)
                       (Tenant info)
                          ↓
                       Fase 6 (Menú + quote)
                          ↓
                       Fase 7 (POST /orders)
                          ↓
                       Fase 10 (SPA scaffolding)
                          ↓
                       Fase 11 (SPA componentes)
                          ↓
                       Fase 12 (E2E tests + rollout)
```

Fases 8 y 9 pueden correr en paralelo con 5–7 si hay más de un dev.

---

## Archivos críticos para la implementación

- `app/Http/Controllers/Public/OrderController.php` (nuevo — núcleo del flujo)
- `app/Http/Resources/SaleResource.php` (extender para que Workbench renderice pedidos web vía broadcast)
- `app/Services/DeliveryFeeService.php` (nuevo — pre-filter + Google Matrix + cache)
- `routes/api.php` (registrar grupo `public/{tenantSlug}`)
- `resources/js/public/main.js` (entry SPA pública, raíz del frontend público)
