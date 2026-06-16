# Hub Fase 1 · Plan A — Backend: API de caja (`/api/v1/hub/*`)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exponer el núcleo de caja (turno + ventas activas + cobro) como API JSON autenticada por Sanctum bajo `/api/v1/hub/*`, reusando los servicios existentes, sin tocar el contrato de básculas (`auth.apikey`) ni de Inertia.

**Architecture:** Refactor previo que preserva comportamiento (extraer lógica de turno a `ShiftService`; converger el recálculo de pago en `SalePaymentService`), luego controladores API nuevos en `Api/Hub/` que llaman a esos servicios y devuelven API Resources. Idempotencia de cobro nueva vía columna `client_reference` en `payments`. Multi-tenant por el usuario del token.

**Tech Stack:** Laravel 13, Sanctum 4, PostgreSQL, PHPUnit, Sail.

**Spec:** `docs/superpowers/specs/2026-06-01-hub-migracion-modulos-fase1-design.md`.

**Prerequisito:** Hito 2 (Sanctum + `HasApiTokens` en `User` + `/api/v1/auth/*`). Este plan asume esa rama mergeada o se construye sobre ella. Rama nueva: `feature/hub-modulos-fase1`.

**No-ruptura:** los controladores Inertia conservan su comportamiento (los refactors mueven código verbatim a servicios y delegan). Único cambio de BD: columna aditiva `client_reference` en `payments`. NO editar guards (el guard `web` por defecto en `config/auth.php` ya alinea con `auth:sanctum` y Spatie).

---

## File Structure

- Create: `app/Services/ShiftService.php` — lógica de abrir/cerrar turno (extraída de `TurnoController`).
- Modify: `app/Http/Controllers/Caja/TurnoController.php` — `open`/`close` delegan en `ShiftService`.
- Modify: `app/Http/Controllers/Sucursal/PaymentController.php` — usar `SalePaymentService::recalculate`, borrar el `recalculate()` privado duplicado.
- Create: `database/migrations/2026_06_01_000001_add_client_reference_to_payments_table.php`.
- Modify: `app/Models/Payment.php` — `client_reference` fillable.
- Create: `app/Http/Middleware/EnsureHubRole.php` — exige rol cajero/admin-sucursal sobre el usuario del token.
- Modify: `bootstrap/app.php` — registrar alias de middleware `hub.role`.
- Create: `app/Http/Controllers/Api/Hub/ShiftController.php`, `SaleController.php`, `PaymentController.php`.
- Create: `app/Http/Resources/Hub/ShiftResource.php`, `HubSaleResource.php`.
- Modify: `routes/api.php` — grupo `/api/v1/hub/*`.
- Create tests: `tests/Feature/Api/Hub/{ShiftApiTest,SaleApiTest,PaymentApiTest}.php`.

---

### Task A1: Converger el recálculo de pago en `SalePaymentService`

`SalePaymentService::recalculate(Sale,User)` es **copia verbatim** del `recalculate()` privado de `Sucursal/PaymentController` (lo dice su docblock). Convergemos sin cambiar comportamiento.

**Files:**
- Modify: `app/Http/Controllers/Sucursal/PaymentController.php`

- [ ] **Step 1: Inyectar `SalePaymentService` y reemplazar las llamadas**

En `Sucursal/PaymentController.php`: agregar `use App\Services\SalePaymentService;`. En cada punto donde hoy se llama `$this->recalculate($sale, $user)` (en `store`, `update`, `destroy`), reemplazar por `app(SalePaymentService::class)->recalculate($sale, $user);`.

- [ ] **Step 2: Borrar el método privado `recalculate()` duplicado**

Eliminar el método `private function recalculate(Sale $sale, $user): void { ... }` completo de `Sucursal/PaymentController.php` (el cuerpo es idéntico al del servicio).

- [ ] **Step 3: Regresión de pagos (comportamiento preservado)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/PagosIndexTest.php tests/Feature/Sucursal/PagosSummaryTest.php`
Expected: PASA (sin cambios de comportamiento).

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Sucursal/PaymentController.php
git commit -m "refactor(pagos): converge payment recalculation on SalePaymentService"
```

