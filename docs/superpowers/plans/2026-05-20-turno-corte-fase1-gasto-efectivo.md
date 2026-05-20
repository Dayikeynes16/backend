# Fase 1 — Gasto en efectivo en caja ligado al turno (corte exacto) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un gasto en efectivo registrado por el cajero durante su turno descuente del efectivo esperado del corte, dejando el corte cuadrado con el cajón físico.

**Architecture:** Se añade `cash_register_shift_id` a `expenses` (FK nullable) y dos columnas de desglose a `cash_register_shifts`. Un servicio nuevo `ShiftCashOutCalculator` centraliza el cálculo del efectivo esperado (hoy duplicado en 6 sitios) restando los gastos en efectivo del turno. Un controlador de caja crea el gasto ligado al turno abierto. La UI del corte muestra el bloque "Salidas en efectivo".

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia.js v2 + Vue 3, PostgreSQL, PHPUnit, Tailwind. Todo se corre con `vendor/bin/sail`.

**Spec:** `docs/superpowers/specs/2026-05-20-gastos-compras-turno-corte-design.md` (Fase 1). NO incluye Fase 2 (compra desde caja) ni el catálogo de productos de compra.

---

## File Structure

**Crear:**
- `database/migrations/2026_05_20_100001_add_cash_shift_link_to_expenses_and_shift_cashout_totals.php` — FK en expenses + columnas de desglose en cash_register_shifts.
- `app/Services/ShiftCashOutCalculator.php` — cálculo centralizado de gastos en efectivo del turno y del efectivo esperado.
- `app/Http/Controllers/Caja/GastoController.php` — alta de gasto en efectivo desde caja (turno abierto requerido).
- `resources/js/Components/Caja/CajaGastoModal.vue` — modal del formulario de gasto en caja.
- `tests/Feature/Caja/CajaGastoControllerTest.php` — tests del alta de gasto en caja.
- `tests/Feature/Services/ShiftCashOutCalculatorTest.php` — tests del cálculo.
- `tests/Feature/Caja/TurnoCorteCashOutTest.php` — tests de que el corte descuenta gastos (cierre + recálculo).

**Modificar:**
- `app/Models/Expense.php` — fillable + relación `cashRegisterShift()`.
- `app/Models/CashRegisterShift.php` — fillable + casts + relación `cashExpenses()`.
- `app/Http/Controllers/Caja/TurnoController.php` — usar el calculador en `index` y `close`; pasar subcategorías + `cash_expenses`.
- `app/Http/Controllers/Sucursal/CashShiftController.php` — usar el calculador en `active`, `close`, `recalculate`.
- `app/Services/RecalculateClosedShifts.php` — usar el calculador.
- `routes/web.php` — ruta `caja.gastos.store`.
- `resources/js/Pages/Caja/Turno/Active.vue` — botón + modal de gasto, desglose con gastos.
- `resources/js/Pages/Caja/Turno/Corte.vue` — bloque "Salidas en efectivo".
- `resources/js/Pages/Sucursal/Cortes/Show.vue` — bloque "Salidas en efectivo".

---

## Task 1: Migración (FK en expenses + columnas de desglose en turnos)

**Files:**
- Create: `database/migrations/2026_05_20_100001_add_cash_shift_link_to_expenses_and_shift_cashout_totals.php`

- [ ] **Step 1: Crear la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Gasto capturado desde caja queda ligado al turno abierto.
            // nullable: los gastos de admin (empresa/sucursal) no llevan turno.
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            // Desglose de salidas en efectivo persistido al cerrar (igual que total_cash).
            // total_cash_provider_payments se llena en la Fase 2; aquí queda en 0.
            $table->decimal('total_cash_expenses', 12, 2)->default(0)->after('total_transfer');
            $table->decimal('total_cash_provider_payments', 12, 2)->default(0)->after('total_cash_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_cash_expenses', 'total_cash_provider_payments']);
        });
    }
};
```

- [ ] **Step 2: Correr la migración**

Run: `vendor/bin/sail artisan migrate`
Expected: `DONE` para `..._add_cash_shift_link_to_expenses_and_shift_cashout_totals`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_20_100001_add_cash_shift_link_to_expenses_and_shift_cashout_totals.php
git commit -m "Turno F1: migración cash_register_shift_id en expenses + desglose en turnos"
```

---

## Task 2: Modelos (Expense + CashRegisterShift)

**Files:**
- Modify: `app/Models/Expense.php`
- Modify: `app/Models/CashRegisterShift.php`

- [ ] **Step 1: Añadir el campo y la relación en `Expense`**

En el atributo `#[Fillable([...])]`, añade `'cash_register_shift_id'` a la lista. Reemplaza el bloque:

