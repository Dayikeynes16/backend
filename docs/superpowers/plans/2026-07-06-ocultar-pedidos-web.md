# Ocultar Pedidos Web tras `FEATURE_WEB_ORDERS` — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apagar de forma total y reversible el módulo Pedidos Web / Menú Online (UI interna + rutas públicas) detrás de un flag global `FEATURE_WEB_ORDERS`, sin borrar código ni datos.

**Architecture:** Un flag en `config/features.php` leído de env. Backend: las rutas exclusivas del módulo se registran solo si el flag está ON (404 natural + Ziggy no las expone). Frontend: prop Inertia global `features.webOrders` consumida con `v-if`. Se conserva el renderizado pasivo de datos históricos (badges de ventas `origin='web'`).

**Tech Stack:** Laravel 13 (Sail), Inertia v2 + Vue 3, PHPUnit 12, Ziggy.

**Spec:** `docs/superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md` — leerla antes de empezar.

**Regla transversal:** todos los comandos van con `vendor/bin/sail`. Tras tocar PHP: `vendor/bin/sail bin pint --dirty --format agent`. NO tocar: `workbench.update-status`, `caja.update-status`, `whatsapp-link`, enum `SaleStatus`, scope `accountable()`, servicios, migraciones, seeders, `vite.config.js`.

---

### Task 1: Flag de configuración + envs

**Files:**
- Create: `config/features.php`
- Modify: `.env.example`, `.env`, `phpunit.xml`

- [ ] **Step 1: Crear `config/features.php`**

```php
<?php

/*
|--------------------------------------------------------------------------
| Feature flags globales de la aplicación
|--------------------------------------------------------------------------
| Apagan módulos completos (rutas + UI) sin borrar código ni datos.
| Ver docs/superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md
*/

return [

    // Pedidos web / menú online público. OFF: la SPA /menu, la API pública,
    // el menú QR, Personalización y la vinculación pedido↔venta no se
    // registran; el panel oculta sus superficies. Reactivar: poner true
    // en .env + `sail artisan config:clear`.
    'web_orders' => env('FEATURE_WEB_ORDERS', false),

];
```

- [ ] **Step 2: Agregar la variable a `.env.example` y `.env`**

En `.env.example`, junto al bloque de OpenAI (línea ~70), agregar:

```dotenv
# Feature flags — ver config/features.php
FEATURE_WEB_ORDERS=false
```

En `.env` (local) agregar la misma línea `FEATURE_WEB_ORDERS=false`.

- [ ] **Step 3: Fijar el flag ON para la suite existente en `phpunit.xml`**

Tras la línea 33 (`NIGHTWATCH_ENABLED`), agregar:

```xml
        <env name="FEATURE_WEB_ORDERS" value="true"/>
```

Esto garantiza que las ~16 suites existentes del módulo (LinkOrderTest, MenuQrTest, PersonalizacionControllerTest, etc.) sigan corriendo sin cambios.

- [ ] **Step 4: Verificar que la config carga**

Run: `vendor/bin/sail artisan config:clear && vendor/bin/sail artisan tinker --execute 'var_dump(config("features.web_orders"));'`
Expected: `bool(false)` (el `.env` local ya lo tiene en false).

- [ ] **Step 5: Commit**

```bash
git add config/features.php .env.example phpunit.xml
git commit -m "feat(flags): config/features.php con FEATURE_WEB_ORDERS (default off)"
```

---

### Task 2: Prop Inertia global `features`

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php:53` (tras el bloque `auth`)
- Test: `tests/Feature/WebOrdersFeatureFlagEnabledTest.php` (nuevo)

- [ ] **Step 1: Escribir el test (flag ON — el estado de phpunit.xml)**

Crear `tests/Feature/WebOrdersFeatureFlagEnabledTest.php`. Para el setup de tenant/branch/usuario replicar el patrón de `tests/Feature/Http/Sucursal/MenuQrTest.php` (mismo factory/seeder de roles):

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebOrdersFeatureFlagEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_order_routes_are_registered_when_flag_is_on(): void
    {
        $this->assertTrue(Route::has('public.menu'));
        $this->assertTrue(Route::has('api.public.orders.store'));
        $this->assertTrue(Route::has('sucursal.menu-online'));
        $this->assertTrue(Route::has('empresa.personalizacion'));
        $this->assertTrue(Route::has('sucursal.workbench.link-order'));
        $this->assertTrue(Route::has('caja.link-order'));
    }

    public function test_features_prop_is_shared_as_true(): void
    {
        // Mismo setup de tenant/branch/admin-sucursal que MenuQrTest.
        [$tenant, $branch, $user] = $this->makeTenantBranchAndAdminSucursal();

        $this->actingAs($user)
            ->get(route('sucursal.dashboard', $tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('features.webOrders', true));
    }
}
```