---

### Task A2: Extraer la lógica de turno a `ShiftService`

**Files:**
- Create: `app/Services/ShiftService.php`
- Modify: `app/Http/Controllers/Caja/TurnoController.php`

- [ ] **Step 1: Crear `ShiftService` con `open` y `close`**

`open` encapsula la regla "no abrir si ya hay turno"; `close` mueve **verbatim** el cuerpo de cálculo/persistencia de `TurnoController@close` (el bloque que usa `ShiftTotalsCalculator`, `ShiftCashOutCalculator`, concilia declarados y hace `$shift->update([...])`). Devuelve el `CashRegisterShift` cerrado.

```php
<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\User;

class ShiftService
{
    public function __construct(
        private ShiftTotalsCalculator $totals,
        private ShiftCashOutCalculator $cashOut,
    ) {}

    /** Turno abierto del usuario, o null. */
    public function current(User $user): ?CashRegisterShift
    {
        return CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Abre un turno. Lanza ShiftAlreadyOpenException si ya hay uno abierto.
     *
     * @throws \App\Exceptions\ShiftAlreadyOpenException
     */
    public function open(User $user, float $openingAmount = 0): CashRegisterShift
    {
        if ($this->current($user) !== null) {
            throw new \App\Exceptions\ShiftAlreadyOpenException;
        }

        return CashRegisterShift::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_amount' => $openingAmount,
        ]);
    }

    /**
     * Cierra el turno abierto del usuario y devuelve el shift cerrado.
     *
     * @param array{declared_amount?:?float, declared_card?:?float, declared_transfer?:?float, notes?:?string} $declared
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException si no hay turno abierto
     */
    public function close(User $user, array $declared): CashRegisterShift
    {
        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $closingAt = now();
        $totals = $this->totals->compute($user->branch_id, $user->id, $shift->opened_at, $closingAt);

        $totalCash = $totals['total_cash'];
        $totalCard = $totals['total_card'];
        $totalTransfer = $totals['total_transfer'];
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $enabled = $this->enabledMethodsFor($user->branch_id);
        $withMovement = array_filter([
            'cash' => $totalCash > 0,
            'card' => $totalCard > 0,
            'transfer' => $totalTransfer > 0,
        ]);
        $effective = array_values(array_unique(array_merge($enabled, array_keys($withMovement))));
        if (! in_array('cash', $effective, true)) {
            $effective[] = 'cash';
        }

        $cashOutTotals = $this->cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expectedCash = $cashOutTotals['expected_amount'];

        $declaredCash = in_array('cash', $effective, true)
            ? round((float) ($declared['declared_amount'] ?? 0), 2) : null;
        $declaredCard = in_array('card', $effective, true)
            ? round((float) ($declared['declared_card'] ?? 0), 2) : null;
        $declaredTransfer = in_array('transfer', $effective, true)
            ? round((float) ($declared['declared_transfer'] ?? 0), 2) : null;

        $diffCash = $declaredCash !== null ? round($declaredCash - $expectedCash, 2) : null;
        $diffCard = $declaredCard !== null ? round($declaredCard - $totalCard, 2) : null;
        $diffTransfer = $declaredTransfer !== null ? round($declaredTransfer - $totalTransfer, 2) : null;

        $shift->update([
            'closed_at' => $closingAt,
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_transfer' => $totalTransfer,
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
            'total_cash_provider_payments' => $cashOutTotals['cash_provider_payments'],
            'total_sales' => $totalCash + $totalCard + $totalTransfer,
            'sale_count' => $totals['collections_count'],
            'sales_generated_amount' => $totals['sales_generated_amount'],
            'sales_generated_count' => $totals['sales_generated_count'],
            'collections_from_today_amount' => $totals['collections_from_today_amount'],
            'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
            'declared_amount' => $declaredCash,
            'declared_card' => $declaredCard,
            'declared_transfer' => $declaredTransfer,
            'expected_amount' => $expectedCash,
            'difference' => $diffCash ?? 0,
            'difference_card' => $diffCard ?? 0,
            'difference_transfer' => $diffTransfer ?? 0,
            'notes' => $declared['notes'] ?? null,
        ]);

        return $shift->refresh();
    }

    /** @return list<string> métodos de pago habilitados en la sucursal */
    private function enabledMethodsFor(int $branchId): array
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail($branchId);

        return $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];
    }
}
```