```php
#[Fillable([
    'tenant_id', 'branch_id', 'expense_subcategory_id', 'user_id', 'updated_by',
    'cancelled_by', 'concept', 'amount', 'payment_method', 'expense_at', 'description',
    'cancellation_reason',
])]
```

por:

```php
#[Fillable([
    'tenant_id', 'branch_id', 'cash_register_shift_id', 'expense_subcategory_id', 'user_id', 'updated_by',
    'cancelled_by', 'concept', 'amount', 'payment_method', 'expense_at', 'description',
    'cancellation_reason',
])]
```

Y añade esta relación dentro de la clase `Expense`, después del método `branch()`:

```php
    public function cashRegisterShift(): BelongsTo
    {
        return $this->belongsTo(CashRegisterShift::class);
    }
```

(`BelongsTo` ya está importado en el archivo.)

- [ ] **Step 2: Añadir fillable, cast y relación en `CashRegisterShift`**

En el `#[Fillable([...])]`, reemplaza la línea:

```php
    'total_cash', 'total_card', 'total_transfer', 'total_sales', 'sale_count',
```

por:

```php
    'total_cash', 'total_card', 'total_transfer', 'total_cash_expenses', 'total_cash_provider_payments', 'total_sales', 'sale_count',
```

En `casts()`, después de `'total_transfer' => 'decimal:2',` añade:

```php
            'total_cash_expenses' => 'decimal:2',
            'total_cash_provider_payments' => 'decimal:2',
```

Añade esta relación dentro de la clase, después de `withdrawals()`:

```php
    public function cashExpenses(): HasMany
    {
        return $this->hasMany(Expense::class)->where('payment_method', 'cash');
    }
```

(`HasMany` ya está importado.)

- [ ] **Step 3: Commit**

```bash
git add app/Models/Expense.php app/Models/CashRegisterShift.php
git commit -m "Turno F1: relación expense<->turno y campos de desglose"
```

---

## Task 3: Servicio `ShiftCashOutCalculator`

**Files:**
- Create: `app/Services/ShiftCashOutCalculator.php`
- Test: `tests/Feature/Services/ShiftCashOutCalculatorTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Services;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\ShiftCashOutCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftCashOutCalculatorTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function subcategoryId(): int
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Operación', 'status' => 'active']);
        $sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ]);

        return $sub->id;
    }

    private function makeShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now(),
            'opening_amount' => 1000,
        ]);
    }

    private function makeCashExpense(CashRegisterShift $shift, float $amount, string $method = 'cash'): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->subcategoryId(),
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'amount' => $amount,
            'payment_method' => $method,
            'expense_at' => now(),
        ]);
    }

    public function test_sums_only_cash_expenses_of_the_shift(): void
    {
        $shift = $this->makeShift();
        $this->makeCashExpense($shift, 150);
        $this->makeCashExpense($shift, 50);
        $this->makeCashExpense($shift, 999, 'card'); // tarjeta: no cuenta

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 500, totalWithdrawals: 100);

        $this->assertSame(200.0, $result['cash_expenses']);
        // esperado = 1000 fondo + 500 cobrado - 100 retiros - 200 gastos = 1200
        $this->assertSame(1200.0, $result['expected_amount']);
    }

    public function test_excludes_soft_deleted_expenses(): void
    {
        $shift = $this->makeShift();
        $expense = $this->makeCashExpense($shift, 300);
        $expense->delete();

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 0, totalWithdrawals: 0);

        $this->assertSame(0.0, $result['cash_expenses']);
        $this->assertSame(1000.0, $result['expected_amount']);
    }

    public function test_ignores_expenses_of_other_shifts(): void
    {
        $shift = $this->makeShift();
        $other = $this->makeShift();
        $this->makeCashExpense($other, 400);

        $result = (new ShiftCashOutCalculator)->forShift($shift, totalCash: 0, totalWithdrawals: 0);

        $this->assertSame(0.0, $result['cash_expenses']);
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Services/ShiftCashOutCalculatorTest.php`
Expected: FAIL — `Class "App\Services\ShiftCashOutCalculator" not found`.

- [ ] **Step 3: Implementar el servicio**

```php
<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\CashRegisterShift;
use App\Models\Expense;

/**
 * Calcula las SALIDAS de efectivo de un turno (Fase 1: gastos en efectivo) y
 * el efectivo esperado del corte. Centraliza la fórmula que antes vivía
 * duplicada en CajaTurnoController, CashShiftController y RecalculateClosedShifts.
 *
 * A diferencia de ShiftTotalsCalculator (que atribuye cobros por ventana de
 * tiempo), aquí las salidas se atan al turno por FK explícita
 * (expenses.cash_register_shift_id), porque se capturan desde la caja con el
 * turno conocido.
 */
class ShiftCashOutCalculator
{
    /**
     * @return array{cash_expenses: float, expected_amount: float}
     */
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
}
```

