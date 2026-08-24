# Movimientos — cierre: pruebas y documentación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar el módulo Movimientos listo para mergear: cubrir con pruebas los §7 del spec, escribir la documentación §8, y cerrar la única incoherencia que quedó entre el spec y el código.

**Architecture:** El módulo ya está implementado en el working tree de `main` (24 archivos, sin commitear). Este plan **no añade funcionalidad**: escribe las pruebas que faltan, corrige un evento que el spec daba por existente y no lo está, y publica la documentación. Las pruebas se agrupan por responsabilidad —escrituras, consulta, acceso— en cuatro archivos bajo `tests/Feature/Movimientos/`, para que un fallo diga por sí solo qué capa se rompió.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18, PHPUnit 12, Inertia 2, Vue 3, Sail.

## Global Constraints

- **Punto de partida: la suite entera en verde con 1422 tests.** Cualquier tarea que la baje de ahí está mal terminada.
- **No se toca `/api/v1` con `X-Api-Key`** (la Scale API de las básculas). Movimientos no la roza; si una tarea parece necesitarlo, está mal planteada. `tests/Feature/Api/ScaleLegacyContractTest.php` debe seguir pasando sin modificarse.
- **`sale_item_changes` no se toca.** Conserva su historia desde 2026-05 y sigue alimentando el historial dentro del detalle de una venta. La duplicación con `audit_logs` es deliberada (spec §3).
- **Comandos vía Sail:** `docker compose exec -T laravel.test php artisan test --filter=X`. Formato: `docker compose exec -T laravel.test ./vendor/bin/pint --dirty`.
- **Migraciones ya aplicadas** en la base local (2026-08-23). No hay que volver a correrlas.
- **El Atlas está pospuesto** (decisión 2026-08-23, `CLAUDE.md` raíz): **no** hay que actualizar ningún manifiesto ni correr `validate:architecture`.
- **Working tree compartido con otras sesiones:** nunca `git add -A` ni `git commit --amend`. Cada commit lista sus archivos uno por uno.
- Documentación y textos de UI en español; identificadores de código en inglés.

---

## Estado de partida

Ya está escrito y funcionando (no hay que reimplementarlo):

| Pieza | Archivo |
|---|---|
| 8 eventos nuevos + `saleMovements()` + `isMonetary()` | `app/Enums/AuditEvent.php` |
| 9 métodos de registro + captura de IP/user-agent | `app/Services/AuditLogger.php` |
| Cancelar y reabrir | `app/Observers/SaleAuditObserver.php` |
| Alta/edición/baja de productos | `app/Services/SaleItemEditor.php` |
| Editar y borrar pagos (web y hub) | `app/Http/Controllers/{Sucursal,Api/Hub}/PaymentController.php` |
| Asignar y quitar cliente (tres controladores) | `app/Services/AssignCustomerToSale.php` |
| Consulta agrupada, neto y marca de sospecha | `app/Services/SaleMovementsQuery.php` |
| Pantallas y gate por rol/flag | `app/Http/Controllers/{Empresa,Sucursal}/MovimientosController.php`, `resources/js/**/Movimientos/**` |

Bug ya corregido el 2026-08-23: los cuatro sitios que leían `$payment->method?->value` fallaban porque `Payment` no castea `method` a enum.

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `app/Enums/AuditEvent.php` | **Modificar.** Quitar `PaymentAdded` de `saleMovements()` (Task 1) |
| `app/Services/SaleMovementsQuery.php` | **Modificar.** Quitar `payment_added` de `PAYMENT_EVENTS` (Task 1) |
| `tests/Feature/Movimientos/SaleMovementWritesTest.php` | **Nuevo.** Que cada acción sobre una venta deje su registro con el `amount_effect` correcto (Tasks 2-4) |
| `tests/Feature/Movimientos/HubSaleMovementWritesTest.php` | **Nuevo.** Lo mismo desde la API del hub (Task 3) |
| `tests/Feature/Movimientos/SaleMovementsQueryTest.php` | **Nuevo.** Agrupación, neto, orden y marca de sospecha (Task 6) |
| `tests/Feature/Movimientos/MovimientosAccessTest.php` | **Nuevo.** Quién entra y con qué alcance (Task 7) |
| `docs/modulos/movimientos.md` | **Nuevo.** Doc vivo del módulo (Task 8) |
| `docs/README.md` | **Modificar.** Índice y tabla de estado (Task 8) |
| `docs/modulos/sucursales.md` | **Modificar.** El flag nuevo en la lista (Task 8) |
| `docs/superpowers/specs/2026-08-21-movimientos-design.md` | **Modificar.** Cabecera `Estado:` → Implementado (Task 8) |

---