- [ ] **Step 2: Crear la excepción `ShiftAlreadyOpenException`**

Create `app/Exceptions/ShiftAlreadyOpenException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class ShiftAlreadyOpenException extends RuntimeException
{
    protected $message = 'Ya tienes un turno abierto.';
}
```

- [ ] **Step 3: `TurnoController` delega en el servicio**

En `app/Http/Controllers/Caja/TurnoController.php`:
- `open`: reemplazar el cuerpo por: validar `opening_amount`, y dentro de `try { app(ShiftService::class)->open($user, (float) ($validated['opening_amount'] ?? 0)); } catch (\App\Exceptions\ShiftAlreadyOpenException) { return redirect()->route('caja.workbench', app('tenant')->slug); }`, luego el redirect de éxito existente.
- `close`: reemplazar el cuerpo por: validar las mismas reglas (`notes`, `declared_*`) — **importante:** la validación de declarados depende de métodos efectivos; para preservar exactamente el comportamiento, mantener la validación en el controlador como está y pasar `$validated` a `app(ShiftService::class)->close($user, $validated)`, luego el redirect a `caja.turno.corte` existente. El método privado `enabledMethodsFor` del controlador puede quedarse (lo usa la validación) o moverse; mantenerlo para no alterar la validación.

> Nota: el objetivo del refactor es que la **persistencia/cálculo** viva en `ShiftService` y sea reutilizable por la API. La validación específica de Inertia (mensajes, métodos efectivos) puede permanecer en el controlador web; la API construirá su propio array `$declared` equivalente (Task A6 no aplica aquí — turno es A5).

- [ ] **Step 4: Regresión de turno**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php tests/Feature/Sucursal/CashShiftCloseTest.php`
Expected: PASA (comportamiento preservado).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/ShiftService.php app/Exceptions/ShiftAlreadyOpenException.php app/Http/Controllers/Caja/TurnoController.php
git commit -m "refactor(turno): extract shift open/close into ShiftService"
```

---

### Task A3: Idempotencia de cobro — columna `client_reference` en `payments`

**Files:**
- Create: `database/migrations/2026_06_01_000001_add_client_reference_to_payments_table.php`
- Modify: `app/Models/Payment.php`

- [ ] **Step 1: Crear la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('amount');
        });

        // Único parcial: solo cuando client_reference no es null. Garantiza
        // idempotencia por (sale_id, client_reference) sin afectar pagos
        // existentes (web Inertia) que lo dejan null.
        DB::statement(
            'CREATE UNIQUE INDEX payments_sale_client_reference_unique '
            .'ON payments (sale_id, client_reference) '
            .'WHERE client_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_sale_client_reference_unique');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('client_reference');
        });
    }
};
```

- [ ] **Step 2: Migrar**

Run: `vendor/bin/sail artisan migrate`
Expected: `... add_client_reference_to_payments_table ... DONE`.

- [ ] **Step 3: `client_reference` fillable en `Payment`**

En `app/Models/Payment.php`, en el atributo `#[Fillable([...])]` agregar `'client_reference'`:

```php
#[Fillable(['sale_id', 'customer_payment_id', 'user_id', 'updated_by', 'method', 'amount', 'client_reference'])]
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_01_000001_add_client_reference_to_payments_table.php app/Models/Payment.php
git commit -m "feat(payments): add nullable client_reference for idempotent hub payments"
```

---

### Task A4: Middleware de rol + grupo de rutas `/api/v1/hub`