- [ ] **Step 4: Correr el test (debe pasar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Services/ShiftCashOutCalculatorTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ShiftCashOutCalculator.php tests/Feature/Services/ShiftCashOutCalculatorTest.php
git commit -m "Turno F1: ShiftCashOutCalculator (gastos en efectivo + esperado)"
```

---

## Task 4: Wire del calculador en cierre, recálculo y vistas en vivo

Objetivo: que los 6 sitios que calculan el efectivo esperado resten los gastos en efectivo y persistan `total_cash_expenses`.

**Files:**
- Modify: `app/Http/Controllers/Caja/TurnoController.php`
- Modify: `app/Http/Controllers/Sucursal/CashShiftController.php`
- Modify: `app/Services/RecalculateClosedShifts.php`
- Test: `tests/Feature/Caja/TurnoCorteCashOutTest.php`

- [ ] **Step 1: Escribir el test que falla (cierre y recálculo descuentan gastos)**

```php
<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\RecalculateClosedShifts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class TurnoCorteCashOutTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function subId(): int
    {
        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Op', 'status' => 'active']);

        return ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ])->id;
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    private function cashExpense(CashRegisterShift $shift, float $amount): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->subId(),
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'amount' => $amount,
            'payment_method' => 'cash',
            'expense_at' => now(),
        ]);
    }

    public function test_close_subtracts_cash_expenses_from_expected(): void
    {
        $shift = $this->openShift();
        $this->cashExpense($shift, 250);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), [
            'declared_amount' => 750,
        ])->assertRedirect();

        $shift->refresh();
        // esperado = 1000 fondo + 0 cobrado - 0 retiros - 250 gastos = 750
        $this->assertSame('750.00', $shift->expected_amount);
        $this->assertSame('250.00', $shift->total_cash_expenses);
        // declarado 750 == esperado 750 → diferencia 0
        $this->assertSame('0.00', $shift->difference);
    }

    public function test_recalculate_after_soft_deleting_expense(): void
    {
        $shift = $this->openShift();
        $expense = $this->cashExpense($shift, 250);

        $this->actingAs($this->cajero);
        $this->post(route('caja.turno.close', $this->tenant->slug), ['declared_amount' => 750])->assertRedirect();

        // Se cancela el gasto; el corte cerrado debe recalcularse por venta no
        // basta — para gastos disparamos recálculo manual del turno cerrado.
        $expense->delete();
        app(RecalculateClosedShifts::class)->forShift($shift->refresh());

        $shift->refresh();
        // ahora esperado = 1000 (sin gastos)
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('0.00', $shift->total_cash_expenses);
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php`
Expected: FAIL — `expected_amount` será `1000.00` (no resta gastos) y/o `forShift` no existe en `RecalculateClosedShifts`.

- [ ] **Step 3: `CajaTurnoController` — inyectar calculador y restar gastos**

Añade el import al inicio del archivo (junto a los otros `use App\Services\...`):

```php
use App\Services\ShiftCashOutCalculator;
```

En `index()`, reemplaza:

```php
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $expected = (float) $shift->opening_amount + $totalCash - $totalWithdrawals;

        return Inertia::render('Caja/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'expected_cash' => round($expected, 2),
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
            'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
            'tenant' => app('tenant'),
        ]);
```

por:

```php
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $cashOut = app(ShiftCashOutCalculator::class)->forShift($shift, $totalCash, $totalWithdrawals);

        return Inertia::render('Caja/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'cash_expenses' => $cashOut['cash_expenses'],
                'expected_cash' => $cashOut['expected_amount'],
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
            'paymentMethods' => $this->enabledMethodsFor($user->branch_id),
            'expenseSubcategories' => $this->expenseSubcategoriesForForm(),
            'tenant' => app('tenant'),
        ]);
```

Añade este método privado dentro de `CajaTurnoController` (después de `enabledMethodsFor`):

```php
    /**
     * Subcategorías activas del tenant para el form de gasto en caja.
     *
     * @return array<int, array{id: int, name: string, category: string}>
     */
    private function expenseSubcategoriesForForm(): array
    {
        return \App\Models\ExpenseSubcategory::query()
            ->where('status', 'active')
            ->with('category:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category?->name ?? '',
            ])->all();
    }
```

En `close()`, cambia la firma para inyectar el calculador:

```php
    public function close(Request $request, ShiftTotalsCalculator $calculator): RedirectResponse