### Task 1: `payment_added` sale de la lista de eventos de venta

El spec §3 dice que los eventos existentes «`cancelled`, `payment_added`, `payment_cancelled` se reutilizan». Para `cancelled` es cierto. Para `payment_added` **no**: el único sitio que lo escribe es `PurchasePaymentService.php:109`, es decir compras. Cobrar una venta nunca deja un `audit_log`.

Y no debería dejarlo: registrar cada cobro metería en Movimientos todas las ventas del día, que es justo el ruido que la pantalla existe para evitar. La pantalla vigila **lo que se le hace a una venta ya cobrada**, no el cobro.

Consecuencias de dejarlo como está: el filtro por evento ofrece «Registró pago», que no devuelve nada nunca; y `PAYMENT_EVENTS` incluye un evento que jamás llega, lo que hace más difícil leer la regla de sospecha.

**Files:**
- Modify: `app/Enums/AuditEvent.php` (método `saleMovements()`)
- Modify: `app/Services/SaleMovementsQuery.php:29` (constante `PAYMENT_EVENTS`)
- Modify: `docs/superpowers/specs/2026-08-21-movimientos-design.md` (§3, la frase de los eventos reutilizados)

**Interfaces:**
- Produces: `AuditEvent::saleMovements(): list<string>` sin `'payment_added'`. Las Tasks 6 y 7 dependen de esta lista para el filtro `event`.

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/Feature/Movimientos/SaleMovementsQueryTest.php` (el archivo se crea entero en la Task 6; si aún no existe, esta prueba se escribe primero y el resto se le suma después):

```php
public function test_cobrar_una_venta_no_es_un_movimiento(): void
{
    // `payment_added` solo lo escribe el módulo de compras. Si algún día se
    // registrara también al cobrar, esta lista tendría que decidirse a propósito
    // y no por herencia: cada venta cobrada aparecería en la pantalla.
    $this->assertNotContains('payment_added', AuditEvent::saleMovements());
    $this->assertContains('payment_updated', AuditEvent::saleMovements());
    $this->assertContains('payment_deleted', AuditEvent::saleMovements());
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `docker compose exec -T laravel.test php artisan test --filter=test_cobrar_una_venta_no_es_un_movimiento`
Expected: FAIL — `Failed asserting that an array does not contain 'payment_added'`.

- [ ] **Step 3: Quitar el evento de las dos listas**

En `app/Enums/AuditEvent.php`, dentro de `saleMovements()`, dejar el bloque así:

```php
        return array_map(fn (self $e) => $e->value, [
            self::ItemAdded, self::ItemUpdated, self::ItemRemoved,
            // `payment_added` queda fuera a propósito: solo lo escribe el módulo
            // de compras, y registrar cada cobro llenaría la pantalla con todas
            // las ventas del día — el ruido que esta pantalla existe para evitar.
            self::PaymentUpdated, self::PaymentDeleted,
            self::Cancelled, self::Reopened,
            self::CustomerAssigned, self::CustomerRemoved,
        ]);
```

En `app/Services/SaleMovementsQuery.php`:

```php
    private const PAYMENT_EVENTS = ['payment_updated', 'payment_deleted'];
```

- [ ] **Step 4: Corregir el spec**

En `docs/superpowers/specs/2026-08-21-movimientos-design.md` §3, sustituir la frase final del bloque de eventos por:

```markdown
Los existentes `cancelled` y `payment_cancelled` se reutilizan. **`payment_added` no**: hoy solo lo escribe el módulo de compras, y registrar cada cobro de venta llenaría la pantalla con todas las ventas del día (corregido el 2026-08-23).
```

- [ ] **Step 5: Correr el test y la suite completa**

Run: `docker compose exec -T laravel.test php artisan test --compact`
Expected: 1422+ passed, 0 failed.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/AuditEvent.php app/Services/SaleMovementsQuery.php docs/superpowers/specs/2026-08-21-movimientos-design.md tests/Feature/Movimientos/SaleMovementsQueryTest.php
git commit -m "fix(movimientos): cobrar una venta no es un movimiento"
```

---

### Task 2: Los tres eventos de producto quedan registrados

`SaleItemEditor` es el único punto por el que pasan la web y el hub para tocar los productos de una venta, así que las pruebas van contra el servicio: cubren las dos superficies sin montar dos veces el mismo escenario.

**Files:**
- Create: `tests/Feature/Movimientos/SaleMovementWritesTest.php`

**Interfaces:**
- Consumes: `SaleItemEditor::add(Sale, array, ?string, User): SaleItem`, `::update(Sale, SaleItem, array, ?string, User): SaleItem`, `::remove(Sale, SaleItem, string, User): void`.
- Produces: los helpers `activeSale()` y `lastLog()` que reutilizan las Tasks 3, 4 y 5 en este mismo archivo.

- [ ] **Step 1: Escribir el archivo con los tres tests que fallan**

```php
<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Services\SaleItemEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Movimientos: que cada forma de tocar una venta ya cobrada deje su rastro.
 *
 * El `amount_effect` en negativo significa «reduce lo que hay que entregar»:
 * es el número que la pantalla ordena y suma, así que su signo es la prueba
 * que más importa de todas.
 */
class SaleMovementWritesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function activeSale(float $total = 200): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);
    }

    private function lastLog(AuditEvent $event): AuditLog
    {
        $log = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', (new Sale)->getMorphClass())
            ->where('event', $event->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, "No se registró el evento {$event->value}.");

        return $log;
    }

    public function test_agregar_un_producto_suma_su_subtotal(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 50]);

        app(SaleItemEditor::class)->add($sale, [
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 50,
        ], 'Faltaba un producto', $this->adminSucursal);

        $log = $this->lastLog(AuditEvent::ItemAdded);
        $this->assertSame(100.0, (float) $log->amount_effect);
        $this->assertSame($sale->id, $log->auditable_id);
        $this->assertSame($this->branch->id, $log->branch_id);
        $this->assertSame($this->adminSucursal->id, $log->user_id);
        $this->assertSame($product->name, $log->changes['product']);
    }

    public function test_bajar_el_precio_de_un_producto_deja_efecto_negativo(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 100]);
        $editor = app(SaleItemEditor::class);

        $item = $editor->add($sale, [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ], null, $this->adminSucursal);

        $editor->update($sale->fresh(), $item, [
            'quantity' => 1,
            'unit_price' => 60,
        ], 'Ajuste', $this->adminSucursal);

        // 60 − 100: la diferencia es exactamente lo que dejó de entrar.
        $log = $this->lastLog(AuditEvent::ItemUpdated);
        $this->assertSame(-40.0, (float) $log->amount_effect);
        $this->assertArrayHasKey('diff', $log->changes);
    }

    public function test_quitar_un_producto_resta_su_subtotal(): void
    {
        $sale = $this->activeSale();
        $product = $this->makeProduct(['price' => 30]);
        $editor = app(SaleItemEditor::class);

        $item = $editor->add($sale, [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 30,
        ], null, $this->adminSucursal);

        $editor->remove($sale->fresh(), $item, 'Se devolvió', $this->adminSucursal);

        $log = $this->lastLog(AuditEvent::ItemRemoved);
        $this->assertSame(-90.0, (float) $log->amount_effect);
    }
}
```

- [ ] **Step 2: Correr los tests**

Run: `docker compose exec -T laravel.test php artisan test --filter=SaleMovementWritesTest`
Expected: los tres PASS. El código ya está implementado, así que estas pruebas **confirman** el comportamiento; si alguna falla, el fallo es real y hay que arreglar el código, no la prueba.

Si `SaleItemEditor::add()` rechaza la venta por su estado, cambiar `activeSale()` al estado que el editor exija y anotarlo en el docblock del helper.

- [ ] **Step 3: Formato**

Run: `docker compose exec -T laravel.test ./vendor/bin/pint --dirty`

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Movimientos/SaleMovementWritesTest.php
git commit -m "test(movimientos): los cambios de producto dejan su efecto en dinero"
```