**Files:**
- Create: `app/Http/Middleware/EnsureHubRole.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Crear `EnsureHubRole`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHubRole
{
    private const HUB_ROLES = ['cajero', 'admin-sucursal'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(self::HUB_ROLES)) {
            return response()->json(['message' => 'No tienes permiso para usar el hub.'], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Registrar el alias en `bootstrap/app.php`**

En la sección `->withMiddleware(function (Middleware $middleware) { ... })`, donde ya se registran alias (`auth.apikey`, `resolve.tenant`, etc.), agregar:

```php
        $middleware->alias([
            // ...alias existentes...
            'hub.role' => \App\Http\Middleware\EnsureHubRole::class,
        ]);
```

(Si los alias existentes están en una llamada `$middleware->alias([...])` ya presente, añadir solo la línea `'hub.role' => ...` dentro de ese array; no duplicar la llamada.)

- [ ] **Step 3: Agregar el grupo de rutas en `routes/api.php`**

Agregar imports al inicio:

```php
use App\Http\Controllers\Api\Hub\PaymentController as HubPaymentController;
use App\Http\Controllers\Api\Hub\SaleController as HubSaleController;
use App\Http\Controllers\Api\Hub\ShiftController as HubShiftController;
```

Agregar **después** del grupo de auth (fuera del grupo `auth.apikey`):

```php
// Hub de escritorio: operaciones de caja autenticadas por usuario (Sanctum).
// Separado del grupo auth.apikey (básculas) y de Inertia.
Route::prefix('v1/hub')
    ->middleware(['auth:sanctum', 'hub.role'])
    ->group(function () {
        Route::get('shift/current', [HubShiftController::class, 'current'])->name('api.hub.shift.current');
        Route::post('shift/open', [HubShiftController::class, 'open'])->name('api.hub.shift.open');
        Route::post('shift/close', [HubShiftController::class, 'close'])->name('api.hub.shift.close');

        Route::get('sales', [HubSaleController::class, 'index'])->name('api.hub.sales.index');
        Route::get('sales/{sale}', [HubSaleController::class, 'show'])->name('api.hub.sales.show');
        Route::post('sales/{sale}/payments', [HubPaymentController::class, 'store'])->name('api.hub.sales.payments.store');
    });
```

- [ ] **Step 4: Verificar rutas**

Run: `vendor/bin/sail artisan route:list --path=api/v1/hub`
Expected: lista las 6 rutas con middleware `auth:sanctum`, `hub.role`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/EnsureHubRole.php bootstrap/app.php routes/api.php
git commit -m "feat(api): hub route group with sanctum + role middleware"
```

---

### Task A5: `Api/Hub/ShiftController` + Resource + tests

**Files:**
- Create: `app/Http/Resources/Hub/ShiftResource.php`
- Create: `app/Http/Controllers/Api/Hub/ShiftController.php`
- Test: `tests/Feature/Api/Hub/ShiftApiTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Api\Hub;

use App\Models\ApiKey;
use App\Models\CashRegisterShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function tokenFor(\App\Models\User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
    }

    public function test_current_returns_null_when_no_open_shift(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/shift/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_open_creates_a_shift(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/open', ['opening_amount' => 500])
            ->assertCreated()
            ->assertJsonPath('data.opening_amount', 500);

        $this->assertSame(1, CashRegisterShift::where('user_id', $this->cajero->id)->whereNull('closed_at')->count());
    }

    public function test_open_twice_returns_409(): void
    {
        $token = $this->tokenFor($this->cajero);
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertStatus(409);
    }

    public function test_close_returns_corte_totals(): void
    {
        $token = $this->tokenFor($this->cajero);
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/hub/shift/close', ['declared_amount' => 0])
            ->assertOk()
            ->assertJsonPath('data.closed', true);

        $this->assertNotNull(CashRegisterShift::where('user_id', $this->cajero->id)->latest('id')->first()->closed_at);
    }

    public function test_admin_empresa_token_is_forbidden(): void
    {
        $this->withToken($this->tokenFor($this->adminEmpresa))
            ->getJson('/api/v1/hub/shift/current')
            ->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/hub/shift/current')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=ShiftApiTest`