Nota: si `makeTenantBranchAndAdminSucursal()` no existe como helper, copiar inline el setup exacto de `MenuQrTest` (RoleSeeder + factories de Tenant/Branch/User con rol `admin-sucursal`).

- [ ] **Step 2: Correr el test — debe fallar el de la prop**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlagEnabledTest`
Expected: FAIL — `features.webOrders` no existe en props (el de rutas pasa porque aún no gateamos nada).

- [ ] **Step 3: Agregar la prop en `HandleInertiaRequests::share()`**

Tras el cierre del bloque `'auth' => [...]` (línea 53), antes de `'flash'`:

```php
            'features' => [
                'webOrders' => (bool) config('features.web_orders'),
            ],
```

- [ ] **Step 4: Correr el test — debe pasar**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlagEnabledTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/WebOrdersFeatureFlagEnabledTest.php
git commit -m "feat(flags): prop Inertia global features.webOrders"
```

---

### Task 3: Gate de rutas públicas (SPA + API)

**Files:**
- Modify: `routes/web.php:97-101`, `routes/api.php:143-155`
- Test: `tests/Feature/WebOrdersFeatureFlagDisabledTest.php` (nuevo)

- [ ] **Step 1: Escribir el test del estado OFF**

El truco: `config([...])` en runtime NO des-registra rutas; hay que apagar el env **antes** de que bootee la app del test:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebOrdersFeatureFlagDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FEATURE_WEB_ORDERS=false');
        $_ENV['FEATURE_WEB_ORDERS'] = 'false';
        $_SERVER['FEATURE_WEB_ORDERS'] = 'false';
        parent::setUp(); // bootea la app leyendo el env ya apagado
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('FEATURE_WEB_ORDERS');
        unset($_ENV['FEATURE_WEB_ORDERS'], $_SERVER['FEATURE_WEB_ORDERS']);
    }

    public function test_public_menu_spa_returns_404(): void
    {
        $this->get('/menu/el-toro')->assertNotFound();
    }

    public function test_public_api_returns_404(): void
    {
        $this->getJson('/api/public/el-toro')->assertNotFound();
        $this->postJson('/api/public/el-toro/branches/1/orders', [])->assertNotFound();
    }

    public function test_panel_web_order_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('public.menu'));
        $this->assertFalse(Route::has('api.public.tenant.show'));
        $this->assertFalse(Route::has('api.public.menu'));
        $this->assertFalse(Route::has('api.public.delivery.quote'));
        $this->assertFalse(Route::has('api.public.orders.store'));
    }

    public function test_unrelated_routes_still_exist(): void
    {
        $this->assertTrue(Route::has('sucursal.workbench.update-status'));
        $this->assertTrue(Route::has('caja.update-status'));
        $this->assertTrue(Route::has('caja.whatsapp-link'));
    }
}
```

(Los asserts de rutas del panel — menú QR, personalización, vinculación — se agregan en la Task 4.)

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlagDisabledTest`
Expected: FAIL — `/menu/el-toro` responde 200 y las rutas existen.

- [ ] **Step 3: Envolver la ruta SPA en `routes/web.php`**

Reemplazar las líneas 97-101:

```php
// Public SPA for online ordering. Reserved '/menu' prefix — no collision with tenant routes.
if (config('features.web_orders')) {
    Route::get('/menu/{tenantSlug}/{any?}', fn () => view('public-spa'))
        ->where('tenantSlug', '[a-z0-9-]+')
        ->where('any', '.*')
        ->name('public.menu');
}
```

