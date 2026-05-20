# Fase 2 — Compra + pago a proveedor en efectivo desde caja — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development o superpowers:executing-plans. Steps con checkbox.

**Goal:** Que el cajero capture una compra pagada en efectivo desde su caja; el pago en efectivo (ProviderPayment) queda ligado al turno y descuenta del efectivo esperado del corte.

**Architecture:** `provider_payments` y `purchases` reciben `cash_register_shift_id` (FK nullable). `ShiftCashOutCalculator` (ya centraliza el esperado) suma los pagos a proveedor en efectivo del turno además de los gastos. La compra en caja reutiliza la creación de compra de `HandlesPurchases` (extraída a `createPurchaseWithItems`) + `PurchasePaymentService::applyPayment` (extendido para sellar el turno). UI: botón "Compra en efectivo" en el turno, reusando `CompraFormModal` (+ cámara/IA) con un campo "pagado en efectivo".

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia v2 + Vue 3, PostgreSQL, PHPUnit, Tailwind. Todo con `vendor/bin/sail`.

**Spec:** `docs/superpowers/specs/2026-05-20-gastos-compras-turno-corte-design.md` (Fase 2). Depende del catálogo de productos de compra (ya implementado) y de la Fase 1 (columnas `total_cash_*` en turnos, `ShiftCashOutCalculator`).

---

## File Structure

**Crear:**
- `database/migrations/2026_05_20_120001_add_cash_shift_link_to_provider_payments_and_purchases.php`
- `app/Http/Controllers/Caja/PurchaseController.php`
- `tests/Feature/Caja/CajaPurchaseControllerTest.php`

**Modificar:**
- `app/Models/ProviderPayment.php` — fillable + relación.
- `app/Models/Purchase.php` — fillable + relación.
- `app/Models/CashRegisterShift.php` — relación `cashProviderPayments()`.
- `app/Services/PurchasePaymentService.php` — `applyPayment` acepta `cash_register_shift_id`.
- `app/Http/Controllers/Concerns/HandlesPurchases.php` — extraer `createPurchaseWithItems`.
- `app/Services/ShiftCashOutCalculator.php` — sumar pagos a proveedor en efectivo.
- `app/Http/Controllers/Caja/TurnoController.php` — persistir `total_cash_provider_payments` en close; cargar pagos en showCorte; pasar providers/purchaseProducts a la vista activa.
- `app/Http/Controllers/Sucursal/CashShiftController.php` — persistir `total_cash_provider_payments` en close/recalculate; cargar pagos en show.
- `app/Services/RecalculateClosedShifts.php` — persistir `total_cash_provider_payments`.
- `routes/web.php` — `caja.compras.store` + `caja.compras.ia.store`.
- `resources/js/Components/Compras/CompraFormModal.vue` — modo caja con campo "pagado en efectivo".
- `resources/js/Pages/Caja/Turno/Active.vue` — botón "Compra en efectivo" + modales.
- `resources/js/Pages/Caja/Turno/Corte.vue` y `resources/js/Pages/Sucursal/Cortes/Show.vue` — línea de pagos a proveedor en efectivo.
- `tests/Feature/Caja/TurnoCorteCashOutTest.php` — caso de pago a proveedor.

---

## Task 1: Migración (FK a turno en provider_payments y purchases)

**Files:**
- Create: `database/migrations/2026_05_20_120001_add_cash_shift_link_to_provider_payments_and_purchases.php`

- [ ] **Step 1: Migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });
    }
};
```

- [ ] **Step 2:** `vendor/bin/sail artisan migrate` → DONE.
- [ ] **Step 3:** Commit `"Turno F2: cash_register_shift_id en provider_payments y purchases"`.

---

## Task 2: Modelos

**Files:** `app/Models/ProviderPayment.php`, `app/Models/Purchase.php`, `app/Models/CashRegisterShift.php`

- [ ] **Step 1: `ProviderPayment` — fillable + relación**

En el `#[Fillable([...])]` reemplaza:

```php
    'tenant_id', 'branch_id', 'provider_id', 'purchase_id',
```

por:

```php
    'tenant_id', 'branch_id', 'cash_register_shift_id', 'provider_id', 'purchase_id',
```

Añade la relación (después de `branch()`):

```php
    public function cashRegisterShift(): BelongsTo
    {
        return $this->belongsTo(CashRegisterShift::class);
    }
```

- [ ] **Step 2: `Purchase` — fillable + relación**

En el `#[Fillable([...])]` reemplaza:

```php
    'tenant_id', 'branch_id', 'provider_id',
```

por:

```php
    'tenant_id', 'branch_id', 'cash_register_shift_id', 'provider_id',
```

Añade la relación (después de `branch()`):

```php
    public function cashRegisterShift(): BelongsTo
    {
        return $this->belongsTo(CashRegisterShift::class);
    }
```

- [ ] **Step 3: `CashRegisterShift` — relación de pagos en efectivo**

Después de `cashExpenses()` añade:

```php
    public function cashProviderPayments(): HasMany
    {
        return $this->hasMany(ProviderPayment::class)
            ->where('payment_method', 'cash')
            ->whereNull('cancelled_at');
    }
```

- [ ] **Step 4:** Commit `"Turno F2: relaciones de turno en pagos/compras"`.

---

## Task 3: `PurchasePaymentService` sella el turno + extraer `createPurchaseWithItems`

**Files:** `app/Services/PurchasePaymentService.php`, `app/Http/Controllers/Concerns/HandlesPurchases.php`

- [ ] **Step 1: `applyPayment` acepta `cash_register_shift_id`**

En `PurchasePaymentService::applyPayment`, en el `ProviderPayment::create([...])`, añade tras `'purchase_id' => $locked->id,`:

```php
                'cash_register_shift_id' => $payload['cash_register_shift_id'] ?? null,
```

Y en el PHPDoc del `@param array{...}` de `applyPayment`, añade la clave `cash_register_shift_id?: int|null,`.

- [ ] **Step 2: Extraer `createPurchaseWithItems` en `HandlesPurchases`**

Reemplaza, dentro de `store`, el bloque:

```php
        $purchase = DB::transaction(function () use ($validated, $branchId, $tenant, $folios) {
            $subtotal = 0.0;
            foreach ($validated['items'] as $line) {
                $subtotal += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $subtotal = round($subtotal, 2);

            $purchase = Purchase::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'folio' => $folios->nextFolio($tenant->id),
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'status' => PurchaseStatus::Received,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'amount_paid' => 0,
                'amount_pending' => $subtotal,
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($validated['items'] as $line) {
                $lineSubtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $product = $this->resolvePurchaseProduct($tenant->id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit']);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $lineSubtotal,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $purchase;
        });
```

por:

```php
        $purchase = $this->createPurchaseWithItems($validated, $branchId, $tenant, $folios);
```

Y añade este método protegido al trait (después de `resolvePurchaseProduct`):

```php
    /**
     * Crea la Purchase + sus PurchaseItem (resolviendo el catálogo) en una
     * transacción. `$extra` permite sellar atributos adicionales (p. ej.
     * cash_register_shift_id desde la caja).
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     */
    protected function createPurchaseWithItems(array $validated, int $branchId, \App\Models\Tenant $tenant, PurchaseFolioGenerator $folios, array $extra = []): Purchase
    {
        return DB::transaction(function () use ($validated, $branchId, $tenant, $folios, $extra) {
            $subtotal = 0.0;
            foreach ($validated['items'] as $line) {
                $subtotal += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $subtotal = round($subtotal, 2);

            $purchase = Purchase::create(array_merge([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'folio' => $folios->nextFolio($tenant->id),
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'status' => PurchaseStatus::Received,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'amount_paid' => 0,
                'amount_pending' => $subtotal,
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ], $extra));

            foreach ($validated['items'] as $line) {
                $lineSubtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $product = $this->resolvePurchaseProduct($tenant->id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit']);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $lineSubtotal,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $purchase;
        });
    }
```