Expected: FALLA (controlador/rutas devuelven error; `ShiftController` no existe).

- [ ] **Step 3: Crear `ShiftResource`**

```php
<?php

namespace App\Http\Resources\Hub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,
            'closed' => $this->closed_at !== null,
            'total_cash' => $this->total_cash !== null ? (float) $this->total_cash : null,
            'total_card' => $this->total_card !== null ? (float) $this->total_card : null,
            'total_transfer' => $this->total_transfer !== null ? (float) $this->total_transfer : null,
            'expected_amount' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
        ];
    }
}
```

- [ ] **Step 4: Crear `Api/Hub/ShiftController`**

```php
<?php

namespace App\Http\Controllers\Api\Hub;

use App\Exceptions\ShiftAlreadyOpenException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\ShiftResource;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function current(Request $request): JsonResource
    {
        $shift = $this->shifts->current($request->user());

        return $shift ? ShiftResource::make($shift) : ShiftResource::make(null);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate(['opening_amount' => 'nullable|numeric|min:0']);

        try {
            $shift = $this->shifts->open($request->user(), (float) ($validated['opening_amount'] ?? 0));
        } catch (ShiftAlreadyOpenException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return ShiftResource::make($shift)->response()->setStatusCode(201);
    }

    public function close(Request $request): JsonResource
    {
        $validated = $request->validate([
            'declared_amount' => 'nullable|numeric|min:0',
            'declared_card' => 'nullable|numeric|min:0',
            'declared_transfer' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = $this->shifts->close($request->user(), $validated);

        return ShiftResource::make($shift);
    }
}
```

> Nota: `ShiftResource::make(null)` serializa `data: null` (comportamiento estándar de JsonResource sobre null). El test `test_current_returns_null...` lo verifica.

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=ShiftApiTest`
Expected: PASA (6 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Resources/Hub/ShiftResource.php app/Http/Controllers/Api/Hub/ShiftController.php tests/Feature/Api/Hub/ShiftApiTest.php
git commit -m "feat(api): hub shift endpoints (current/open/close)"
```

---

### Task A6: `Api/Hub/SaleController` (index/show) + Resource + tests

**Files:**
- Create: `app/Http/Resources/Hub/HubSaleResource.php`
- Create: `app/Http/Controllers/Api/Hub/SaleController.php`
- Test: `tests/Feature/Api/Hub/SaleApiTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function makeSale(int $branchId, SaleStatus $status, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => $status,
        ]);
    }

    public function test_index_lists_only_active_and_pending_of_own_branch(): void
    {
        $this->makeSale($this->branch->id, SaleStatus::Active);
        $this->makeSale($this->branch->id, SaleStatus::Pending);
        $this->makeSale($this->branch->id, SaleStatus::Completed); // no debe salir
        $this->makeSale($this->secondBranch->id, SaleStatus::Active); // otra sucursal

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/sales')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_show_returns_sale_with_items_and_payments(): void
    {
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.folio', $sale->folio);
    }

    public function test_show_forbids_other_branch_sale(): void
    {
        $sale = $this->makeSale($this->secondBranch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=SaleApiTest`
Expected: FALLA (`SaleController` no existe).

- [ ] **Step 3: Crear `HubSaleResource`**

```php
<?php

namespace App\Http\Resources\Hub;

use App\Enums\SaleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HubSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => $this->status instanceof SaleStatus ? $this->status->value : $this->status,
            'payment_method' => $this->payment_method,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'amount_pending' => (float) $this->amount_pending,
            'origin' => $this->origin,
            'origin_name' => $this->origin_name,
            'created_at' => $this->created_at->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_name' => $i->product_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'created_at' => $p->created_at->toIso8601String(),
            ])),
        ];
    }
}
```

- [ ] **Step 4: Crear `Api/Hub/SaleController`**