- [ ] **Step 4: Envolver el grupo público en `routes/api.php` (líneas 143-155)**

```php
// Public endpoints for online ordering SPA. No auth. Rate-limited.
if (config('features.web_orders')) {
    Route::prefix('public/{tenantSlug}')
        ->where(['tenantSlug' => '[a-z0-9-]+'])
        ->middleware(['resolve.public.tenant', 'throttle:60,1'])
        ->group(function () {
            Route::get('/', [PublicTenantController::class, 'show'])->name('api.public.tenant.show');
            Route::get('branches/{branch}/menu', [PublicMenuController::class, 'show'])->name('api.public.menu');
            Route::post('branches/{branch}/delivery/quote', [PublicDeliveryController::class, 'quote'])
                ->middleware('throttle:20,1')
                ->name('api.public.delivery.quote');
            Route::post('branches/{branch}/orders', [PublicOrderController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('api.public.orders.store');
        });
}
```

- [ ] **Step 5: Correr ambos tests de flag — deben pasar**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlag`
Expected: PASS (Enabled + Disabled).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add routes/web.php routes/api.php tests/Feature/WebOrdersFeatureFlagDisabledTest.php
git commit -m "feat(flags): gate de rutas públicas de pedidos web (SPA + API)"
```

---

### Task 4: Gate de rutas del panel

**Files:**
- Modify: `routes/web.php` — 4 bloques: empresa personalización (171-173), sucursal vinculación (327-328 y 334-335), sucursal menú QR (453-454), caja vinculación (507-508 y 515-516)
- Test: ampliar `tests/Feature/WebOrdersFeatureFlagDisabledTest.php`

- [ ] **Step 1: Ampliar el test OFF con las rutas del panel**

Agregar dentro de `test_panel_web_order_routes_are_not_registered()`:

```php
        $this->assertFalse(Route::has('sucursal.menu-online'));
        $this->assertFalse(Route::has('empresa.personalizacion'));
        $this->assertFalse(Route::has('empresa.personalizacion.update'));
        $this->assertFalse(Route::has('empresa.personalizacion.reset'));
        $this->assertFalse(Route::has('sucursal.workbench.pending-web-orders'));
        $this->assertFalse(Route::has('sucursal.workbench.linkable-sales'));
        $this->assertFalse(Route::has('sucursal.workbench.link-order'));
        $this->assertFalse(Route::has('sucursal.workbench.unlink-order'));
        $this->assertFalse(Route::has('caja.pending-web-orders'));
        $this->assertFalse(Route::has('caja.linkable-sales'));
        $this->assertFalse(Route::has('caja.link-order'));
        $this->assertFalse(Route::has('caja.unlink-order'));
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlagDisabledTest`
Expected: FAIL en los asserts nuevos.

- [ ] **Step 3: Envolver los 4 bloques en `routes/web.php`**

**(a) Empresa — personalización (líneas 171-173):**

```php
                if (config('features.web_orders')) {
                    Route::get('personalizacion', [PersonalizacionController::class, 'edit'])->name('personalizacion');
                    Route::post('personalizacion', [PersonalizacionController::class, 'update'])->name('personalizacion.update');
                    Route::post('personalizacion/reset', [PersonalizacionController::class, 'reset'])->name('personalizacion.reset');
                }
```

**(b) Sucursal — vinculación en workbench.** OJO: las 4 rutas NO son contiguas (entre medio están `store`, `cancel`, `reopen`, `update-status`, `request-cancel`, que NO se tocan). Envolver por pares:

Líneas 327-328:

```php
                if (config('features.web_orders')) {
                    Route::get('mesa-de-trabajo/pedidos-pendientes', [WorkbenchController::class, 'pendingWebOrders'])->name('workbench.pending-web-orders');
                    Route::get('mesa-de-trabajo/ventas-vinculables', [WorkbenchController::class, 'linkableSales'])->name('workbench.linkable-sales');
                }
```

Líneas 334-335:

```php
                if (config('features.web_orders')) {
                    Route::post('mesa-de-trabajo/ventas/{sale}/vincular-pedido', [WorkbenchController::class, 'linkOrder'])->name('workbench.link-order');
                    Route::delete('mesa-de-trabajo/ventas/{sale}/vincular-pedido', [WorkbenchController::class, 'unlinkOrder'])->name('workbench.unlink-order');
                }
```