---

### Task 3: Editar y borrar un pago deja el monto anterior, desde la web y desde el hub

Es el corazón del spec §1: «editar un pago de \$680 a \$380 y borrar el sobrante cuadra la caja sin dejar huella». Estas dos pruebas son las que demuestran que ya la deja.

**Files:**
- Modify: `tests/Feature/Movimientos/SaleMovementWritesTest.php` (añadir los dos tests de la web)
- Create: `tests/Feature/Movimientos/HubSaleMovementWritesTest.php`

**Interfaces:**
- Consumes: rutas `sucursal.workbench.payment.update` (PUT, campos `amount` y `method`) y `sucursal.workbench.payment.destroy` (DELETE); en el hub, `PUT|DELETE /api/v1/hub/sales/{sale}/payments/{payment}` con token Sanctum.

- [ ] **Step 1: Añadir los dos tests de la web a `SaleMovementWritesTest`**

```php
    public function test_editar_un_pago_guarda_el_monto_anterior(): void
    {
        $sale = $this->activeSale(680);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 680,
        ]);

        $this->actingAs($this->adminSucursal)->put(
            route('sucursal.workbench.payment.update', [$this->tenant->slug, $sale->id, $payment->id]),
            ['amount' => 380, 'method' => 'cash'],
        );

        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        // Sin esta fila, los $300 no existen en ninguna parte: la tabla `payments`
        // solo guarda el monto vigente.
        $this->assertSame([680.0, 380.0], array_map('floatval', $log->changes['amount']));
        $this->assertSame(-300.0, (float) $log->amount_effect);
    }

    public function test_borrar_un_pago_lo_registra_completo_en_negativo(): void
    {
        $sale = $this->activeSale(300);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'transfer',
            'amount' => 300,
        ]);

        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.workbench.payment.destroy', [$this->tenant->slug, $sale->id, $payment->id]),
        );

        $log = $this->lastLog(AuditEvent::PaymentDeleted);
        $this->assertSame(-300.0, (float) $log->amount_effect);
        $this->assertSame('transfer', $log->changes['method']);
    }
```