- [ ] **Step 3:** Correr la suite de compras (no debe romperse el flujo existente).

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras`
Expected: PASS.

- [ ] **Step 4:** Commit `"Turno F2: applyPayment sella turno + extrae createPurchaseWithItems"`.

---

## Task 4: `ShiftCashOutCalculator` suma pagos a proveedor + persistencia + carga

**Files:** `app/Services/ShiftCashOutCalculator.php`, `app/Http/Controllers/Caja/TurnoController.php`, `app/Http/Controllers/Sucursal/CashShiftController.php`, `app/Services/RecalculateClosedShifts.php`, `tests/Feature/Caja/TurnoCorteCashOutTest.php`

- [ ] **Step 1: Test que falla** — añade a `TurnoCorteCashOutTest`:

```php
    public function test_close_subtracts_cash_provider_payments_from_expected(): void
    {
        $shift = $this->openShift();

        \App\Models\ProviderPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'provider_id' => \App\Models\Provider::create(['name' => 'Prov', 'type' => 'otro'])->id,
            'paid_at' => now(),
            'amount' => 300,
            'payment_method' => 'cash',
            'user_id' => $this->cajero->id,
        ]);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => 700,
            'declared_card' => 0,
            'declared_transfer' => 0,
        ])->assertRedirect();

        $shift->refresh();
        // esperado = 1000 - 0 retiros - 0 gastos - 300 pagos a proveedor = 700
        $this->assertSame('700.00', $shift->expected_amount);
        $this->assertSame('300.00', $shift->total_cash_provider_payments);
    }
```

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php --filter=cash_provider_payments`
Expected: FAIL (expected_amount 1000, total_cash_provider_payments 0).

- [ ] **Step 2: Extender `ShiftCashOutCalculator::forShift`**

Reemplaza el cuerpo de `forShift`:

```php
    public function forShift(CashRegisterShift $shift, float $totalCash, float $totalWithdrawals): array
    {
        // SoftDeletes excluye los gastos cancelados automáticamente.
        $cashExpenses = round((float) Expense::query()
            ->where('cash_register_shift_id', $shift->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->sum('amount'), 2);

        $expected = round(
            (float) $shift->opening_amount + $totalCash - $totalWithdrawals - $cashExpenses,
            2,
        );

        return [
            'cash_expenses' => $cashExpenses,
            'expected_amount' => $expected,
        ];
    }
```

por:

```php
    public function forShift(CashRegisterShift $shift, float $totalCash, float $totalWithdrawals): array
    {
        // SoftDeletes excluye los registros cancelados automáticamente.
        $cashExpenses = round((float) Expense::query()
            ->where('cash_register_shift_id', $shift->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->sum('amount'), 2);

        $cashProviderPayments = round((float) ProviderPayment::query()
            ->where('cash_register_shift_id', $shift->id)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->whereNull('cancelled_at')
            ->sum('amount'), 2);

        $expected = round(
            (float) $shift->opening_amount + $totalCash - $totalWithdrawals - $cashExpenses - $cashProviderPayments,
            2,
        );

        return [
            'cash_expenses' => $cashExpenses,
            'cash_provider_payments' => $cashProviderPayments,
            'expected_amount' => $expected,
        ];
    }
```

Añade el import `use App\Models\ProviderPayment;` al inicio.

- [ ] **Step 3: Persistir `total_cash_provider_payments`** en los 4 sitios de escritura. En cada `$shift->update([...])` que ya pone `'total_cash_expenses' => $cashOutTotals['cash_expenses'],` añade justo debajo:

```php
            'total_cash_provider_payments' => $cashOutTotals['cash_provider_payments'],
```

Sitios:
- `Caja\TurnoController::close`
- `Sucursal\CashShiftController::close`
- `Sucursal\CashShiftController::recalculate`
- `RecalculateClosedShifts::recompute`