**(c) Sucursal — menú QR (líneas 453-454):**

```php
                // Menú online (QR + link público)
                if (config('features.web_orders')) {
                    Route::get('menu-online', [MenuQrController::class, 'show'])->name('menu-online');
                }
```

**(d) Caja — vinculación.** Igual que en sucursal, por pares (507-508 y 515-516), sin tocar lo de en medio:

```php
                if (config('features.web_orders')) {
                    Route::get('pedidos-pendientes', [CajaWorkbenchController::class, 'pendingWebOrders'])->name('pending-web-orders');
                    Route::get('ventas-vinculables', [CajaWorkbenchController::class, 'linkableSales'])->name('linkable-sales');
                }
```

```php
                if (config('features.web_orders')) {
                    Route::post('ventas/{sale}/vincular-pedido', [CajaWorkbenchController::class, 'linkOrder'])->name('link-order');
                    Route::delete('ventas/{sale}/vincular-pedido', [CajaWorkbenchController::class, 'unlinkOrder'])->name('unlink-order');
                }
```

- [ ] **Step 4: Correr flag tests + suites del módulo — deben pasar**

Run: `vendor/bin/sail artisan test --compact --filter=WebOrdersFeatureFlag`
Expected: PASS.

Run: `vendor/bin/sail artisan test --compact --filter='LinkOrderTest|UnlinkOrderTest|PendingWebOrdersTest|LinkableSalesTest|EndToEndLinkFlowTest|MenuQrTest|PersonalizacionControllerTest'`
Expected: PASS (corren con flag ON de phpunit.xml).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add routes/web.php tests/Feature/WebOrdersFeatureFlagDisabledTest.php
git commit -m "feat(flags): gate de rutas del panel (menu QR, personalización, vinculación)"
```

---

### Task 5: Frontend — sidebars

**Files:**
- Modify: `resources/js/Layouts/SucursalLayout.vue:38`, `resources/js/Layouts/EmpresaLayout.vue:11-25`

- [ ] **Step 1: SucursalLayout — filtrar "Menú online"**

Reemplazar la línea 38 (`const navLinks = computed(() => baseNavLinks);`):

```js
const webOrders = computed(() => !!page.props.features?.webOrders);
const navLinks = computed(() =>
    baseNavLinks.filter((l) => l.route !== 'sucursal.menu-online' || webOrders.value)
);
```

- [ ] **Step 2: EmpresaLayout — filtrar "Personalizacion"**

Renombrar el array `navLinks` (línea 11) a `baseNavLinks` y agregar debajo (ya hay `computed` y `page` importados):

```js
const webOrders = computed(() => !!page.props.features?.webOrders);
const navLinks = computed(() =>
    baseNavLinks.filter((l) => l.route !== 'empresa.personalizacion' || webOrders.value)
);
```

Verificar que el template use `navLinks` (con `.value` implícito en template) — no requiere más cambios.

- [ ] **Step 3: Verificar en build**

Run: `vendor/bin/sail npm run build`
Expected: build OK sin errores.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/SucursalLayout.vue resources/js/Layouts/EmpresaLayout.vue
git commit -m "feat(flags): ocultar Menú online y Personalización del sidebar tras flag"
```

---

### Task 6: Frontend — Sucursales (Edit / Index / Show)

**Files:**
- Modify: `resources/js/Pages/Empresa/Sucursales/Edit.vue`, `Index.vue`, `Show.vue`

En cada archivo, agregar al `<script setup>` (con los imports que falten — `computed` de vue, `usePage` de `@inertiajs/vue3`):

```js
const webOrders = computed(() => !!usePage().props.features?.webOrders);
```

- [ ] **Step 1: Edit.vue — ocultar secciones**

Los campos permanecen intactos en el `useForm` (viajan con sus valores del servidor; la validación backend no cambia). Solo se ocultan bloques de template:

- Sección **"Pedidos en línea"** completa (líneas 186-262, incluido el `v-else` de módulo apagado): envolver el contenedor de la sección con `v-if="webOrders"`.
- Sección **"Horarios de atención"** (líneas 173-184): `v-if="webOrders"` en el contenedor.
- Campo **`public_phone`**: en el uso de `PhoneFields` (líneas 137-143), pasar prop para ocultarlo o envolver solo el campo público. Si `PhoneFields` renderiza ambos teléfonos juntos, agregarle una prop `showPublicPhone` (default `true`) y pasarle `:show-public-phone="webOrders"`; dentro del componente, `v-if="showPublicPhone"` en el bloque del teléfono público.

- [ ] **Step 2: Index.vue — ocultar badges**

- Badge "Menú online activo/apagado" (líneas 135-139): `v-if="webOrders"` (combinar con la condición existente si la hay: `v-if="webOrders && ..."`).
- Chips 🚚 Envío / 🏪 Recolección (142-145): igual.
- Resumen de horarios (152) y teléfono público (162-164): igual.

- [ ] **Step 3: Show.vue — ocultar vista previa de config online**

Envolver los bloques que muestran `hours`, `delivery_tiers`, `onlineEnabled`, `deliveryEnabled` con `v-if="webOrders"`.

- [ ] **Step 4: Verificar que la edición de sucursal sigue guardando**

Run: `vendor/bin/sail artisan test --compact --filter=SucursalControllerTest`
Expected: PASS (la validación backend no cambió).

Run: `vendor/bin/sail npm run build`
Expected: build OK.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Empresa/Sucursales/ resources/js/Components/
git commit -m "feat(flags): ocultar config de pedidos en línea en Sucursales tras flag"
```

---

### Task 7: Frontend — Productos (`visible_online`)

**Files:**
- Modify: `resources/js/Pages/Sucursal/Productos/Create.vue:304`, `Edit.vue:299`, `Index.vue:290-293`, `resources/js/Components/Productos/ProductDetailModal.vue:302-311`

En cada archivo agregar el mismo helper `webOrders` del Task 6 (en `ProductDetailModal` puede llegar más limpio como `usePage()` directo).

- [ ] **Step 1: Create.vue y Edit.vue — ocultar checkbox**

Envolver el bloque del checkbox "Visible en menú online" (Create:304, Edit:299) con `v-if="webOrders"`. El campo `visible_online` permanece en el `useForm` con su valor por defecto/del servidor.

- [ ] **Step 2: Index.vue — ocultar badge "Online"**

Línea 290-293: cambiar `v-if="p.visible_online"` por `v-if="webOrders && p.visible_online"`.

- [ ] **Step 3: ProductDetailModal.vue — ocultar toggle rápido**

Envolver el bloque del toggle (líneas 302-311) con `v-if="webOrders"`. No tocar el `router.patch` (queda inalcanzable con el flag OFF; la ruta `sucursal.productos.quick` NO se gatea porque es compartida con otros toggles rápidos).

- [ ] **Step 4: Build + commit**

Run: `vendor/bin/sail npm run build` — Expected: OK.

```bash
git add resources/js/Pages/Sucursal/Productos/ resources/js/Components/Productos/ProductDetailModal.vue
git commit -m "feat(flags): ocultar visible_online en Productos tras flag"
```

---

### Task 8: Frontend — SaleDetail (Sucursal y Caja)

**Files:**
- Modify: `resources/js/Components/Sucursal/SaleDetail.vue`, `resources/js/Components/Caja/SaleDetail.vue`

Agregar el helper `webOrders` en ambos. **Regla del spec:** el banner informativo del pedido web (Sucursal 370-393) y los badges pasivos NO se ocultan — solo acciones y modales.

- [ ] **Step 1: Sucursal/SaleDetail.vue**

- Botón "🔗 Vincular pedido web" (261-265): `v-if` existente (`canLinkOrder`) → `v-if="webOrders && canLinkOrder"`.
- Botón "Desvincular pedido" (266-270): igual con su condición (`webOrders && canUnlinkOrder`).
- Botón "🔗 Vincular con venta" dentro del banner (394): `v-if="webOrders"`.
- Render de `LinkOrderModal` (664-669): `v-if="webOrders && showLinkOrderModal"` (ajustar a la condición actual).
- Render de `LinkSaleToOrderModal` (671-674): `v-if="webOrders && sale.origin === 'web'"`.
- Diálogo de desvincular (679-681): condicionar igual con `webOrders &&`.

- [ ] **Step 2: Caja/SaleDetail.vue**

- Botón Vincular (204-207) y Desvincular (211-212): anteponer `webOrders &&` a sus condiciones.
- Render de `LinkOrderModal` (461-466) y diálogo de desvincular (469-471): igual.
- NO tocar `whatsapp-link` (129) — se usa para ventas normales.

- [ ] **Step 3: Build + suites de workbench**

Run: `vendor/bin/sail npm run build` — Expected: OK.
Run: `vendor/bin/sail artisan test --compact --filter='LinkOrderTest|UnlinkOrderTest|EndToEndLinkFlowTest'` — Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Sucursal/SaleDetail.vue resources/js/Components/Caja/SaleDetail.vue
git commit -m "feat(flags): ocultar acciones de vinculación de pedido web tras flag"
```