Añadir `use App\Models\Payment;` a los imports del archivo.

- [ ] **Step 2: Crear el archivo del hub**

El setup (token, turno abierto si hace falta) se copia de `tests/Feature/Api/Hub/SaleAdminActionsApiTest.php`, que ya ejercita estos dos endpoints.

```php
<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Lo mismo que `SaleMovementWritesTest`, pero entrando por la API del hub.
 *
 * Existe por separado porque el hub es la superficie por la que se opera la caja
 * en la sucursal: si sus escrituras no dejaran rastro, la pantalla mentiría
 * justo donde más importa.
 */
class HubSaleMovementWritesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function token(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    private function saleWithPayment(float $amount): array
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $amount,
            'amount_paid' => $amount,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => $amount,
        ]);

        return [$sale, $payment];
    }

    public function test_el_hub_registra_la_edicion_de_un_pago(): void
    {
        [$sale, $payment] = $this->saleWithPayment(500);

        $this->withToken($this->token())
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$payment->id}", [
                'amount' => 200,
                'method' => 'cash',
            ])->assertOk();

        $log = AuditLog::withoutGlobalScopes()
            ->where('event', AuditEvent::PaymentUpdated->value)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(-300.0, (float) $log->amount_effect);
        $this->assertSame($this->branch->id, $log->branch_id);
    }

    public function test_el_hub_registra_el_borrado_de_un_pago(): void
    {
        [$sale, $payment] = $this->saleWithPayment(150);

        $this->withToken($this->token())
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/payments/{$payment->id}")
            ->assertOk();

        $log = AuditLog::withoutGlobalScopes()
            ->where('event', AuditEvent::PaymentDeleted->value)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(-150.0, (float) $log->amount_effect);
    }
}
```

- [ ] **Step 3: Correr los cuatro tests**

Run: `docker compose exec -T laravel.test php artisan test --filter="SaleMovementWritesTest|HubSaleMovementWritesTest"`
Expected: todos PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Movimientos/SaleMovementWritesTest.php tests/Feature/Movimientos/HubSaleMovementWritesTest.php
git commit -m "test(movimientos): editar y borrar un pago deja el monto anterior"
```

---

### Task 4: Cancelar, reabrir y cambiar de cliente

Cancelar sí mueve dinero (el total completo, en negativo). Reabrir y cambiar de cliente **no**: se ven en la lista, en gris, fuera del neto. Esa distinción es lo que se prueba aquí.

**Files:**
- Modify: `tests/Feature/Movimientos/SaleMovementWritesTest.php`

- [ ] **Step 1: Añadir los tres tests**

```php
    public function test_cancelar_una_venta_resta_su_total(): void
    {
        $sale = $this->activeSale(450);
        $sale->forceFill([
            'status' => SaleStatus::Cancelled,
            'cancel_reason' => 'Cliente se arrepintió',
        ])->save();

        $log = $this->lastLog(AuditEvent::Cancelled);
        $this->assertSame(-450.0, (float) $log->amount_effect);
        $this->assertSame('Cliente se arrepintió', $log->changes['reason']);
    }

    public function test_reabrir_una_venta_cobrada_no_mueve_dinero(): void
    {
        $sale = $this->activeSale(120);
        $sale->forceFill(['status' => SaleStatus::Completed, 'completed_at' => now()])->save();

        $sale->forceFill(['status' => SaleStatus::Active])->save();

        // Reabrir habilita mover dinero, no lo mueve: efecto nulo a propósito,
        // para que no ensucie el neto del periodo.
        $log = $this->lastLog(AuditEvent::Reopened);
        $this->assertNull($log->amount_effect);
    }

    public function test_asignar_y_quitar_cliente_se_ven_pero_no_suman(): void
    {
        $sale = $this->activeSale(80);
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Doña Mari',
            'phone' => '5551234567',
            'status' => 'active',
        ]);

        $service = app(AssignCustomerToSale::class);
        $service->execute($sale, $customer->id, $this->branch->id);

        $assigned = $this->lastLog(AuditEvent::CustomerAssigned);
        $this->assertNull($assigned->amount_effect);
        $this->assertSame('Doña Mari', $assigned->changes['customer']);

        $service->execute($sale->fresh(), null, $this->branch->id);

        $removed = $this->lastLog(AuditEvent::CustomerRemoved);
        $this->assertNull($removed->amount_effect);
        $this->assertSame('Doña Mari', $removed->changes['customer']);
    }