- [ ] **Step 4: Cargar pagos en el corte**

En `Caja\TurnoController::showCorte`, en el `$shift->load([...])`, añade `'cashProviderPayments:id,cash_register_shift_id,provider_id,amount,paid_at', 'cashProviderPayments.provider:id,name'`.

En `Sucursal\CashShiftController::show`, igual.

- [ ] **Step 5:** Correr.

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php`
Expected: PASS (3 tests: gastos cierre, recálculo, pagos a proveedor).

- [ ] **Step 6:** Commit `"Turno F2: el corte resta pagos a proveedor en efectivo"`.

---

## Task 5: `Caja\PurchaseController` + rutas

**Files:** `app/Http/Controllers/Caja/PurchaseController.php`, `routes/web.php`, `app/Http/Controllers/Caja/TurnoController.php` (payload), `tests/Feature/Caja/CajaPurchaseControllerTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaPurchaseControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 1000,
        ]);
    }

    private function payload(float $paid): array
    {
        return [
            'provider_id' => Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne'])->id,
            'purchased_at' => now()->toDateString(),
            'paid_amount' => $paid,
            'items' => [[
                'concept' => 'Media canal de res',
                'quantity' => 2,
                'unit' => 'kg',
                'unit_price' => 100,
            ]],
        ];
    }

    public function test_cajero_registers_cash_purchase_tied_to_shift(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(200))->assertRedirect();

        $purchase = Purchase::first();
        $this->assertNotNull($purchase);
        $this->assertSame($shift->id, $purchase->cash_register_shift_id);
        $this->assertSame($this->branch->id, $purchase->branch_id);
        $this->assertSame('200.00', $purchase->amount_paid);

        $this->assertDatabaseHas('provider_payments', [
            'purchase_id' => $purchase->id,
            'cash_register_shift_id' => $shift->id,
            'payment_method' => 'cash',
            'amount' => 200,
        ]);
    }

    public function test_partial_cash_payment_leaves_pending(): void
    {
        $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(50))->assertRedirect();

        $purchase = Purchase::first();
        $this->assertSame('50.00', $purchase->amount_paid);
        $this->assertSame('150.00', $purchase->amount_pending); // total 200
    }

    public function test_requires_open_shift(): void
    {
        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.store', $this->tenant->slug), $this->payload(200))->assertStatus(422);
        $this->assertSame(0, Purchase::count());
    }
}
```

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/CajaPurchaseControllerTest.php`
Expected: FAIL (ruta no existe).

- [ ] **Step 2: Controlador**

```php
<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Captura de compra pagada en efectivo desde la caja, ligada al turno abierto.
 * Reusa la creación de compra del trait y PurchasePaymentService para el pago.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    public function store(Request $request, PurchaseFolioGenerator $folios, PurchasePaymentService $payments): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            abort(422, 'Abre tu turno antes de registrar una compra.');
        }

        $validated = $this->validatedPurchasePayload($request);
        $paid = round((float) $request->input('paid_amount', 0), 2);
        if ($paid < 0) {
            $paid = 0;
        }

        $purchase = $this->createPurchaseWithItems(
            $validated,
            $shift->branch_id,
            $tenant,
            $folios,
            ['cash_register_shift_id' => $shift->id],
        );

        if ($paid > 0) {
            // applyPayment valida que no exceda el total y recalcula.
            $payments->applyPayment($purchase, [
                'amount' => $paid,
                'payment_method' => 'cash',
                'user_id' => $user->id,
                'cash_register_shift_id' => $shift->id,
            ]);
        } else {
            $payments->recalculate($purchase);
        }

        return back()->with('success', 'Compra en efectivo registrada.');
    }

    // ─── Hooks del trait (no se usan store/update genéricos aquí) ─────────

    protected function resolveBranchIdForWrite(Request $request): int
    {
        return (int) Auth::user()->branch_id;
    }

    protected function applyBranchScopeToQuery(Builder $query): Builder
    {
        return $query->where('branch_id', (int) Auth::user()->branch_id);
    }

    protected function assertCanMutate(Purchase $purchase): void
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function redirectAfterWrite(Request $request, string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }
}
```