```php
<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::where('branch_id', $request->user()->branch_id)
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
            ->with('items')
            ->orderByDesc('created_at')
            ->paginate(30);

        return HubSaleResource::collection($sales);
    }

    public function show(Request $request, Sale $sale): HubSaleResource
    {
        abort_if($sale->branch_id !== $request->user()->branch_id, 404);

        $sale->load(['items', 'payments']);

        return HubSaleResource::make($sale);
    }
}
```

> Nota: `Sale` usa `BelongsToTenant` (TenantScope). El test inyecta el tenant vía `seedTenant()`; en runtime, `ResolveTenant` no corre en este grupo, así que el scope se basa en el tenant del binding. Como filtramos explícitamente por `branch_id` del usuario del token y `abort_if` en `show`, el aislamiento está garantizado aunque el scope global no aplique. (Si el `TenantScope` requiere un tenant en el contenedor y rompe la query, el controlador debe `app()->instance('tenant', $request->user()->tenant)` al inicio — verificar en Step 5; si los tests de aislamiento pasan, no hace falta.)

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=SaleApiTest`
Expected: PASA (3 tests). Si `test_index` falla por el TenantScope (no resuelve tenant), agregar al inicio de `index` y `show`: `app()->instance('tenant', $request->user()->tenant);` y re-correr.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Resources/Hub/HubSaleResource.php app/Http/Controllers/Api/Hub/SaleController.php tests/Feature/Api/Hub/SaleApiTest.php
git commit -m "feat(api): hub sales endpoints (index active/pending, show)"
```

---

### Task A7: `Api/Hub/PaymentController` (cobro idempotente, requiere turno)

**Files:**
- Create: `app/Http/Controllers/Api/Hub/PaymentController.php`
- Test: `tests/Feature/Api/Hub/PaymentApiTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    private function activeSale(float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => SaleStatus::Active,
        ]);
    }

    public function test_payment_requires_open_shift(): void
    {
        $sale = $this->activeSale();
        $this->withToken($this->token())
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(409);
    }

    public function test_full_payment_completes_sale_and_returns_change(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale(100);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 120])
            ->assertCreated()
            ->assertJsonPath('change', 20.0)
            ->assertJsonPath('sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('sale.amount_pending', 0.0);
    }

    public function test_payment_is_idempotent_by_client_reference(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale(100);
        $ref = 'pay-ref-1';

        $first = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertCreated();

        $second = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertSuccessful();

        $this->assertSame(1, Payment::where('sale_id', $sale->id)->count());
        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
    }

    public function test_cannot_pay_other_branch_sale(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $other = Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->secondBranch->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'), 'payment_method' => 'cash',
            'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100, 'origin' => 'api',
            'status' => SaleStatus::Active,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$other->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/sail artisan test --compact --filter=PaymentApiTest`
Expected: FALLA (`PaymentController` no existe).

- [ ] **Step 3: Crear `Api/Hub/PaymentController`**

```php
<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private SalePaymentService $payments) {}

    public function store(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        abort_if($sale->branch_id !== $user->branch_id, 404);

        if (in_array($sale->status, [SaleStatus::Completed, SaleStatus::Cancelled], true)) {
            return response()->json(['message' => 'No se pueden registrar pagos en esta venta.'], 422);
        }

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->exists();
        if (! $hasOpenShift) {
            return response()->json(['message' => 'Abre un turno antes de cobrar.'], 409);
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'method' => 'required|in:'.implode(',', $allowed),
            'amount' => 'required|numeric|gt:0',
            'client_reference' => 'nullable|string|max:64',
        ]);

        $clientReference = $validated['client_reference'] ?? null;

        // Idempotencia: si ya existe un pago con este (sale_id, client_reference),
        // devolverlo sin crear otro (reintento del hub).
        if ($clientReference !== null) {
            $existing = Payment::where('sale_id', $sale->id)
                ->where('client_reference', $clientReference)
                ->first();

            if ($existing) {
                $sale->load(['items', 'payments']);

                return response()->json([
                    'payment' => ['id' => $existing->id, 'method' => $existing->method, 'amount' => (float) $existing->amount],
                    'change' => 0.0,
                    'sale' => HubSaleResource::make($sale),
                ], 200);
            }
        }

        $change = 0.0;
        $payment = DB::transaction(function () use ($sale, $user, $validated, $clientReference, &$change) {
            $actualPayment = min((float) $validated['amount'], (float) $sale->amount_pending);

            $payment = Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'method' => $validated['method'],
                'amount' => round($actualPayment, 2),
                'client_reference' => $clientReference,
            ]);

            $this->payments->recalculate($sale, $user);
            $change = round((float) $validated['amount'] - $actualPayment, 2);

            return $payment;
        });

        $sale->load(['items', 'payments']);

        return response()->json([
            'payment' => ['id' => $payment->id, 'method' => $payment->method, 'amount' => (float) $payment->amount],
            'change' => $change,
            'sale' => HubSaleResource::make($sale),
        ], 201);
    }
}
```