```

Añadir a los imports: `use App\Models\Customer;` y `use App\Services\AssignCustomerToSale;`.

- [ ] **Step 2: Correr**

Run: `docker compose exec -T laravel.test php artisan test --filter=SaleMovementWritesTest`
Expected: los seis tests del archivo PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Movimientos/SaleMovementWritesTest.php
git commit -m "test(movimientos): cancelar resta el total; reabrir y cambiar cliente no"
```

---

### Task 5: El contexto del cambio

Sin contexto la pantalla no sirve para lo que se pidió: el dueño necesita reconocer si un cambio hecho con su cuenta salió del equipo de siempre. `AuditLogger::requestContext()` lo resuelve por su cuenta desde el request, y deja los tres campos en `null` cuando no hay request (cola o consola), que es lo correcto.

**Files:**
- Modify: `tests/Feature/Movimientos/SaleMovementWritesTest.php`

- [ ] **Step 1: Añadir los dos tests**

```php
    public function test_el_registro_guarda_desde_donde_se_hizo_el_cambio(): void
    {
        $sale = $this->activeSale(680);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 680,
        ]);

        $this->actingAs($this->adminSucursal)
            ->withServerVariables([
                'REMOTE_ADDR' => '187.190.1.20',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ])
            ->put(
                route('sucursal.workbench.payment.update', [$this->tenant->slug, $sale->id, $payment->id]),
                ['amount' => 380, 'method' => 'cash'],
            );

        $log = $this->lastLog(AuditEvent::PaymentUpdated);
        $this->assertSame('187.190.1.20', $log->ip_address);
        $this->assertStringContainsString('Windows', $log->user_agent);
    }

    public function test_un_cambio_sin_request_no_inventa_contexto(): void
    {
        // El observer también corre desde consola (comandos programados). Ahí no
        // hay equipo del que hablar: null es la respuesta honesta.
        $sale = $this->activeSale(100);
        $sale->forceFill(['status' => SaleStatus::Cancelled, 'cancel_reason' => 'Prueba'])->save();

        $log = $this->lastLog(AuditEvent::Cancelled);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
    }
```

- [ ] **Step 2: Correr**

Run: `docker compose exec -T laravel.test php artisan test --filter=SaleMovementWritesTest`
Expected: los ocho tests PASS.

Si el primero falla porque en el entorno de pruebas `Request::ip()` devuelve `127.0.0.1`, comprobar que `withServerVariables` se aplica antes del `put`; no relajar la aserción a `assertNotNull`, que dejaría pasar una IP inventada.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Movimientos/SaleMovementWritesTest.php
git commit -m "test(movimientos): el registro guarda desde dónde se hizo el cambio"
```

---

### Task 6: La consulta — agrupación, neto, orden y marca de sospecha

Estas pruebas van contra `SaleMovementsQuery` con filas de `audit_logs` creadas a mano: el objetivo es fijar la lectura, y montar las escrituras reales aquí solo añadiría ruido y lentitud. Que las escrituras produzcan esas filas ya lo garantizan las Tasks 2-5.

**Files:**
- Create/Modify: `tests/Feature/Movimientos/SaleMovementsQueryTest.php` (ya contiene el test de la Task 1)

**Interfaces:**
- Consumes: `SaleMovementsQuery::run(array $filters, ?int $forcedBranchId = null, int $perPage = 20): array{sales: LengthAwarePaginator, summary: array}`.

- [ ] **Step 1: Escribir el archivo completo**

```php
<?php

namespace Tests\Feature\Movimientos;

use App\Enums\AuditEvent;
use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Services\SaleMovementsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La lectura de Movimientos: una fila por venta, ordenada por lo que se le quitó.
 */
class SaleMovementsQueryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function sale(float $total = 500): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $total,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    private function log(Sale $sale, AuditEvent $event, ?float $effect, ?Carbon $at = null, ?int $branchId = null): AuditLog
    {
        return AuditLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'auditable_type' => (new Sale)->getMorphClass(),
            'auditable_id' => $sale->id,
            'user_id' => $this->adminSucursal->id,
            'event' => $event->value,
            'changes' => [],
            'amount_effect' => $effect,
            'created_at' => $at ?? now(),
        ]);
    }

    public function test_cobrar_una_venta_no_es_un_movimiento(): void
    {
        $this->assertNotContains('payment_added', AuditEvent::saleMovements());
        $this->assertContains('payment_updated', AuditEvent::saleMovements());
        $this->assertContains('payment_deleted', AuditEvent::saleMovements());
    }

    public function test_agrupa_por_venta_y_cuenta_sus_movimientos(): void
    {
        $sale = $this->sale();
        $this->log($sale, AuditEvent::ItemUpdated, -40);
        $this->log($sale, AuditEvent::PaymentUpdated, -60);

        $result = app(SaleMovementsQuery::class)->run([]);
        $rows = collect($result['sales']->items());

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()['movement_count']);
        $this->assertSame(-100.0, $rows->first()['net_effect']);
    }

    public function test_el_neto_deja_fuera_los_eventos_no_monetarios(): void
    {
        $sale = $this->sale();
        $this->log($sale, AuditEvent::ItemRemoved, -50);
        $this->log($sale, AuditEvent::CustomerAssigned, null);

        $result = app(SaleMovementsQuery::class)->run([]);

        // Pasar la venta a fiado saca el dinero del efectivo del día pero no lo
        // pierde: contarlo daría una pérdida que no ocurrió.
        $this->assertSame(-50.0, $result['summary']['net_effect']);
        $this->assertSame(2, $result['summary']['movement_count']);
        $this->assertSame(1, $result['summary']['sales_touched']);
    }

    public function test_ordena_por_el_dinero_que_se_quito(): void
    {
        $poca = $this->sale();
        $mucha = $this->sale();
        $this->log($poca, AuditEvent::ItemUpdated, -10);
        $this->log($mucha, AuditEvent::PaymentDeleted, -400);

        $rows = collect(app(SaleMovementsQuery::class)->run([])['sales']->items());

        $this->assertSame($mucha->id, $rows->first()['sale_id']);
    }

    public function test_marca_precio_y_pago_dentro_de_la_misma_hora(): void
    {
        $sale = $this->sale();
        $this->log($sale, AuditEvent::ItemUpdated, -300, now()->setTime(14, 0));
        $this->log($sale, AuditEvent::PaymentUpdated, -300, now()->setTime(14, 20));

        $rows = collect(app(SaleMovementsQuery::class)->run([])['sales']->items());

        $this->assertTrue($rows->first()['suspicious']);
    }

    public function test_no_marca_un_cambio_suelto(): void
    {
        $sale = $this->sale();
        $this->log($sale, AuditEvent::ItemUpdated, -300, now()->setTime(14, 0));

        $rows = collect(app(SaleMovementsQuery::class)->run([])['sales']->items());

        // Un cambio de precio solo casi siempre es una corrección legítima;
        // marcarlo todo sería igual que no marcar nada.
        $this->assertFalse($rows->first()['suspicious']);
    }

    public function test_no_marca_cambios_separados_por_horas(): void
    {
        $sale = $this->sale();
        $this->log($sale, AuditEvent::ItemUpdated, -300, now()->setTime(9, 0));
        $this->log($sale, AuditEvent::PaymentUpdated, -300, now()->setTime(18, 0));

        $rows = collect(app(SaleMovementsQuery::class)->run([])['sales']->items());

        $this->assertFalse($rows->first()['suspicious']);
    }

    public function test_la_sucursal_forzada_gana_sobre_la_de_la_url(): void
    {
        $mia = $this->sale();
        $ajena = $this->sale();
        $this->log($mia, AuditEvent::ItemUpdated, -20);
        $this->log($ajena, AuditEvent::ItemUpdated, -999, null, $this->secondBranch->id);

        $result = app(SaleMovementsQuery::class)->run(
            ['branch_id' => $this->secondBranch->id],
            $this->branch->id,
        );
        $rows = collect($result['sales']->items());

        $this->assertCount(1, $rows);
        $this->assertSame($mia->id, $rows->first()['sale_id']);
    }
}
```

- [ ] **Step 2: Correr**

Run: `docker compose exec -T laravel.test php artisan test --filter=SaleMovementsQueryTest`
Expected: los ocho PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Movimientos/SaleMovementsQueryTest.php
git commit -m "test(movimientos): la consulta agrupa, suma el neto y marca el patrón"
```

---

### Task 7: Quién entra

Tres reglas del spec §6: el admin-empresa siempre; el admin-sucursal solo su sucursal y solo con el flag encendido; el cajero nunca.

**Files:**
- Create: `tests/Feature/Movimientos/MovimientosAccessTest.php`

- [ ] **Step 1: Escribir el archivo**