---

### Task 9: Suite completa + smoke manual

- [ ] **Step 1: Suite completa**

Run: `vendor/bin/sail artisan test --compact`
Expected: PASS total (con flag ON de phpunit.xml + los 2 tests nuevos de flag).

- [ ] **Step 2: Pint final**

Run: `vendor/bin/sail bin pint --dirty --format agent`

- [ ] **Step 3: Smoke manual con flag OFF (el `.env` local ya está en false)**

Run: `vendor/bin/sail artisan config:clear && vendor/bin/sail npm run build`

Checklist en el navegador (revisar consola JS sin errores de Ziggy en cada pantalla):
1. `admin@eltoro.test` → sidebar Empresa SIN "Personalizacion"; Sucursales Index sin badges de menú online; Editar Sucursal sin "Pedidos en línea"/horarios/teléfono público y **guardar cambios funciona**.
2. `sucursal@eltoro.test` → sidebar SIN "Menú online"; Productos sin checkbox/badge/toggle Online; Mesa de Trabajo: abrir una venta normal → sin botón "Vincular pedido web".
3. `cajero@eltoro.test` → mesa de trabajo y cobro de una venta normal completos.
4. `GET /menu/el-toro` → 404. `GET /api/public/el-toro` → 404.
5. (Si hay demo data web) una venta `origin='web'` histórica muestra sus badges pero sin acciones.

- [ ] **Step 4: Commit de ajustes del smoke (si los hubo)**

```bash
git add -A && git commit -m "fix(flags): ajustes de smoke test del ocultamiento de pedidos web"
```

---

### Task 10: Documentación (definition of done)

**Files:**
- Modify: `docs/modulos/pedidos-web.md` (header + sección nueva), `docs/README.md` (tabla estado), `docs/superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md` (header)

- [ ] **Step 1: Header de `docs/modulos/pedidos-web.md`**

Agregar tras el título:

```markdown
> **Estado:** implementado · **OCULTO tras `FEATURE_WEB_ORDERS=false`** desde 2026-07-06 (no se usa; se apagó para despejar el panel). El código y los datos están intactos.
>
> **Reactivar:** `FEATURE_WEB_ORDERS=true` en `.env` + `sail artisan config:clear` (+ `config:cache` si aplica en prod). Diseño del apagado: [`2026-07-06-ocultar-pedidos-web-design.md`](../superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md).
```

- [ ] **Step 2: `docs/README.md` — fila de la tabla "Estado del sistema"**

Cambiar la fila `Pedidos web + emparejamiento...` a:

```markdown
| Pedidos web + emparejamiento con venta de báscula | ✅ Completo · **oculto tras `FEATURE_WEB_ORDERS`** (2026-07-06) |
```

- [ ] **Step 3: Flip del header del spec**

En `docs/superpowers/specs/2026-07-06-ocultar-pedidos-web-design.md`: `**Estado:** Aprobado — pendiente de plan de implementación` → `**Estado:** Implementado (2026-07-06)`.

- [ ] **Step 4: Marcar checkboxes de este plan y commit final**

```bash
git add docs/
git commit -m "docs: pedidos web oculto tras FEATURE_WEB_ORDERS — estado y reactivación"
```