> Nota: no se emite el broadcast `SaleUpdated` aquí (la web sí lo hace para su tiempo real). En Fase 1 el hub usa polling; añadir broadcast es trivial luego y no afecta correctitud. La respuesta envuelve `payment`, `change` y `sale` a nivel raíz (no `data`), consistente con cómo se consume en el hub.

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/sail artisan test --compact --filter=PaymentApiTest`
Expected: PASA (4 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Api/Hub/PaymentController.php tests/Feature/Api/Hub/PaymentApiTest.php
git commit -m "feat(api): hub payment endpoint (idempotent, requires open shift)"
```

---

### Task A8: Regresión completa + verificación de no-ruptura

- [ ] **Step 1: Tests nuevos del hub**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Api/Hub`
Expected: PASA (ShiftApiTest 6 + SaleApiTest 3 + PaymentApiTest 4 = 13).

- [ ] **Step 2: Regresión — caja/sucursal/básculas/auth no rotas**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php tests/Feature/Caja/PagosIndexTest.php tests/Feature/Sucursal/CashShiftCloseTest.php tests/Feature/Sucursal/PagosSummaryTest.php tests/Feature/PresentationSaleContractTest.php tests/Feature/Auth tests/Feature/Api/AuthControllerTest.php`
Expected: PASA todo.

- [ ] **Step 3: Suite completa (confirmación final)**

Run: `vendor/bin/sail artisan test --compact`
Expected: verde. (Si algún test ajeno ya estaba rojo antes de empezar, anotarlo; no es de este trabajo.)

---

## Self-Review (cobertura del spec)

- **Grupo `/api/v1/hub/*` con `auth:sanctum` + rol** → Task A4. ✅
- **`ShiftService` extraído (open/close), Inertia delega** → Task A2. ✅
- **Convergencia del recálculo de pago** → Task A1 (PaymentController usa SalePaymentService, dupe borrado). ✅
- **Idempotencia de cobro (columna nueva + índice parcial + dedupe)** → Task A3 (migración) + A7 (dedupe). ✅
- **Shift endpoints current/open(409)/close** → Task A5. ✅
- **Sales index (activas/pending, por sucursal del token) + show (404 otra sucursal)** → Task A6. ✅
- **Payment store (requiere turno 409, cambio, transición, idempotente, aislamiento 404)** → Task A7. ✅
- **API Resources** → ShiftResource, HubSaleResource (A5, A6). ✅
- **No romper básculas/Inertia + regresión con tests nombrados** → Task A8. ✅
- **No editar guards** → no se toca `config/auth.php` ni `config/sanctum.php`. ✅

Sin placeholders. Nombres consistentes (`ShiftService`, `EnsureHubRole`, `hub.role`, `HubSaleResource`, `ShiftResource`, `client_reference`, `payments_sale_client_reference_unique`).

## Notas

- Activar skills `laravel-best-practices` al implementar.
- Planes siguientes: **B** (hub main: httpClient + api/shift.js + api/sales.js + IPC + preload + tests Vitest) y **C** (hub renderer MD3 con @material/web: ShiftView, SalesView polling, SaleDetailView cobro). Dependen de que A esté verde.