```php
<?php

namespace Tests\Feature\Movimientos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Movimientos es un registro de vigilancia sobre quien opera la caja, así que
 * su flag nace apagado y el cajero no entra nunca.
 */
class MovimientosAccessTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function enableForBranchAdmin(bool $enabled = true): void
    {
        $this->branch->forceFill(['branch_admin_movements_enabled' => $enabled])->save();
    }

    public function test_el_admin_de_empresa_entra_siempre(): void
    {
        $this->enableForBranchAdmin(false);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.movimientos.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Empresa/Movimientos/Index'));
    }

    public function test_el_admin_de_sucursal_sin_el_flag_no_entra(): void
    {
        $this->enableForBranchAdmin(false);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_el_admin_de_sucursal_con_el_flag_entra(): void
    {
        $this->enableForBranchAdmin();

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sucursal/Movimientos/Index')
                ->where('scope', 'sucursal')
                ->where('branches', []));
    }

    public function test_el_cajero_no_entra_por_ninguna_de_las_dos_puertas(): void
    {
        $this->enableForBranchAdmin();

        $this->actingAs($this->cajero)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertForbidden();

        $this->actingAs($this->cajero)
            ->get(route('empresa.movimientos.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_el_flag_nace_apagado(): void
    {
        // Un registro de vigilancia se enciende a propósito, nunca por omisión.
        $this->assertFalse((bool) $this->secondBranch->fresh()->branch_admin_movements_enabled);
    }
}
```

- [ ] **Step 2: Correr**

Run: `docker compose exec -T laravel.test php artisan test --filter=MovimientosAccessTest`
Expected: los cinco PASS.

- [ ] **Step 3: Correr la suite completa**