- [ ] **Step 3: Rutas** — en el grupo `caja` (junto a `gastos.store`):

Añade el import:

```php
use App\Http\Controllers\Caja\PurchaseController as CajaPurchaseController;
```

Y las rutas:

```php
                Route::post('compras', [CajaPurchaseController::class, 'store'])->name('compras.store');
                Route::post('compras/ia/borrador', [AiPurchaseDraftController::class, 'store'])->name('compras.ia.store');
```

(`AiPurchaseDraftController` ya está importado como alias en routes/web.php.)

- [ ] **Step 4: Pasar providers/purchaseProducts a la vista del turno**

En `Caja\TurnoController::index` (rama con turno activo), añade al payload de `Inertia::render('Caja/Turno/Active', [...])`:

```php
            'providers' => \App\Models\Provider::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
            'purchaseProducts' => \App\Models\PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
```

- [ ] **Step 5:** Correr.

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/CajaPurchaseControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6:** Commit `"Turno F2: Caja\\PurchaseController (compra pagada en efectivo)"`.

---

## Task 6: UI — compra en efectivo desde el turno + corte

**Files:** `resources/js/Components/Compras/CompraFormModal.vue`, `resources/js/Pages/Caja/Turno/Active.vue`, `resources/js/Pages/Caja/Turno/Corte.vue`, `resources/js/Pages/Sucursal/Cortes/Show.vue`

- [ ] **Step 1: `CompraFormModal` modo caja con "pagado en efectivo"**

Añade props:

```js
    cashMode: { type: Boolean, default: false },
```

En el `useForm({...})` añade `paid_amount: 0,`.

En el `watch` (rama crear), después de `form.items = [emptyLine()];` añade:

```js
        form.paid_amount = 0;
```

Antes del bloque de Adjuntos (o donde encaje), añade el campo solo en modo caja:

```html
                        <div v-if="cashMode" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                            <label class="mb-1 block text-sm font-medium text-emerald-900">Pagado en efectivo ahora</label>
                            <input v-model.number="form.paid_amount" type="number" step="0.01" min="0" :max="total"
                                class="w-40 rounded-lg border-gray-300 text-right text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            <p class="mt-1 text-xs text-emerald-700">Sale del cajón y descuenta del corte. Lo no pagado queda como saldo.</p>
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                            <button type="button" @click="form.paid_amount = Number(total.toFixed(2))" class="mt-1 text-xs font-semibold text-emerald-700 hover:underline">Pagar total ({{ fmt(total) }})</button>
                        </div>
```

> El `paid_amount` se envía siempre en el form; el backend de caja lo usa y los backends empresa/sucursal lo ignoran (no está en su validación).

- [ ] **Step 2: Botón "Compra en efectivo" en el turno activo**

En `resources/js/Pages/Caja/Turno/Active.vue`:
- Añade props `providers` y `purchaseProducts` (default `[]`).
- Importa `CompraFormModal` y `CompraCapturaIAModal` y añade estado `const compraOpen = ref(false);`, `const compraIaOpen = ref(false);`, `const compraAiResult = ref(null);`.
- Junto al botón "+ Gasto en efectivo" añade:

```html
                        <button type="button" @click="compraOpen = true" class="font-semibold text-red-600 hover:text-red-700">+ Compra en efectivo</button>
```

- Antes de `<FlashToast />` añade los modales:

```html
        <CompraCapturaIAModal :open="compraIaOpen" :routes="{ iaStore: 'caja.compras.ia.store' }"
            @close="compraIaOpen = false" @analyzed="(r) => { compraAiResult = r; compraIaOpen = false; compraOpen = true; }" />
        <CompraFormModal :open="compraOpen" :purchase="null" cash-mode
            :providers="providers" :purchase-products="purchaseProducts"
            :fixed-branch-id="shift.branch_id" :ai-result="compraAiResult"
            :routes="{ store: 'caja.compras.store', update: 'caja.compras.store' }"
            @close="compraOpen = false; compraAiResult = null" />
```