```

por:

```php
    public function close(Request $request, ShiftTotalsCalculator $calculator, ShiftCashOutCalculator $cashOut): RedirectResponse
```

Dentro de `close()`, reemplaza:

```php
        $expectedCash = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
```

por:

```php
        $cashOutTotals = $cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expectedCash = $cashOutTotals['expected_amount'];
```

Y en el `$shift->update([...])`, añade después de `'total_transfer' => $totalTransfer,`:

```php
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
```

- [ ] **Step 4: `CashShiftController` — mismas tres ediciones (`active`, `close`, `recalculate`)**

Añade el import:

```php
use App\Services\ShiftCashOutCalculator;
```

En `active()`, reemplaza:

```php
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');

        $expected = (float) $shift->opening_amount + $totalCash - $totalWithdrawals;

        return Inertia::render('Sucursal/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'expected_cash' => round($expected, 2),
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
```

por:

```php
        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $cashOut = app(ShiftCashOutCalculator::class)->forShift($shift, $totalCash, $totalWithdrawals);

        return Inertia::render('Sucursal/Turno/Active', [
            'shift' => $shift->load('withdrawals'),
            'totals' => [
                'cash' => $totalCash,
                'card' => $totalCard,
                'transfer' => $totalTransfer,
                'total' => $totalCash + $totalCard + $totalTransfer,
                'withdrawals' => $totalWithdrawals,
                'cash_expenses' => $cashOut['cash_expenses'],
                'expected_cash' => $cashOut['expected_amount'],
                'payment_count' => $payments->pluck('sale_id')->unique()->count(),
            ],
```

En `close()`, cambia la firma:

```php
    public function close(Request $request, ShiftTotalsCalculator $calculator): RedirectResponse
```

por:

```php
    public function close(Request $request, ShiftTotalsCalculator $calculator, ShiftCashOutCalculator $cashOut): RedirectResponse
```

Reemplaza:

```php
        $expectedCash = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
```

por:

```php
        $cashOutTotals = $cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expectedCash = $cashOutTotals['expected_amount'];
```

Y en el `$shift->update([...])` añade tras `'total_transfer' => $totalTransfer,`:

```php
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
```

En `recalculate()`, cambia la firma:

```php
    public function recalculate(CashRegisterShift $shift, ShiftTotalsCalculator $calculator): RedirectResponse
```

por:

```php
    public function recalculate(CashRegisterShift $shift, ShiftTotalsCalculator $calculator, ShiftCashOutCalculator $cashOut): RedirectResponse
```

Reemplaza:

```php
        $expected = round((float) $shift->opening_amount + $totalCash - $totalWithdrawals, 2);
```

por:

```php
        $cashOutTotals = $cashOut->forShift($shift, $totalCash, $totalWithdrawals);
        $expected = $cashOutTotals['expected_amount'];
```

Y en su `$shift->update([...])`, añade tras `'total_transfer' => $totalTransfer,`:

```php
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
```

- [ ] **Step 5: `RecalculateClosedShifts` — usar el calculador y añadir `forShift`**

Reemplaza el constructor y el cuerpo del foreach de `forSale` para que use el calculador, y añade un método público `forShift`. Reemplaza:

```php
    public function __construct(private ShiftTotalsCalculator $calculator) {}

    public function forSale(Sale $sale): void
    {
```

por:

```php
    public function __construct(
        private ShiftTotalsCalculator $calculator,
        private ShiftCashOutCalculator $cashOut,
    ) {}

    /**
     * Recalcula un turno cerrado puntual (p. ej. cuando se cancela/edita un
     * gasto en efectivo ligado a él).
     */
    public function forShift(CashRegisterShift $shift): void
    {
        if (! $shift->closed_at) {
            return;
        }
        $this->recompute($shift);
    }

    public function forSale(Sale $sale): void
    {
```

Dentro de `forSale`, reemplaza el bloque del foreach:

```php
            foreach ($shifts as $shift) {
                $totals = $this->calculator->compute(
                    $shift->branch_id,
                    $shift->user_id,
                    $shift->opened_at,
                    $shift->closed_at,
                );

                $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
                $expected = round((float) $shift->opening_amount + $totals['total_cash'] - $totalWithdrawals, 2);
                $declared = (float) $shift->declared_amount;

                $shift->update([
                    'total_cash' => $totals['total_cash'],
                    'total_card' => $totals['total_card'],
                    'total_transfer' => $totals['total_transfer'],
                    'total_sales' => $totals['total_cash'] + $totals['total_card'] + $totals['total_transfer'],
                    'sale_count' => $totals['collections_count'],
                    'sales_generated_amount' => $totals['sales_generated_amount'],
                    'sales_generated_count' => $totals['sales_generated_count'],
                    'collections_from_today_amount' => $totals['collections_from_today_amount'],
                    'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
                    'expected_amount' => $expected,
                    'difference' => round($declared - $expected, 2),
                ]);
            }
```

por:

```php
            foreach ($shifts as $shift) {
                $this->recompute($shift);
            }
```

Añade este método privado al final de la clase (antes de la llave de cierre):

```php
    private function recompute(CashRegisterShift $shift): void
    {
        $totals = $this->calculator->compute(
            $shift->branch_id,
            $shift->user_id,
            $shift->opened_at,
            $shift->closed_at,
        );

        $totalWithdrawals = (float) $shift->withdrawals()->sum('amount');
        $cashOutTotals = $this->cashOut->forShift($shift, $totals['total_cash'], $totalWithdrawals);
        $expected = $cashOutTotals['expected_amount'];
        $declared = (float) $shift->declared_amount;

        $shift->update([
            'total_cash' => $totals['total_cash'],
            'total_card' => $totals['total_card'],
            'total_transfer' => $totals['total_transfer'],
            'total_cash_expenses' => $cashOutTotals['cash_expenses'],
            'total_sales' => $totals['total_cash'] + $totals['total_card'] + $totals['total_transfer'],
            'sale_count' => $totals['collections_count'],
            'sales_generated_amount' => $totals['sales_generated_amount'],
            'sales_generated_count' => $totals['sales_generated_count'],
            'collections_from_today_amount' => $totals['collections_from_today_amount'],
            'collections_from_previous_amount' => $totals['collections_from_previous_amount'],
            'expected_amount' => $expected,
            'difference' => round($declared - $expected, 2),
        ]);
    }
```

Añade el import al inicio:

```php
use App\Services\ShiftCashOutCalculator;
```

- [ ] **Step 6: Correr el test (debe pasar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/TurnoCorteCashOutTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Correr la regresión del turno/corte existente**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Services/ShiftTotalsCalculatorTest.php tests/Feature/Console/RecomputeShiftTotalsTest.php`
Expected: PASS (no regresiones).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Caja/TurnoController.php app/Http/Controllers/Sucursal/CashShiftController.php app/Services/RecalculateClosedShifts.php tests/Feature/Caja/TurnoCorteCashOutTest.php
git commit -m "Turno F1: el corte resta gastos en efectivo (cierre, recálculo, vistas en vivo)"
```

---

## Task 5: Controlador y ruta de gasto en caja

**Files:**
- Create: `app/Http/Controllers/Caja/GastoController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Caja/CajaGastoControllerTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaGastoControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private int $subId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $cat = ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Op', 'status' => 'active']);
        $this->subId = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Insumos',
            'status' => 'active',
        ])->id;
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

    public function test_cajero_registers_cash_expense_tied_to_open_shift(): void
    {
        $shift = $this->openShift();

        $this->actingAs($this->cajero);
        $this->post(route('caja.gastos.store', $this->tenant->slug), [
            'concept' => 'Bolsas',
            'amount' => 120.50,
            'expense_subcategory_id' => $this->subId,
        ])->assertRedirect();

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'user_id' => $this->cajero->id,
            'concept' => 'Bolsas',
            'payment_method' => 'cash',
        ]);
    }

    public function test_requires_open_shift(): void
    {
        $this->actingAs($this->cajero);
        $this->post(route('caja.gastos.store', $this->tenant->slug), [
            'concept' => 'Bolsas',
            'amount' => 50,
            'expense_subcategory_id' => $this->subId,
        ])->assertStatus(422);

        $this->assertSame(0, Expense::count());
    }

    public function test_rejects_subcategory_from_other_tenant(): void
    {
        $this->openShift();

        $this->actingAs($this->cajero);
        $this->from(route('caja.turno', $this->tenant->slug))
            ->post(route('caja.gastos.store', $this->tenant->slug), [
                'concept' => 'X',
                'amount' => 10,
                'expense_subcategory_id' => 999999,
            ])->assertSessionHasErrors('expense_subcategory_id');
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/CajaGastoControllerTest.php`
Expected: FAIL — ruta `caja.gastos.store` no existe.

- [ ] **Step 3: Crear el controlador**

```php
<?php

namespace App\Http\Controllers\Caja;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Alta de gasto en efectivo desde la caja, ligado al turno abierto del cajero.
 * Sale del cajón → descuenta del efectivo esperado del corte.
 */
class GastoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        $shift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $shift) {
            abort(422, 'Abre tu turno antes de registrar un gasto.');
        }

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('status', 'active')),
            ],
            'description' => 'nullable|string|max:1000',
        ], [
            'expense_subcategory_id.required' => 'Selecciona una subcategoría.',
            'expense_subcategory_id.exists' => 'La subcategoría no es válida o está inactiva.',
            'amount.min' => 'El monto debe ser mayor a 0.',
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $shift->branch_id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('success', 'Gasto en efectivo registrado.');
    }
}
```

- [ ] **Step 4: Registrar la ruta**

En `routes/web.php`, dentro del grupo `caja` (después de la línea `Route::get('turno/corte/{shift}', ...)->name('turno.corte');`), añade:

```php
                Route::post('gastos', [\App\Http\Controllers\Caja\GastoController::class, 'store'])->name('gastos.store');