Run: `docker compose exec -T laravel.test php artisan test --compact`
Expected: 1443+ passed, 0 failed.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Movimientos/MovimientosAccessTest.php
git commit -m "test(movimientos): el flag nace apagado y el cajero no entra"
```

---

### Task 8: Documentación

**Files:**
- Create: `docs/modulos/movimientos.md`
- Modify: `docs/README.md`
- Modify: `docs/modulos/sucursales.md`
- Modify: `docs/superpowers/specs/2026-08-21-movimientos-design.md`

- [ ] **Step 1: Escribir el doc vivo**

`docs/modulos/movimientos.md`, con estas secciones y este contenido:

1. **Qué resuelve** — el dueño sospecha que alguien usa sus credenciales para bajar precios y ajustar el efectivo a entregar; hasta 2026-08 solo quedaba rastro de los cambios de producto, y editar o borrar un pago no dejaba ninguno.
2. **Qué puede y qué no puede decir** — copiar §2 del spec: los cambios aparecerán a nombre del dueño y eso es la señal, no un defecto; la IP no distingue equipos dentro del local pero sí dentro/fuera; el registro deja ver, no impide.
3. **Dónde vive** — tabla `audit_logs` con las cuatro columnas nuevas (`branch_id`, `ip_address`, `user_agent`, `amount_effect`) y por qué `amount_effect` se guarda en vez de calcularse. `sale_item_changes` sigue intacta y alimenta el historial del detalle de una venta.
4. **Los eventos y su efecto en dinero** — la tabla del spec §3, ya sin `payment_added` (ver Task 1), con la regla «negativo = reduce lo que hay que entregar» y por qué el cambio de cliente es no monetario.
5. **Dónde se escribe cada uno** — `SaleItemEditor`, los dos `PaymentController`, `AssignCustomerToSale`, `SaleAuditObserver`; y que `AuditLogger` resuelve el contexto solo.
6. **La pantalla** — una fila por venta, orden por impacto, resumen del periodo, marca de patrón sospechoso (precio + pago dentro de la misma hora, `SUSPICIOUS_WINDOW_MINUTES = 60`), filtros.
7. **Quién entra** — admin-empresa siempre en `/{tenant}/empresa/movimientos`; admin-sucursal en `/{tenant}/sucursal/movimientos` solo con `branch_admin_movements_enabled`; cajero nunca. Explicar por qué el flag nace apagado.
8. **Pruebas** — los cuatro archivos de `tests/Feature/Movimientos/` y qué cubre cada uno.

- [ ] **Step 2: Índice y tabla de estado**

En `docs/README.md`: añadir `movimientos.md` al índice de `docs/modulos/` y una fila en la tabla «Estado del sistema» con estado **Implementado**, siguiendo el formato de las filas vecinas.

- [ ] **Step 3: El flag en la lista de sucursales**

En `docs/modulos/sucursales.md`, añadir `branch_admin_movements_enabled` a la lista de feature flags, indicando que es el único que nace **apagado** y por qué.

- [ ] **Step 4: Cerrar el spec**

En `docs/superpowers/specs/2026-08-21-movimientos-design.md`, cambiar la cabecera:

```markdown
- **Estado:** Implementado (2026-08-23) — doc vivo: [`docs/modulos/movimientos.md`](../../modulos/movimientos.md)
```

- [ ] **Step 5: Commit**

```bash
git add docs/modulos/movimientos.md docs/README.md docs/modulos/sucursales.md docs/superpowers/specs/2026-08-21-movimientos-design.md
git commit -m "docs(movimientos): doc vivo del módulo y cierre del spec"
```

---

### Task 9: Verlo funcionando y abrir el PR

Las pruebas dicen que el registro es correcto; nadie ha mirado todavía la pantalla con datos dentro.

**Files:** ninguno nuevo.

- [ ] **Step 1: Compilar y levantar**

```bash
npm run build
docker compose exec -T laravel.test php artisan route:list --name=movimientos
```

- [ ] **Step 2: Recorrido manual**

Con `sucursal@eltoro.test` / `password` en el tenant `el-toro`:

1. Encender `branch_admin_movements_enabled` en la ficha de la sucursal (como `admin@eltoro.test`) y comprobar que aparece «Movimientos» en el sidebar de sucursal.
2. Editar un pago de una venta cobrada y borrar otro; cambiar el precio de un producto en la misma venta dentro de la misma hora.
3. Abrir Movimientos: esa venta debe salir marcada como patrón sospechoso, con el neto en negativo y el desglose por evento con hora, valor antes → después y tipo de equipo.
4. Apagar el flag y comprobar que el item del sidebar desaparece y la URL directa da 403.

- [ ] **Step 3: Suite completa y formato**

```bash
docker compose exec -T laravel.test ./vendor/bin/pint --dirty
docker compose exec -T laravel.test php artisan test --compact
```

Expected: 0 failed.

- [ ] **Step 4: Llevar el trabajo a su rama**

La rama `feat/movimientos` ya existe y contiene el spec. **Listar los archivos uno a uno** — el working tree se comparte con otras sesiones y puede haber cambios ajenos:

```bash
git checkout feat/movimientos
git add app/Enums/AuditEvent.php app/Models/AuditLog.php app/Models/Branch.php \
        app/Observers/SaleAuditObserver.php app/Providers/AppServiceProvider.php \
        app/Services/AuditLogger.php app/Services/AssignCustomerToSale.php \
        app/Services/SaleItemEditor.php app/Services/SaleMovementsQuery.php \
        app/Http/Controllers/Empresa/MovimientosController.php \
        app/Http/Controllers/Sucursal/MovimientosController.php \
        app/Http/Controllers/Empresa/SucursalController.php \
        app/Http/Controllers/Sucursal/PaymentController.php \
        app/Http/Controllers/Api/Hub/PaymentController.php \
        app/Http/Middleware/HandleInertiaRequests.php \
        database/migrations/2026_08_21_134244_add_context_to_audit_logs_table.php \
        database/migrations/2026_08_21_134245_add_branch_admin_movements_to_branches_table.php \
        resources/js/Components/Movimientos resources/js/Pages/Empresa/Movimientos \
        resources/js/Pages/Sucursal/Movimientos resources/js/Layouts/EmpresaLayout.vue \
        resources/js/Layouts/SucursalLayout.vue resources/js/Pages/Empresa/Sucursales/Edit.vue \
        routes/web.php
git commit -m "feat(movimientos): qué se le hizo a una venta después de cobrarla"
git push -u origin feat/movimientos
```

- [ ] **Step 5: Abrir el PR**

```bash
gh pr create --title "Movimientos: qué se le hizo a una venta después de cobrarla" --body "..."
```

El cuerpo explica: el problema (editar un pago no dejaba rastro del monto anterior), qué registra ahora y con qué efecto en dinero, quién entra y por qué el flag nace apagado, el límite honesto de la IP, y el resultado de la suite.

---

## Autorrevisión

- **Cobertura del spec:** §3 (columnas y eventos) → Tasks 1-5; §4 (dónde se escribe) → Tasks 2-4; §5 (pantalla y marca) → Task 6 y Task 9; §6 (quién entra) → Task 7; §7 (pruebas) → Tasks 2-7; §8 (documentación) → Task 8.
- **Sin cobertura automatizada, a propósito:** el desglose visual de la pantalla y el formato de los importes se verifican a mano en la Task 9. Fijarlos en aserciones de Inertia congelaría una UI que aún no se ha mirado con datos reales.
- **Nombres usados en varias tareas:** `activeSale()`, `lastLog(AuditEvent)`, `sale()`, `log(...)`, `enableForBranchAdmin(bool)`. Cada uno se define una vez, en el archivo donde se usa.