> `CompraFormModal` en modo crear postea a `routes.store` con `slug`; `update` no se usa (no hay edición en caja) pero el prop lo exige, por eso se repite `caja.compras.store`.

- [ ] **Step 3: Línea de pagos a proveedor en el corte**

En `resources/js/Pages/Caja/Turno/Corte.vue`:
- Cambia los computed:

```js
const cashExpensesTotal = computed(() => (props.shift.cash_expenses || []).reduce((s, e) => s + Number(e.amount), 0));
const providerPaymentsTotal = computed(() => (props.shift.cash_provider_payments || []).reduce((s, p) => s + Number(p.amount), 0));
const totalCashOut = computed(() => withdrawalsTotal.value + cashExpensesTotal.value + providerPaymentsTotal.value);
```

- En el bloque "Salidas en efectivo", después del `<div>` de "Gastos en efectivo", añade:

```html
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-gray-600">Pagos a proveedor en efectivo</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ money(providerPaymentsTotal) }}</p>
                    </div>
                    <div v-for="p in (shift.cash_provider_payments || [])" :key="'pp-' + p.id" class="flex items-center justify-between px-6 py-2 pl-10">
                        <p class="text-xs text-gray-400">{{ p.provider?.name || 'Proveedor' }}</p>
                        <p class="font-mono text-xs tabular-nums text-gray-500">{{ money(p.amount) }}</p>
                    </div>
```

- [ ] **Step 4: Lo mismo en `Sucursal/Cortes/Show.vue`** (mismos computed y mismas filas dentro del bloque "Salidas en efectivo").

- [ ] **Step 5:** `vendor/bin/sail npm run build` → sin errores.
- [ ] **Step 6:** Commit `"Turno F2: UI de compra en efectivo en caja y pagos a proveedor en el corte"`.

---

## Task 7: Pint + suite

- [ ] `vendor/bin/sail bin pint --dirty --format agent` → pass.
- [ ] `vendor/bin/sail artisan test --compact` → toda la suite verde.
- [ ] Commit si Pint cambió algo.

---

## Self-Review

**Cobertura del spec (Fase 2):**
- `cash_register_shift_id` en `provider_payments` y `purchases` → Task 1. ✓
- Compra completa pagada en efectivo desde caja (Purchase+items+ProviderPayment cash, sellados al turno) → Tasks 3, 5. ✓
- El pago en efectivo descuenta del corte; pago parcial deja saldo → Tasks 4, 5. ✓
- Reuso del flujo de compra + cámara/IA → Task 6 (CompraFormModal cashMode + CompraCapturaIAModal con ruta de caja). ✓
- Bloque "Salidas en efectivo" con pagos a proveedor → Task 6. ✓
- Turno abierto requerido → Task 5. ✓
- Tests (compra ligada al turno, parcial, sin turno, corte resta pagos) → Tasks 4, 5. ✓

**Reuso/centralización:** la fórmula del esperado vive solo en `ShiftCashOutCalculator::forShift`; sumar pagos a proveedor es un cambio de una línea ahí (los 4 sitios de escritura solo persisten la columna nueva). La creación de compra se comparte vía `createPurchaseWithItems`; el pago vía `applyPayment` (con su guardia de sobre-pago).

**Desviaciones conscientes:** la compra en caja solo crea (no edita); el prop `routes.update` del modal apunta a la misma ruta store (no se usa). El `paid_amount` viaja en todos los forms de compra pero solo lo consume el backend de caja.

**Type consistency:** `applyPayment` acepta `cash_register_shift_id` en el payload. `forShift` ahora devuelve `cash_provider_payments` además de `cash_expenses` y `expected_amount`; los 4 sitios persisten ambas columnas. La relación `cashProviderPayments` se serializa como `cash_provider_payments`.