```

- [ ] **Step 5: Correr el test (debe pasar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja/CajaGastoControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Caja/GastoController.php routes/web.php tests/Feature/Caja/CajaGastoControllerTest.php
git commit -m "Turno F1: alta de gasto en efectivo desde caja (turno abierto requerido)"
```

---

## Task 6: UI — formulario de gasto en caja y bloque "Salidas en efectivo"

**Files:**
- Create: `resources/js/Components/Caja/CajaGastoModal.vue`
- Modify: `resources/js/Pages/Caja/Turno/Active.vue`
- Modify: `resources/js/Pages/Caja/Turno/Corte.vue`
- Modify: `resources/js/Pages/Sucursal/Cortes/Show.vue`
- Modify: `app/Http/Controllers/Caja/TurnoController.php` (cargar gastos en `showCorte`)
- Modify: `app/Http/Controllers/Sucursal/CashShiftController.php` (cargar gastos en `show`)

- [ ] **Step 1: Crear el modal de gasto**

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    subcategories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const form = useForm({
    concept: '',
    amount: '',
    expense_subcategory_id: '',
    description: '',
});

const canSubmit = computed(() =>
    form.concept.trim() !== '' && parseFloat(form.amount) > 0 && form.expense_subcategory_id !== ''
);

const submit = () => {
    form.post(route('caja.gastos.store', props.tenantSlug), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); emit('close'); },
    });
};

const close = () => { form.clearErrors(); emit('close'); };
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-md overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">Gasto en efectivo</h2>
                        <button @click="close" :disabled="form.processing" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Subcategoría <span class="text-red-600">*</span></label>
                            <select v-model="form.expense_subcategory_id"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500">
                                <option value="" disabled>Selecciona…</option>
                                <option v-for="s in subcategories" :key="s.id" :value="s.id">{{ s.category }} · {{ s.name }}</option>
                            </select>
                            <p v-if="form.errors.expense_subcategory_id" class="mt-1 text-xs text-red-600">{{ form.errors.expense_subcategory_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Concepto <span class="text-red-600">*</span></label>
                            <input v-model="form.concept" type="text" maxlength="160"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="Ej. Bolsas, gas, propina" />
                            <p v-if="form.errors.concept" class="mt-1 text-xs text-red-600">{{ form.errors.concept }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Monto <span class="text-red-600">*</span></label>
                            <input v-model="form.amount" type="number" step="0.01" min="0.01"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-red-500 focus:ring-red-500"
                                placeholder="0.00" />
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" :disabled="form.processing"
                            class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button @click="submit" :disabled="form.processing || !canSubmit"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:opacity-50">
                            {{ form.processing ? 'Guardando…' : 'Registrar gasto' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 2: Montar el modal en `Active.vue` y mostrar gastos en el desglose**

En el `<script setup>` de `resources/js/Pages/Caja/Turno/Active.vue`:

- Añade la prop `expenseSubcategories` al `defineProps`:

```js
    expenseSubcategories: { type: Array, default: () => [] },
```

- Añade el import y el estado del modal (debajo del import de `FlashToast`):

```js
import CajaGastoModal from '@/Components/Caja/CajaGastoModal.vue';
```

```js
const gastoOpen = ref(false);
```

En el `<template>`, dentro del bloque "Shift summary", reemplaza la fila de fondo/retiros:

```html
                    <div class="flex items-center gap-4 text-xs text-gray-400">
                        <span>Fondo: <span class="font-semibold text-gray-600">${{ parseFloat(shift.opening_amount).toFixed(2) }}</span></span>
                        <span>Retiros: <span class="font-semibold text-red-500">-${{ totals.withdrawals.toFixed(2) }}</span></span>
                    </div>
```

por:

```html
                    <div class="flex items-center gap-4 text-xs text-gray-400">
                        <span>Fondo: <span class="font-semibold text-gray-600">${{ parseFloat(shift.opening_amount).toFixed(2) }}</span></span>
                        <span>Retiros: <span class="font-semibold text-red-500">-${{ totals.withdrawals.toFixed(2) }}</span></span>
                        <span>Gastos: <span class="font-semibold text-red-500">-${{ (totals.cash_expenses ?? 0).toFixed(2) }}</span></span>
                        <button type="button" @click="gastoOpen = true" class="font-semibold text-red-600 hover:text-red-700">+ Gasto en efectivo</button>
                    </div>
```

En el desglose del esperado de efectivo, reemplaza:

```html
                                            <p class="mt-1 text-[9px] text-gray-400 leading-tight">
                                                ${{ parseFloat(shift.opening_amount).toFixed(0) }} fondo
                                                + ${{ totals.cash.toFixed(0) }} cobrado
                                                <template v-if="totals.withdrawals > 0"> - ${{ totals.withdrawals.toFixed(0) }} retiros</template>
                                            </p>
```

por:

```html
                                            <p class="mt-1 text-[9px] text-gray-400 leading-tight">
                                                ${{ parseFloat(shift.opening_amount).toFixed(0) }} fondo
                                                + ${{ totals.cash.toFixed(0) }} cobrado
                                                <template v-if="totals.withdrawals > 0"> - ${{ totals.withdrawals.toFixed(0) }} retiros</template>
                                                <template v-if="(totals.cash_expenses ?? 0) > 0"> - ${{ totals.cash_expenses.toFixed(0) }} gastos</template>
                                            </p>
```

Justo antes del `<FlashToast />` final, añade el modal:

```html
        <CajaGastoModal :open="gastoOpen" :tenant-slug="tenant.slug" :subcategories="expenseSubcategories" @close="gastoOpen = false" />
```

- [ ] **Step 3: Cargar los gastos del turno en `showCorte` y `show`**

En `app/Http/Controllers/Caja/TurnoController.php::showCorte`, reemplaza:

```php
        $shift->load(['user:id,name', 'withdrawals']);
```

por:

```php
        $shift->load(['user:id,name', 'withdrawals', 'cashExpenses:id,cash_register_shift_id,concept,amount,expense_at']);
```

En `app/Http/Controllers/Sucursal/CashShiftController.php::show`, reemplaza:

```php
        $shift->load(['user:id,name', 'withdrawals']);
```

por:

```php
        $shift->load(['user:id,name', 'withdrawals', 'cashExpenses:id,cash_register_shift_id,concept,amount,expense_at']);
```

- [ ] **Step 4: Bloque "Salidas en efectivo" en `Corte.vue`**

En `resources/js/Pages/Caja/Turno/Corte.vue`, después del `const withdrawalsTotal = computed(...)`, añade:

```js
const cashExpensesTotal = computed(() => (props.shift.cash_expenses || []).reduce((s, e) => s + Number(e.amount), 0));
const totalCashOut = computed(() => withdrawalsTotal.value + cashExpensesTotal.value);
```

En el `<template>`, justo después del bloque `<!-- Withdrawals -->` (cierre de su `</div>`), añade:

```html
            <!-- Salidas en efectivo -->
            <div v-if="totalCashOut > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Salidas en efectivo</h2>
                    <p class="text-xs text-gray-400">Total: <span class="font-semibold text-red-600">-{{ money(totalCashOut) }}</span></p>
                </div>
                <div class="divide-y divide-gray-50">
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-gray-600">Retiros</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ money(withdrawalsTotal) }}</p>
                    </div>
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-gray-600">Gastos en efectivo</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ money(cashExpensesTotal) }}</p>
                    </div>
                    <div v-for="e in (shift.cash_expenses || [])" :key="e.id" class="flex items-center justify-between px-6 py-2 pl-10">
                        <p class="text-xs text-gray-400">{{ e.concept }}</p>
                        <p class="font-mono text-xs tabular-nums text-gray-500">{{ money(e.amount) }}</p>
                    </div>
                </div>
            </div>
```

- [ ] **Step 5: Bloque "Salidas en efectivo" en `Sucursal/Cortes/Show.vue`**

En el `<script setup>`, después de `const totalDiff = computed(...)`, añade:

```js
const withdrawalsTotal = computed(() => (props.shift.withdrawals || []).reduce((s, w) => s + Number(w.amount), 0));
const cashExpensesTotal = computed(() => (props.shift.cash_expenses || []).reduce((s, e) => s + Number(e.amount), 0));
const totalCashOut = computed(() => withdrawalsTotal.value + cashExpensesTotal.value);
const money = (v) => '$' + Number(v ?? 0).toFixed(2);
```

En el `<template>`, después del bloque `<!-- Withdrawals -->` (cierre de su `</div>`), añade:

```html
            <!-- Salidas en efectivo -->
            <div v-if="totalCashOut > 0" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-bold text-gray-900">Salidas en efectivo</h2>
                    <p class="text-xs text-gray-400">Total: <span class="font-semibold text-red-600">-{{ money(totalCashOut) }}</span></p>
                </div>
                <div class="divide-y divide-gray-50">
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-gray-600">Retiros</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ money(withdrawalsTotal) }}</p>
                    </div>
                    <div class="flex items-center justify-between px-6 py-3">
                        <p class="text-sm text-gray-600">Gastos en efectivo</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ money(cashExpensesTotal) }}</p>
                    </div>
                    <div v-for="e in (shift.cash_expenses || [])" :key="e.id" class="flex items-center justify-between px-6 py-2 pl-10">
                        <p class="text-xs text-gray-400">{{ e.concept }}</p>
                        <p class="font-mono text-xs tabular-nums text-gray-500">{{ money(e.amount) }}</p>
                    </div>
                </div>
            </div>
```

> Nota: el `$shift` serializado a Inertia incluye la relación `cashExpenses` como `cash_expenses` (snake_case) gracias al cast por defecto de Eloquent → array. Verifícalo en el paso de build/manual.

- [ ] **Step 6: Compilar el front**

Run: `vendor/bin/sail npm run build`
Expected: `built in ...` sin errores; el bundle de `Caja/Turno/Active` y `Corte` compilan.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Caja/CajaGastoModal.vue resources/js/Pages/Caja/Turno/Active.vue resources/js/Pages/Caja/Turno/Corte.vue resources/js/Pages/Sucursal/Cortes/Show.vue app/Http/Controllers/Caja/TurnoController.php app/Http/Controllers/Sucursal/CashShiftController.php
git commit -m "Turno F1: UI de gasto en caja y bloque Salidas en efectivo en el corte"
```

---

## Task 7: Pint + suite completa de turno/caja

- [ ] **Step 1: Formatear PHP**

Run: `vendor/bin/sail bin pint --dirty --format agent`
Expected: `{"result":"pass"}` o lista de archivos arreglados.

- [ ] **Step 2: Correr los tests del feature + regresión cercana**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja tests/Feature/Services/ShiftCashOutCalculatorTest.php tests/Feature/Services/ShiftTotalsCalculatorTest.php`
Expected: PASS.

- [ ] **Step 3: Commit (si Pint cambió algo)**

```bash
git add -A
git commit -m "Turno F1: pint"
```

---

## Self-Review

**Cobertura del spec (Fase 1):**
- Migración `cash_register_shift_id` en `expenses` + columnas de desglose → Task 1. ✓
- `ShiftCashOutCalculator` → Task 3. ✓
- Cambio de fórmula `expected_amount` (centralizado en los 6 sitios) → Task 4. ✓
- Extensión de `RecalculateClosedShifts` (turno cerrado al cancelar gasto) → Task 4 (`forShift`). ✓
- Ruta/controlador de caja con turno abierto requerido y scope → Task 5. ✓
- Lectura de subcategorías para cajero → Task 4 (`expenseSubcategoriesForForm` en el payload del turno). ✓
- UI del corte "Salidas en efectivo" → Task 6. ✓
- Tests → Tasks 3, 4, 5. ✓

**Desviaciones conscientes del spec:**
- La lectura de subcategorías se sirve en el payload del turno en vez de un endpoint `GET /caja/gastos/subcategorias` separado (menos requests; misma protección por rol cajero).
- El gasto en caja de Fase 1 es **sin foto** (el spec la marcaba "opcional"); se difiere para no arrastrar `ExpenseAttachmentService` a este corte. Anótalo si se quiere subir al alcance.
- `total_cash_provider_payments` se crea en la migración pero queda en 0 hasta la Fase 2.

**Disparo del recálculo en cancelación de gasto:** en esta Fase 1 el alta de gasto se hace con turno abierto (lectura en vivo, sin necesidad de recálculo). `RecalculateClosedShifts::forShift` queda disponible para cuando se permita cancelar/editar un gasto de un turno **cerrado** (cableado de ese disparo: cuando se implemente la cancelación de gastos desde caja/sucursal, llamar `app(RecalculateClosedShifts::class)->forShift($shift)` si `expense->cash_register_shift_id` apunta a un turno cerrado). El test de Task 4 ya cubre `forShift`.

**Type consistency:** `forShift(CashRegisterShift, float, float): array{cash_expenses, expected_amount}` se usa idéntico en `CajaTurnoController`, `CashShiftController` y `RecalculateClosedShifts`. La prop `cash_expenses` del payload `totals` se consume en `Active.vue` con guard `?? 0`. La relación `cashExpenses` se serializa como `cash_expenses`.
