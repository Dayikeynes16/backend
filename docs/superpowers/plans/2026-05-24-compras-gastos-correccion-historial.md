# Compras y Gastos: corrección desde Caja + historial de cambios — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el cajero pueda pagar/editar/cancelar sus compras y gastos con el turno abierto, que toda edición/cancelación quede en un historial de cambios campo por campo, que las compras canceladas se oculten, y que cancelar devuelva el efectivo al corte.

**Architecture:** Tabla polimórfica `audit_logs` + servicio `AuditLogger` (sin dependencias nuevas) para el historial de `Purchase` y `Expense`. Los flujos de Caja reusan `HandlesPurchases` y `PurchasePaymentService` bajo un candado server-side (propio + turno abierto). Cancelar una compra cancela sus pagos en efectivo y recalcula el corte cerrado vía `RecalculateClosedShifts`.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia.js v2 + Vue 3, PostgreSQL 18, Laravel Sail, PHPUnit 12. Tests con `Tests\Concerns\SeedsMetricsData`. Pruebas backend con TDD; UI Vue con edición concreta + verificación manual (el repo no tiene runner de JS; las pruebas son feature tests PHPUnit).

**Spec:** `docs/superpowers/specs/2026-05-24-compras-gastos-correccion-historial-design.md`

---

## Convenciones (leer una vez)

- **Todos los comandos** corren dentro de Sail: prefijo `vendor/bin/sail`.
- **Correr un test:** `vendor/bin/sail artisan test --compact --filter=NombreDelTest`
- **Correr un archivo:** `vendor/bin/sail artisan test --compact tests/Feature/Ruta/ArchivoTest.php`
- **Formato PHP (al terminar cada tarea con cambios PHP):** `vendor/bin/sail bin pint --dirty --format agent`
- **Compilar assets Vue:** `vendor/bin/sail npm run build`
- **Tests** usan el trait `Tests\Concerns\SeedsMetricsData`: en `setUp()` llaman `$this->seedTenant(); app()->instance('tenant', $this->tenant);`. Exponen `$this->tenant`, `$this->branch`, `$this->adminEmpresa`, `$this->adminSucursal`, `$this->cajero`, y `makeUser(string $email, string $role, ?int $branchId): User`. No hay factories para Purchase/Expense: los modelos se crean con `Model::create([...])`.

## Mapa de archivos

**Nuevos (backend):**
- `database/migrations/2026_05_24_000001_create_audit_logs_table.php` — tabla polimórfica.
- `app/Enums/AuditEvent.php` — enum de eventos.
- `app/Models/AuditLog.php` — modelo del historial.
- `app/Models/Concerns/RecordsHistory.php` — trait `history()` para Purchase/Expense.
- `app/Services/AuditLogger.php` — escribe historial y calcula diffs.

**Nuevos (frontend):**
- `resources/js/Components/Historial/HistorialTimeline.vue` — línea de tiempo reutilizable.

**Nuevos (tests):**
- `tests/Feature/Historial/PurchaseHistoryTest.php`
- `tests/Feature/Historial/ExpenseHistoryTest.php`
- `tests/Feature/Caja/CajaPurchaseCorrectionTest.php`
- `tests/Feature/Caja/CajaGastoCorrectionTest.php`
- `tests/Feature/Compras/PurchaseCancellationCashTest.php`
- `tests/Feature/Compras/PurchaseHiddenWhenCancelledTest.php`

**Modificados (backend):**
- `app/Models/Purchase.php`, `app/Models/Expense.php` — `use RecordsHistory;`
- `app/Http/Controllers/Concerns/HandlesPurchases.php` — historial en store/update/cancel; regla de edición; cancelar pagos en cancel + recálculo de corte; ocultar canceladas en `applyIndexFilters`; `history` en `serializePurchase`.
- `app/Services/PurchasePaymentService.php` — log de `payment_added`/`payment_cancelled`.
- `app/Http/Controllers/Empresa/GastoController.php` y `Sucursal/GastoController.php` — historial en store/update/destroy; recálculo de corte cerrado en update (si cambia monto) y destroy; `history` eager-load en index.
- `app/Http/Controllers/Caja/PurchaseController.php` — `update`, `cancel`, `storePayment`, `destroyPayment`, candado, serialización con `history` + `can_manage`, ocultar canceladas.
- `app/Http/Controllers/Caja/GastoController.php` — `update`, `destroy`, candado, `history` + `can_manage`, recálculo de corte.
- `routes/web.php` — rutas nuevas de Caja.

**Modificados (frontend):**
- `resources/js/Components/Compras/CompraDetailModal.vue` — prop `canManage`, sección historial, validator de rutas relajado + adjuntos opcionales.
- `resources/js/Components/Gastos/GastoDetailModal.vue` — sección historial.
- `resources/js/Components/Compras/CompraFormModal.vue` — botón "Exacto"; ocultar campo de pago al editar.
- `resources/js/Components/Compras/PagoProveedorModal.vue` — botón "Exacto".
- `resources/js/Pages/Caja/Compras/Index.vue` y `Caja/Gastos/Index.vue` — filas clicables → detalle.
- `resources/js/Pages/Empresa/Compras/Index.vue` y `Sucursal/Compras/Index.vue` — quitar opción "Canceladas".

---

# FASE 1 — Historial de cambios

## Task 1: Migración `audit_logs`

**Files:**
- Create: `database/migrations/2026_05_24_000001_create_audit_logs_table.php`

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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->morphs('auditable'); // auditable_type + auditable_id (+ índice compuesto)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
```

- [ ] **Step 2: Correr la migración para validar el esquema**

Run: `vendor/bin/sail artisan migrate`
Expected: `... create_audit_logs_table .......... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_24_000001_create_audit_logs_table.php
git commit -m "feat(historial): tabla audit_logs polimórfica"
```

---

## Task 2: Enum `AuditEvent`

**Files:**
- Create: `app/Enums/AuditEvent.php`

- [ ] **Step 1: Crear el enum**

```php
<?php

namespace App\Enums;

enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Cancelled = 'cancelled';
    case PaymentAdded = 'payment_added';
    case PaymentCancelled = 'payment_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creó',
            self::Updated => 'Editó',
            self::Cancelled => 'Canceló',
            self::PaymentAdded => 'Registró pago',
            self::PaymentCancelled => 'Canceló pago',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Enums/AuditEvent.php
git commit -m "feat(historial): enum AuditEvent"
```

---

## Task 3: Modelo `AuditLog` + trait `RecordsHistory`

**Files:**
- Create: `app/Models/AuditLog.php`
- Create: `app/Models/Concerns/RecordsHistory.php`
- Modify: `app/Models/Purchase.php`
- Modify: `app/Models/Expense.php`

- [ ] **Step 1: Crear el modelo `AuditLog`**

```php
<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'auditable_type', 'auditable_id',
        'user_id', 'event', 'changes', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Crear el trait `RecordsHistory`**

```php
<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait RecordsHistory
{
    /**
     * Historial de cambios del modelo, más reciente primero.
     */
    public function history(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
```

- [ ] **Step 3: Agregar el trait a `Purchase`**

En `app/Models/Purchase.php`, añade el `use` del trait junto a los demás imports y agrégalo a la lista de traits de la clase:

```php
use App\Models\Concerns\RecordsHistory;
```

```php
// dentro de la clase Purchase, junto a los otros traits:
use BelongsToTenant, RecordsHistory, SoftDeletes;
```

- [ ] **Step 4: Agregar el trait a `Expense`**

En `app/Models/Expense.php`, igual:

```php
use App\Models\Concerns\RecordsHistory;
```

```php
// dentro de la clase Expense:
use BelongsToTenant, RecordsHistory, SoftDeletes;
```

- [ ] **Step 5: Verificar que carga (smoke test rápido)**

Run: `vendor/bin/sail artisan tinker --execute 'echo \App\Models\Purchase::query()->has("history")->toSql();'`
Expected: imprime un SQL con `exists (select * from "audit_logs" ...)` sin errores.

- [ ] **Step 6: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Models/AuditLog.php app/Models/Concerns/RecordsHistory.php app/Models/Purchase.php app/Models/Expense.php
git commit -m "feat(historial): modelo AuditLog y trait RecordsHistory en Purchase/Expense"
```

---

## Task 4: Servicio `AuditLogger` (escritura + diffs)

**Files:**
- Create: `app/Services/AuditLogger.php`
- Test: `tests/Feature/Historial/PurchaseHistoryTest.php` (solo el test del diff por ahora)

- [ ] **Step 1: Escribir el test del diff (unidad a través de feature)**

Crea `tests/Feature/Historial/PurchaseHistoryTest.php`:

```php
<?php

namespace Tests\Feature\Historial;

use App\Services\AuditLogger;
use Tests\TestCase;

class PurchaseHistoryTest extends TestCase
{
    public function test_diff_detects_field_and_item_changes(): void
    {
        $logger = new AuditLogger;

        $before = [
            'fields' => ['provider' => 'Don Pedro', 'total' => 100.0, 'invoice_number' => null],
            'items' => [
                ['concept' => 'Chuleta', 'quantity' => 1.0, 'unit' => 'kg', 'unit_price' => 100.0],
            ],
        ];
        $after = [
            'fields' => ['provider' => 'Don Pedro', 'total' => 280.0, 'invoice_number' => 'F-1'],
            'items' => [
                ['concept' => 'Costilla', 'quantity' => 2.0, 'unit' => 'kg', 'unit_price' => 90.0],
            ],
        ];

        $changes = $logger->diff($before, $after);

        $this->assertSame([100.0, 280.0], $changes['fields']['total']);
        $this->assertSame([null, 'F-1'], $changes['fields']['invoice_number']);
        $this->assertArrayNotHasKey('provider', $changes['fields']); // no cambió
        $this->assertCount(1, $changes['items']['added']);
        $this->assertSame('Costilla', $changes['items']['added'][0]['concept']);
        $this->assertCount(1, $changes['items']['removed']);
        $this->assertSame('Chuleta', $changes['items']['removed'][0]['concept']);
    }

    public function test_diff_returns_empty_when_nothing_changed(): void
    {
        $logger = new AuditLogger;
        $snap = [
            'fields' => ['total' => 100.0],
            'items' => [['concept' => 'X', 'quantity' => 1.0, 'unit' => 'kg', 'unit_price' => 100.0]],
        ];

        $this->assertSame([], $logger->diff($snap, $snap));
    }
}
```

- [ ] **Step 2: Correr el test para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHistoryTest`
Expected: FAIL — `Class "App\Services\AuditLogger" not found`.

- [ ] **Step 3: Implementar `AuditLogger`**

```php
<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Único punto que escribe el historial de cambios (audit_logs) y calcula los
 * diffs campo por campo (incl. líneas de compra). Inmutable: solo inserta.
 */
class AuditLogger
{
    public function log(Model $auditable, AuditEvent $event, ?array $changes = null, ?int $userId = null): void
    {
        AuditLog::create([
            'tenant_id' => $auditable->tenant_id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'user_id' => $userId ?? Auth::id(),
            'event' => $event->value,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    public function logCreated(Model $m): void
    {
        $this->log($m, AuditEvent::Created);
    }

    public function logCancelled(Model $m, string $reason): void
    {
        $this->log($m, AuditEvent::Cancelled, ['reason' => $reason]);
    }

    public function logPaymentAdded(Model $m, float $amount, string $method, ?int $userId = null): void
    {
        $this->log($m, AuditEvent::PaymentAdded, ['amount' => round($amount, 2), 'method' => $method], $userId);
    }

    public function logPaymentCancelled(Model $m, float $amount, string $method, string $reason, ?int $userId = null): void
    {
        $this->log($m, AuditEvent::PaymentCancelled, ['amount' => round($amount, 2), 'method' => $method, 'reason' => $reason], $userId);
    }

    /**
     * Registra una edición solo si hubo cambios reales entre dos snapshots.
     *
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $before
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $after
     */
    public function logUpdatedIfChanged(Model $m, array $before, array $after): void
    {
        $changes = $this->diff($before, $after);
        if ($changes !== []) {
            $this->log($m, AuditEvent::Updated, $changes);
        }
    }

    /**
     * @return array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function purchaseSnapshot(Purchase $p): array
    {
        return [
            'fields' => [
                'provider' => $p->provider?->name,
                'invoice_number' => $p->invoice_number,
                'purchased_at' => $p->purchased_at?->toDateString(),
                'total' => (float) $p->total,
                'notes' => $p->notes,
            ],
            'items' => $p->items->map(fn ($i) => [
                'concept' => $i->concept,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function expenseSnapshot(Expense $e): array
    {
        return [
            'fields' => [
                'concept' => $e->concept,
                'amount' => (float) $e->amount,
                'subcategory' => $e->subcategory?->name,
                'payment_method' => $e->payment_method?->value,
                'expense_at' => $e->expense_at?->toDateString(),
                'description' => $e->description,
                'branch' => $e->branch?->name,
            ],
            'items' => [],
        ];
    }

    /**
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $before
     * @param  array{fields: array<string, mixed>, items: array<int, array<string, mixed>>}  $after
     * @return array<string, mixed>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        $fieldDiff = [];
        foreach ($after['fields'] as $key => $newVal) {
            $oldVal = $before['fields'][$key] ?? null;
            if ($this->normalize($oldVal) !== $this->normalize($newVal)) {
                $fieldDiff[$key] = [$oldVal, $newVal];
            }
        }
        if ($fieldDiff !== []) {
            $changes['fields'] = $fieldDiff;
        }

        $itemDiff = $this->diffItems($before['items'] ?? [], $after['items'] ?? []);
        if ($itemDiff !== []) {
            $changes['items'] = $itemDiff;
        }

        return $changes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<string, mixed>
     */
    private function diffItems(array $before, array $after): array
    {
        $key = fn (array $i) => mb_strtolower(trim((string) $i['concept']));

        $beforeByKey = [];
        foreach ($before as $i) {
            $beforeByKey[$key($i)] = $i;
        }
        $afterByKey = [];
        foreach ($after as $i) {
            $afterByKey[$key($i)] = $i;
        }

        $added = $removed = $changed = [];

        foreach ($afterByKey as $k => $i) {
            if (! isset($beforeByKey[$k])) {
                $added[] = $i;

                continue;
            }
            $b = $beforeByKey[$k];
            if ((float) $b['quantity'] !== (float) $i['quantity']
                || (float) $b['unit_price'] !== (float) $i['unit_price']
                || $b['unit'] !== $i['unit']) {
                $changed[] = [
                    'concept' => $i['concept'],
                    'from' => ['quantity' => (float) $b['quantity'], 'unit_price' => (float) $b['unit_price']],
                    'to' => ['quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price']],
                ];
            }
        }
        foreach ($beforeByKey as $k => $i) {
            if (! isset($afterByKey[$k])) {
                $removed[] = $i;
            }
        }

        $out = [];
        if ($added !== []) {
            $out['added'] = $added;
        }
        if ($removed !== []) {
            $out['removed'] = $removed;
        }
        if ($changed !== []) {
            $out['changed'] = $changed;
        }

        return $out;
    }

    private function normalize(mixed $v): mixed
    {
        if (is_int($v) || is_float($v)) {
            return (string) round((float) $v, 2);
        }
        if ($v === null) {
            return null;
        }

        return (string) $v;
    }
}
```

- [ ] **Step 4: Correr el test para verlo pasar**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHistoryTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/AuditLogger.php tests/Feature/Historial/PurchaseHistoryTest.php
git commit -m "feat(historial): servicio AuditLogger con diff campo por campo"
```

---

## Task 5: Historial en Compras (store/update/cancel) + pagos

**Files:**
- Modify: `app/Http/Controllers/Concerns/HandlesPurchases.php`
- Modify: `app/Services/PurchasePaymentService.php`
- Test: `tests/Feature/Historial/PurchaseHistoryTest.php` (añadir casos de integración)

- [ ] **Step 1: Añadir tests de integración del historial de compra**

Añade estos métodos a `tests/Feature/Historial/PurchaseHistoryTest.php` y agrégale los traits/imports al inicio:

```php
// imports al inicio del archivo:
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;

// en la clase: añade los traits y setUp
use RefreshDatabase, SeedsMetricsData;

protected function setUp(): void
{
    parent::setUp();
    $this->seedTenant();
    app()->instance('tenant', $this->tenant);
}

private function provider(): Provider
{
    return Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne']);
}

private function purchasePayload(array $override = []): array
{
    return array_merge([
        'provider_id' => $this->provider()->id,
        'branch_id' => $this->branch->id,
        'purchased_at' => now()->toDateString(),
        'items' => [[
            'concept' => 'Chuleta',
            'quantity' => 1,
            'unit' => 'kg',
            'unit_price' => 100,
        ]],
    ], $override);
}

public function test_creating_a_purchase_writes_created_history(): void
{
    $this->actingAs($this->adminSucursal);
    $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())
        ->assertRedirect();

    $purchase = Purchase::firstOrFail();
    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => $purchase->getMorphClass(),
        'auditable_id' => $purchase->id,
        'event' => AuditEvent::Created->value,
        'user_id' => $this->adminSucursal->id,
    ]);
}

public function test_editing_a_purchase_writes_updated_history_with_diff(): void
{
    $this->actingAs($this->adminSucursal);
    $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();
    $purchase = Purchase::firstOrFail();

    $this->put(route('sucursal.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
        $this->purchasePayload(['items' => [[
            'concept' => 'Chuleta', 'quantity' => 3, 'unit' => 'kg', 'unit_price' => 100,
        ]]]))->assertRedirect();

    $log = AuditLog::where('event', AuditEvent::Updated->value)->firstOrFail();
    $this->assertArrayHasKey('changed', $log->changes['items']);
    $this->assertSame([100.0, 300.0], $log->changes['fields']['total']);
}

public function test_cancelling_a_purchase_writes_cancelled_history(): void
{
    $this->actingAs($this->adminSucursal);
    $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();
    $purchase = Purchase::firstOrFail();

    $this->patch(route('sucursal.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
        ['reason' => 'duplicada'])->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $purchase->id,
        'event' => AuditEvent::Cancelled->value,
    ]);
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHistoryTest`
Expected: FAIL — no existen las entradas `audit_logs` (los controladores aún no las escriben).

- [ ] **Step 3: Inyectar el logger en `HandlesPurchases::store`**

En `app/Http/Controllers/Concerns/HandlesPurchases.php` añade el import:

```php
use App\Services\AuditLogger;
```

En `store()`, justo antes del `return $this->redirectAfterWrite(...)`, añade:

```php
        app(AuditLogger::class)->logCreated($purchase);

        return $this->redirectAfterWrite($request, 'Compra registrada.');
```

- [ ] **Step 4: Diff en `HandlesPurchases::update` + regla de edición**

Reemplaza el cuerpo de `update()` (desde la firma hasta el `return`) por esta versión, que (a) bloquea bajar el total por debajo de lo pagado, y (b) registra el diff:

```php
    public function update(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $purchase = $compra;
        $this->assertCanMutate($purchase);
        if ($purchase->status === PurchaseStatus::Cancelled) {
            abort(422, 'No se puede editar una compra cancelada.');
        }

        $validated = $this->validatedPurchasePayload($request, $purchase);
        $branchId = $this->resolveBranchIdForWrite($request);
        $this->assertBranchBelongsToTenant($branchId, $purchase->tenant_id);

        $newSubtotal = 0.0;
        foreach ($validated['items'] as $line) {
            $newSubtotal += (float) $line['quantity'] * (float) $line['unit_price'];
        }
        $newSubtotal = round($newSubtotal, 2);
        if ($newSubtotal + 0.001 < (float) $purchase->amount_paid) {
            abort(422, 'El total no puede ser menor a lo ya pagado ($'.number_format((float) $purchase->amount_paid, 2).'). Cancela un pago primero.');
        }

        $auditor = app(AuditLogger::class);
        $before = $auditor->purchaseSnapshot($purchase->loadMissing('provider', 'items'));

        DB::transaction(function () use ($purchase, $validated, $branchId, $newSubtotal) {
            $purchase->update([
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'subtotal' => $newSubtotal,
                'total' => $newSubtotal,
                'notes' => $validated['notes'] ?? null,
            ]);

            $purchase->items()->delete();
            foreach ($validated['items'] as $line) {
                $product = $this->resolvePurchaseProduct($purchase->tenant_id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit']);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });

        if ($request->hasFile('attachments')) {
            $request->validate([
                'attachments.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp,pdf',
                    'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                    'max:'.(PurchaseAttachmentService::MAX_BYTES / 1024),
                ],
            ]);
            app(PurchaseAttachmentService::class)->attach($purchase, $request->file('attachments'), Auth::id());
        }

        $payments->recalculate($purchase);

        $after = $auditor->purchaseSnapshot($purchase->fresh()->loadMissing('provider', 'items'));
        $auditor->logUpdatedIfChanged($purchase, $before, $after);

        return $this->redirectAfterWrite($request, 'Compra actualizada.');
    }
```

- [ ] **Step 5: Log de cancelación en `HandlesPurchases::cancel`**

Al final de `cancel()`, antes del `return back()->with('success', 'Compra cancelada.');`, añade (la lógica de devolver efectivo se completa en la Task 9; aquí solo el log):

```php
        app(AuditLogger::class)->logCancelled($purchase, $validated['reason']);

        return back()->with('success', 'Compra cancelada.');
```

- [ ] **Step 6: Log de pagos en `PurchasePaymentService`**

En `app/Services/PurchasePaymentService.php` añade el import `use App\Services\AuditLogger;`. En `applyPayment`, dentro del `DB::transaction`, después de `$this->recalculate($locked);` y antes de `return $payment;`, añade:

```php
            if ($locked->purchase_id ?? $locked->id) {
                app(AuditLogger::class)->logPaymentAdded(
                    $locked,
                    $amount,
                    $payment->payment_method->value,
                    $payload['user_id'] ?? null,
                );
            }
```

En `cancelPayment`, dentro del `if ($payment->purchase_id)` (justo después de `$this->recalculate($purchase);`), añade:

```php
                    app(AuditLogger::class)->logPaymentCancelled(
                        $purchase,
                        (float) $payment->amount,
                        $payment->payment_method->value,
                        $reason,
                        $cancelledBy,
                    );
```

- [ ] **Step 7: Correr los tests**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHistoryTest`
Expected: PASS (todos).

- [ ] **Step 8: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Concerns/HandlesPurchases.php app/Services/PurchasePaymentService.php tests/Feature/Historial/PurchaseHistoryTest.php
git commit -m "feat(historial): registra creada/editada/cancelada/pagos en compras"
```

---

## Task 6: Historial en Gastos (admin + caja)

**Files:**
- Modify: `app/Http/Controllers/Empresa/GastoController.php`
- Modify: `app/Http/Controllers/Sucursal/GastoController.php`
- Modify: `app/Http/Controllers/Caja/GastoController.php`
- Test: `tests/Feature/Historial/ExpenseHistoryTest.php`

- [ ] **Step 1: Escribir el test del historial de gasto**

Crea `tests/Feature/Historial/ExpenseHistoryTest.php`:

```php
<?php

namespace Tests\Feature\Historial;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseHistoryTest extends TestCase
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

    private function payload(array $override = []): array
    {
        return array_merge([
            'concept' => 'Bolsas',
            'amount' => 50,
            'expense_subcategory_id' => $this->subId(),
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], $override);
    }

    public function test_admin_create_edit_cancel_writes_history(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->subId();

        $this->post(route('empresa.gastos.store', $this->tenant->slug), $this->payload(['expense_subcategory_id' => $sub]))->assertRedirect();
        $expense = Expense::firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $expense->id, 'event' => AuditEvent::Created->value]);

        $this->put(route('empresa.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $expense->id]),
            $this->payload(['amount' => 75, 'expense_subcategory_id' => $sub]))->assertRedirect();
        $log = AuditLog::where('event', AuditEvent::Updated->value)->firstOrFail();
        $this->assertSame([50.0, 75.0], $log->changes['fields']['amount']);

        $this->delete(route('empresa.gastos.destroy', ['tenant' => $this->tenant->slug, 'gasto' => $expense->id]),
            ['cancellation_reason' => 'error'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $expense->id, 'event' => AuditEvent::Cancelled->value]);
    }
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=ExpenseHistoryTest`
Expected: FAIL — no hay entradas en `audit_logs`.

- [ ] **Step 3: Wire en `Empresa\GastoController`**

Añade el import `use App\Services\AuditLogger;`.

En `store()`, después de cerrar el `DB::transaction(...)` (donde `$expense` queda asignado), antes del `return back()->with('success', 'Gasto registrado.');`:

```php
        app(AuditLogger::class)->logCreated($expense);

        return back()->with('success', 'Gasto registrado.');
```

En `update()`, captura el snapshot antes y registra el diff. Reemplaza desde `$validated = ...` hasta el `return`:

```php
        $validated = $request->validate($this->validationRules($tenant->id), $this->messages());

        $auditor = app(AuditLogger::class);
        $before = $auditor->expenseSnapshot($gasto->loadMissing('subcategory', 'branch'));

        $gasto->update([
            'branch_id' => $validated['branch_id'],
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? null,
            'expense_at' => $this->buildExpenseAt($validated['expense_date']),
            'description' => $validated['description'] ?? null,
            'updated_by' => $user->id,
        ]);

        if ($request->hasFile('attachments')) {
            $remaining = $gasto->attachments()->count();
            $incoming = count($request->file('attachments'));
            if ($remaining + $incoming > ExpenseAttachmentService::MAX_PER_EXPENSE) {
                return back()->withErrors([
                    'attachments' => 'Máximo '.ExpenseAttachmentService::MAX_PER_EXPENSE.' adjuntos por gasto.',
                ]);
            }
            $this->attachments->attach($gasto, $request->file('attachments'), $user->id);
        }

        $after = $auditor->expenseSnapshot($gasto->fresh()->loadMissing('subcategory', 'branch'));
        $auditor->logUpdatedIfChanged($gasto, $before, $after);
        $this->recalcShiftIfClosed($gasto);

        return back()->with('success', 'Gasto actualizado.');
```

En `destroy()`, después de `$gasto->delete();` y antes del `return`:

```php
        app(AuditLogger::class)->logCancelled($gasto, $reason ?? '');
        $this->recalcShiftIfClosed($gasto);

        return back()->with('success', 'Gasto eliminado.');
```

Añade este helper privado al final de la clase (`Empresa\GastoController`), con los imports `use App\Models\CashRegisterShift;` y `use App\Services\RecalculateClosedShifts;`:

```php
    /**
     * Si el gasto estaba ligado a un turno YA cerrado, su monto/baja cambia el
     * efectivo del corte → recalcula ese corte. Turno abierto no hace falta
     * (el corte suma en vivo al cerrar).
     */
    private function recalcShiftIfClosed(Expense $gasto): void
    {
        if (! $gasto->cash_register_shift_id) {
            return;
        }
        $shift = CashRegisterShift::find($gasto->cash_register_shift_id);
        if ($shift && $shift->closed_at) {
            app(RecalculateClosedShifts::class)->forShift($shift);
        }
    }
```

- [ ] **Step 4: Wire idéntico en `Sucursal\GastoController`**

Aplica exactamente los mismos cambios del Step 3 a `app/Http/Controllers/Sucursal/GastoController.php` (mismo `store`/`update`/`destroy`, mismo helper `recalcShiftIfClosed`, mismos imports). Nota: el `update` de Sucursal no reasigna `branch_id` (viene del usuario), así que **no** incluyas `'branch_id' => ...` en su `$gasto->update([...])` — deja el resto igual.

- [ ] **Step 5: Wire en `Caja\GastoController::store`**

Añade `use App\Services\AuditLogger;`. Dentro del `DB::transaction(...)` de `store()`, después de crear `$expense` (y antes de cerrar la función anónima), añade:

```php
            app(AuditLogger::class)->logCreated($expense);
```

- [ ] **Step 6: Correr el test**

Run: `vendor/bin/sail artisan test --compact --filter=ExpenseHistoryTest`
Expected: PASS.

- [ ] **Step 7: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Empresa/GastoController.php app/Http/Controllers/Sucursal/GastoController.php app/Http/Controllers/Caja/GastoController.php tests/Feature/Historial/ExpenseHistoryTest.php
git commit -m "feat(historial): registra creada/editada/cancelada en gastos + recálculo de corte"
```

---

## Task 7: Serializar `history` hacia el frontend

**Files:**
- Modify: `app/Http/Controllers/Concerns/HandlesPurchases.php` (`serializePurchase` + eager loads de los index de Empresa/Sucursal)
- Modify: `app/Http/Controllers/Empresa/PurchaseController.php`, `Sucursal/PurchaseController.php` (eager load `history.user`)
- Modify: `app/Http/Controllers/Empresa/GastoController.php`, `Sucursal/GastoController.php` (eager load `history.user`)

- [ ] **Step 1: Añadir `history` a `serializePurchase`**

En `HandlesPurchases::serializePurchase`, antes del cierre del array (después de `'cancel_reason' => $p->cancel_reason,`), añade:

```php
            'history' => $p->relationLoaded('history')
                ? $p->history->take(50)->map(fn ($h) => [
                    'event' => $h->event->value,
                    'user_name' => $h->user?->name ?? 'Usuario eliminado',
                    'created_at' => $h->created_at?->toIso8601String(),
                    'changes' => $h->changes,
                ])->values()
                : [],
```

- [ ] **Step 2: Eager-load `history.user` en los index de compras**

En `Sucursal\PurchaseController::index` y `Empresa\PurchaseController::index`, agrega `'history.user:id,name'` al array `->with([...])` de la query de compras (junto a `'items'`, `'attachments'`, `'payments'`).

- [ ] **Step 3: Eager-load `history.user` en los index de gastos**

En `Empresa\GastoController::index` y `Sucursal\GastoController::index`, agrega `'history.user:id,name'` al `->with([...])` de la query de gastos. (Los modelos crudos serializan la relación cargada; el componente leerá `expense.history`.)

- [ ] **Step 4: Verificación (Inertia prop presente)**

Añade a `tests/Feature/Historial/PurchaseHistoryTest.php`:

```php
public function test_index_exposes_history_in_purchase_payload(): void
{
    $this->actingAs($this->adminSucursal);
    $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();

    $this->get(route('sucursal.compras.index', $this->tenant->slug))
        ->assertInertia(fn ($page) => $page
            ->has('purchases.0.history', 1)
            ->where('purchases.0.history.0.event', 'created'));
}
```

Run: `vendor/bin/sail artisan test --compact --filter=test_index_exposes_history_in_purchase_payload`
Expected: PASS.

- [ ] **Step 5: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Concerns/HandlesPurchases.php app/Http/Controllers/Empresa/PurchaseController.php app/Http/Controllers/Sucursal/PurchaseController.php app/Http/Controllers/Empresa/GastoController.php app/Http/Controllers/Sucursal/GastoController.php tests/Feature/Historial/PurchaseHistoryTest.php
git commit -m "feat(historial): expone history en payloads de compras y gastos"
```

---

## Task 8: Componente `HistorialTimeline.vue` + montaje en modales

**Files:**
- Create: `resources/js/Components/Historial/HistorialTimeline.vue`
- Modify: `resources/js/Components/Compras/CompraDetailModal.vue`
- Modify: `resources/js/Components/Gastos/GastoDetailModal.vue`

- [ ] **Step 1: Crear `HistorialTimeline.vue`**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    history: { type: Array, default: () => [] },
});

const EVENT_LABEL = {
    created: 'Creó',
    updated: 'Editó',
    cancelled: 'Canceló',
    payment_added: 'Registró pago',
    payment_cancelled: 'Canceló pago',
};
const EVENT_ICON = {
    created: '🟢', updated: '✏️', cancelled: '🔴', payment_added: '💵', payment_cancelled: '↩️',
};
const FIELD_LABEL = {
    provider: 'Proveedor', invoice_number: 'Factura', purchased_at: 'Fecha', total: 'Total', notes: 'Notas',
    concept: 'Concepto', amount: 'Monto', subcategory: 'Subcategoría', payment_method: 'Método', expense_at: 'Fecha',
    description: 'Notas', branch: 'Sucursal',
};
const METHOD_LABEL = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };

const money = (v) => '$' + Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtVal = (key, v) => {
    if (v === null || v === undefined || v === '') return '∅';
    if (key === 'total' || key === 'amount') return money(v);
    if (key === 'payment_method') return METHOD_LABEL[v] || v;
    return String(v);
};
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
const userName = (h) => h.user_name ?? h.user?.name ?? 'Usuario eliminado';

const entries = computed(() => props.history || []);

// Convierte changes en líneas legibles.
const lines = (h) => {
    const out = [];
    const c = h.changes || {};
    if (h.event === 'cancelled' && c.reason) out.push(`Motivo: ${c.reason}`);
    if (h.event === 'payment_added') out.push(`${METHOD_LABEL[c.method] || c.method} +${money(c.amount)}`);
    if (h.event === 'payment_cancelled') out.push(`${METHOD_LABEL[c.method] || c.method} −${money(c.amount)}${c.reason ? ` · ${c.reason}` : ''}`);
    if (c.fields) {
        for (const [k, pair] of Object.entries(c.fields)) {
            out.push(`${FIELD_LABEL[k] || k}: ${fmtVal(k, pair[0])} → ${fmtVal(k, pair[1])}`);
        }
    }
    if (c.items) {
        (c.items.added || []).forEach(i => out.push(`+ línea "${i.concept}" ${i.quantity} ${i.unit} × ${money(i.unit_price)}`));
        (c.items.removed || []).forEach(i => out.push(`− línea "${i.concept}"`));
        (c.items.changed || []).forEach(i => out.push(`~ línea "${i.concept}" ${i.from.quantity}×${money(i.from.unit_price)} → ${i.to.quantity}×${money(i.to.unit_price)}`));
    }
    return out;
};
</script>

<template>
    <div>
        <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-700">Historial</h3>
        <p v-if="!entries.length" class="rounded-lg bg-gray-50 px-3 py-3 text-sm italic text-gray-500">
            Sin cambios registrados.
        </p>
        <ul v-else class="space-y-2">
            <li v-for="(h, idx) in entries" :key="idx" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <div class="flex items-center gap-2 text-gray-900">
                    <span>{{ EVENT_ICON[h.event] || '•' }}</span>
                    <span class="font-semibold">{{ EVENT_LABEL[h.event] || h.event }}</span>
                    <span class="text-gray-500">· {{ userName(h) }}</span>
                    <span class="ml-auto text-xs text-gray-400">{{ fmtDate(h.created_at) }}</span>
                </div>
                <ul v-if="lines(h).length" class="mt-1 space-y-0.5 pl-6 text-xs text-gray-600">
                    <li v-for="(l, i) in lines(h)" :key="i">{{ l }}</li>
                </ul>
            </li>
        </ul>
    </div>
</template>
```

- [ ] **Step 2: Montar en `CompraDetailModal.vue`**

Importa el componente en el `<script setup>`:

```js
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
```

En el `<template>`, después del bloque de Notas (`<!-- Notas -->` … `</div>`) y antes del cierre del contenedor scroll (`</div>` que cierra `max-h-[80vh]`), añade:

```html
                        <!-- Historial -->
                        <HistorialTimeline :history="purchase.history || []" />
```

- [ ] **Step 3: Montar en `GastoDetailModal.vue`**

Importa el componente y añádelo al final del cuerpo (después del bloque de Adjuntos, dentro del `<div class="flex-1 overflow-y-auto ...">`):

```js
import HistorialTimeline from '@/Components/Historial/HistorialTimeline.vue';
```

```html
                        <!-- Historial -->
                        <HistorialTimeline :history="expense.history || []" />
```

- [ ] **Step 4: Compilar y verificar manualmente**

Run: `vendor/bin/sail npm run build`
Expected: build sin errores.

Verificación manual: abre `/{tenant}/sucursal/compras`, crea una compra, edítala (cambia el total), abre su detalle → la sección "Historial" muestra "Creó" y "Editó · Total $X → $Y". Igual en Gastos.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Historial/HistorialTimeline.vue resources/js/Components/Compras/CompraDetailModal.vue resources/js/Components/Gastos/GastoDetailModal.vue
git commit -m "feat(historial): timeline reutilizable en modales de detalle"
```

---

# FASE 2 — Caja corrige (turno abierto)

## Task 9: Cancelar devuelve el efectivo + recálculo de corte

**Files:**
- Modify: `app/Http/Controllers/Concerns/HandlesPurchases.php` (`cancel`)
- Test: `tests/Feature/Compras/PurchaseCancellationCashTest.php`

- [ ] **Step 1: Escribir el test**

Crea `tests/Feature/Compras/PurchaseCancellationCashTest.php`:

```php
<?php

namespace Tests\Feature\Compras;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\ProviderPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseCancellationCashTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cancelling_purchase_cancels_cash_payments_and_recalcs_closed_shift(): void
    {
        // Turno cerrado con un pago a proveedor en efectivo de 300.
        $shift = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now()->subHours(2), 'closed_at' => now()->subHour(), 'opening_amount' => 1000,
            'expected_amount' => 700, 'total_cash_provider_payments' => 300, 'declared_amount' => 700, 'difference' => 0,
        ]);

        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-X', 'purchased_at' => now()->subHour(), 'status' => 'received',
            'subtotal' => 300, 'total' => 300, 'amount_paid' => 300, 'amount_pending' => 0,
            'created_by' => $this->cajero->id, 'cash_register_shift_id' => $shift->id,
        ]);
        ProviderPayment::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id, 'provider_id' => $purchase->provider_id,
            'purchase_id' => $purchase->id, 'paid_at' => now()->subHour(), 'amount' => 300,
            'payment_method' => 'cash', 'user_id' => $this->cajero->id,
        ]);

        $this->actingAs($this->adminSucursal);
        $this->patch(route('sucursal.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['reason' => 'duplicada'])->assertRedirect();

        // El pago quedó cancelado.
        $this->assertNotNull(ProviderPayment::where('purchase_id', $purchase->id)->first()->cancelled_at);
        // El corte cerrado se recalculó: ya no resta los 300 → esperado 1000.
        $shift->refresh();
        $this->assertSame('1000.00', $shift->expected_amount);
        $this->assertSame('0.00', $shift->total_cash_provider_payments);
    }
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseCancellationCashTest`
Expected: FAIL — el pago no se cancela; `expected_amount` sigue en 700.

- [ ] **Step 3: Completar `HandlesPurchases::cancel`**

Añade imports a `HandlesPurchases`:

```php
use App\Enums\PaymentMethod;
use App\Models\CashRegisterShift;
use App\Services\RecalculateClosedShifts;
```

Reemplaza el cuerpo de `cancel()` desde `$purchase->update([... cancelled ...])` por:

```php
        $purchase->update([
            'status' => PurchaseStatus::Cancelled,
            'cancelled_by' => Auth::id(),
            'cancelled_at' => now(),
            'cancel_reason' => $validated['reason'],
        ]);

        // Devolver el efectivo: cancelar los pagos en efectivo vivos y
        // recalcular los cortes CERRADOS afectados (los abiertos suman en vivo).
        $payments = app(PurchasePaymentService::class);
        $affectedShiftIds = [];
        $cashPayments = $purchase->payments()
            ->whereNull('cancelled_at')
            ->where('payment_method', PaymentMethod::Cash->value)
            ->get();
        foreach ($cashPayments as $pago) {
            if ($pago->cash_register_shift_id) {
                $affectedShiftIds[$pago->cash_register_shift_id] = true;
            }
            $payments->cancelPayment($pago, Auth::id(), 'Compra cancelada: '.$validated['reason']);
        }
        $recalc = app(RecalculateClosedShifts::class);
        foreach (array_keys($affectedShiftIds) as $shiftId) {
            $shift = CashRegisterShift::find($shiftId);
            if ($shift && $shift->closed_at) {
                $recalc->forShift($shift);
            }
        }

        app(AuditLogger::class)->logCancelled($purchase, $validated['reason']);

        return back()->with('success', 'Compra cancelada.');
```

Nota: borra la línea `app(AuditLogger::class)->logCancelled(...)` que añadiste en la Task 5 Step 5 si quedó duplicada — debe quedar **una sola** al final.

- [ ] **Step 4: Correr el test**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseCancellationCashTest`
Expected: PASS.

- [ ] **Step 5: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Concerns/HandlesPurchases.php tests/Feature/Compras/PurchaseCancellationCashTest.php
git commit -m "feat(compras): cancelar devuelve efectivo y recalcula corte cerrado"
```

---

## Task 10: Rutas de corrección en Caja

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Añadir las rutas dentro del grupo `caja`**

En `routes/web.php`, dentro del grupo `Route::middleware('role:cajero|superadmin')->prefix('caja')->name('caja.')->group(...)`, junto a las rutas de `compras`/`gastos` existentes (después de `Route::post('compras/ia/borrador', ...)` y de las de gastos), añade:

```php
                // Corrección de compras propias (turno abierto): editar, cancelar, pagar.
                Route::put('compras/{compra}', [CajaPurchaseController::class, 'update'])->whereNumber('compra')->name('compras.update');
                Route::patch('compras/{compra}/cancelar', [CajaPurchaseController::class, 'cancel'])->whereNumber('compra')->name('compras.cancel');
                Route::post('compras/{compra}/pagos', [CajaPurchaseController::class, 'storePayment'])->whereNumber('compra')->name('compras.pagos.store');
                Route::delete('compras/{compra}/pagos/{pago}', [CajaPurchaseController::class, 'destroyPayment'])->whereNumber('compra')->whereNumber('pago')->name('compras.pagos.destroy');

                // Corrección de gastos propios (turno abierto): editar, cancelar.
                Route::put('gastos/{gasto}', [CajaGastoController::class, 'update'])->whereNumber('gasto')->name('gastos.update');
                Route::delete('gastos/{gasto}', [CajaGastoController::class, 'destroy'])->whereNumber('gasto')->name('gastos.destroy');
```

- [ ] **Step 2: Verificar que las rutas se registran**

Run: `vendor/bin/sail artisan route:list --path=caja --except-vendor`
Expected: aparecen `caja.compras.update`, `caja.compras.cancel`, `caja.compras.pagos.store`, `caja.compras.pagos.destroy`, `caja.gastos.update`, `caja.gastos.destroy`.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat(caja): rutas de editar/cancelar/pagar compras y gastos"
```

---

## Task 11: `Caja\PurchaseController` — update/cancel/pagar + candado

**Files:**
- Modify: `app/Http/Controllers/Caja/PurchaseController.php`
- Test: `tests/Feature/Caja/CajaPurchaseCorrectionTest.php`

- [ ] **Step 1: Escribir los tests del candado y de pagar**

Crea `tests/Feature/Caja/CajaPurchaseCorrectionTest.php`:

```php
<?php

namespace Tests\Feature\Caja;

use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CajaPurchaseCorrectionTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now(), 'opening_amount' => 1000,
        ]);
    }

    private function purchase(CashRegisterShift $shift, float $paid = 0, float $total = 100): Purchase
    {
        return Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-'.uniqid(), 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => $total, 'total' => $total, 'amount_paid' => $paid, 'amount_pending' => $total - $paid,
            'created_by' => $this->cajero->id, 'cash_register_shift_id' => $shift->id,
        ]);
    }

    public function test_cajero_registers_payment_with_open_shift(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['amount' => 100])->assertRedirect();

        $purchase->refresh();
        $this->assertSame('100.00', $purchase->amount_paid);
        $this->assertDatabaseHas('provider_payments', [
            'purchase_id' => $purchase->id, 'cash_register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount' => 100,
        ]);
    }

    public function test_cajero_cannot_pay_purchase_of_a_closed_shift(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);
        $shift->update(['closed_at' => now()]);

        $this->actingAs($this->cajero);
        $this->post(route('caja.compras.pagos.store', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['amount' => 100])->assertForbidden();
    }

    public function test_cajero_cannot_edit_another_cajeros_purchase(): void
    {
        $shift = $this->openShift();
        $otro = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P2', 'type' => 'otro'])->id,
            'folio' => 'CMP-AJENA', 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $otro->id, 'cash_register_shift_id' => $shift->id,
        ]);

        $this->actingAs($this->cajero);
        $this->put(route('caja.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'provider_id' => $purchase->provider_id, 'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 50]],
        ])->assertForbidden();
    }

    public function test_cajero_cancels_own_open_shift_purchase(): void
    {
        $shift = $this->openShift();
        $purchase = $this->purchase($shift, 0, 100);

        $this->actingAs($this->cajero);
        $this->patch(route('caja.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['reason' => 'me equivoqué'])->assertRedirect();

        $this->assertSame('cancelled', $purchase->refresh()->status->value);
    }
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=CajaPurchaseCorrectionTest`
Expected: FAIL — métodos `update/cancel/storePayment` no existen (500/404).

- [ ] **Step 3: Implementar los métodos en `Caja\PurchaseController`**

Añade imports:

```php
use App\Models\ProviderPayment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
```

Añade el helper del candado y los métodos (antes de los hooks del trait):

```php
    /**
     * Turno abierto del cajero actual (o null).
     */
    private function openShift(): ?CashRegisterShift
    {
        return CashRegisterShift::where('user_id', Auth::id())
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Candado de Caja: la compra debe ser del cajero y pertenecer a su turno
     * abierto. Si no, 403.
     */
    private function assertCajaCanMutate(Purchase $purchase): CashRegisterShift
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
        $shift = $this->openShift();
        if (! $shift
            || $purchase->created_by !== Auth::id()
            || $purchase->cash_register_shift_id !== $shift->id) {
            abort(403, 'Solo puedes corregir tus compras del turno abierto.');
        }

        return $shift;
    }

    public function update(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        // Reusa la lógica compartida (incluye regla "total >= pagado" e historial).
        return $this->updatePurchase($request, $compra, $payments);
    }

    public function cancel(Request $request, Purchase $compra): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        return $this->cancelPurchase($request, $compra);
    }

    public function storePayment(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $shift = $this->assertCajaCanMutate($compra);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $payments->applyPayment($compra, [
            'amount' => $validated['amount'],
            'payment_method' => 'cash',
            'user_id' => Auth::id(),
            'cash_register_shift_id' => $shift->id,
        ]);

        return back()->with('success', 'Pago registrado.');
    }

    public function destroyPayment(Request $request, Purchase $compra, ProviderPayment $pago, PurchasePaymentService $payments): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($compra);

        if ($pago->purchase_id !== $compra->id) {
            abort(404);
        }
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $payments->cancelPayment($pago, Auth::id(), $reason);

        return back()->with('success', 'Pago cancelado.');
    }
```

> **Nota de diseño:** los métodos `update`/`cancel` del trait `HandlesPurchases` se llaman `update`/`cancel`. Como el controlador de Caja **sobrescribe** esos nombres, renombramos los del trait a `updatePurchase`/`cancelPurchase` para poder reusarlos. Hazlo en el Step 4.

- [ ] **Step 4: Renombrar los métodos del trait y ajustar Empresa/Sucursal**

En `HandlesPurchases`, renombra `public function update(...)` → `public function updatePurchase(...)` y `public function cancel(...)` → `public function cancelPurchase(...)`.

En las rutas de Empresa y Sucursal (`routes/web.php`), los `compras.update` y `compras.cancel` apuntan a `[...PurchaseController::class, 'update']` / `'cancel'`. Cambia esos dos por `'updatePurchase'` / `'cancelPurchase'` **solo en los grupos empresa y sucursal** (no en caja, que tiene sus propios métodos). Busca:

```php
Route::put('{compra}', [EmpresaPurchaseController::class, 'update'])
// → 'updatePurchase'
Route::patch('{compra}/cancelar', [EmpresaPurchaseController::class, 'cancel'])
// → 'cancelPurchase'
```

y lo equivalente para `SucursalPurchaseController`. (Confirma los nombres exactos con `grep -n "compras" routes/web.php`.)

- [ ] **Step 5: Serializar las compras de Caja con `history` + `can_manage`**

En `Caja\PurchaseController::index`, cambia el armado de `$purchases`. Hoy pagina y pasa el modelo crudo; ahora serializa con el trait y añade `can_manage`. Reemplaza el bloque `$purchases = (clone $query)...->paginate(25)...` y el eager-load por:

```php
        $shift = $this->openShift();
        $shiftId = $shift?->id;

        $query = Purchase::query()
            ->where('branch_id', $user->branch_id)
            ->where('created_by', $user->id)
            ->where('status', '!=', \App\Enums\PurchaseStatus::Cancelled)
            ->with(['provider:id,name', 'branch:id,name', 'items', 'payments', 'attachments', 'history.user:id,name'])
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($q2) => $q2
                    ->where('folio', 'ilike', "%{$s}%")
                    ->orWhere('invoice_number', 'ilike', "%{$s}%")
                    ->orWhereHas('provider', fn ($pq) => $pq->where('name', 'ilike', "%{$s}%")));
            });

        $purchases = (clone $query)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Purchase $p) use ($shiftId) {
                $data = $this->serializePurchase($p);
                $data['can_manage'] = $shiftId !== null
                    && $p->cash_register_shift_id === $shiftId
                    && $p->status !== \App\Enums\PurchaseStatus::Cancelled;

                return $data;
            });
```

(El `totals` que ya existe debe seguir usando `(clone $query)->sum('total')` y `->count()`; quedan igual.)

- [ ] **Step 6: Correr los tests**

Run: `vendor/bin/sail artisan test --compact --filter=CajaPurchaseCorrectionTest`
Expected: PASS.

- [ ] **Step 7: Correr la suite de compras/caja para no romper nada**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Caja tests/Feature/Compras`
Expected: PASS (incluye los tests existentes de `CajaPurchaseControllerTest`, `PurchaseControllerTest`).

- [ ] **Step 8: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Caja/PurchaseController.php app/Http/Controllers/Concerns/HandlesPurchases.php routes/web.php tests/Feature/Caja/CajaPurchaseCorrectionTest.php
git commit -m "feat(caja): editar/cancelar/pagar compras propias con candado de turno"
```

---

## Task 12: `Caja\GastoController` — update/destroy + candado

**Files:**
- Modify: `app/Http/Controllers/Caja/GastoController.php`
- Test: `tests/Feature/Caja/CajaGastoCorrectionTest.php`

- [ ] **Step 1: Escribir los tests**

Crea `tests/Feature/Caja/CajaGastoCorrectionTest.php`:

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

class CajaGastoCorrectionTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'expense_category_id' => $cat->id, 'name' => 'Insumos', 'status' => 'active',
        ])->id;
    }

    private function openShift(): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now(), 'opening_amount' => 1000,
        ]);
    }

    private function expense(CashRegisterShift $shift, ?int $userId = null): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id, 'expense_subcategory_id' => $this->subId(),
            'user_id' => $userId ?? $this->cajero->id, 'concept' => 'Bolsas', 'amount' => 50,
            'payment_method' => 'cash', 'expense_at' => now(),
        ]);
    }

    public function test_cajero_edits_own_open_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);

        $this->actingAs($this->cajero);
        $this->put(route('caja.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]), [
            'concept' => 'Bolsas grandes', 'amount' => 80, 'expense_subcategory_id' => $e->expense_subcategory_id,
        ])->assertRedirect();

        $this->assertSame('80.00', $e->refresh()->amount);
    }

    public function test_cajero_cancels_own_open_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);

        $this->actingAs($this->cajero);
        $this->delete(route('caja.gastos.destroy', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]),
            ['cancellation_reason' => 'duplicado'])->assertRedirect();

        $this->assertSoftDeleted('expenses', ['id' => $e->id]);
    }

    public function test_cajero_cannot_edit_closed_shift_expense(): void
    {
        $shift = $this->openShift();
        $e = $this->expense($shift);
        $shift->update(['closed_at' => now()]);

        $this->actingAs($this->cajero);
        $this->put(route('caja.gastos.update', ['tenant' => $this->tenant->slug, 'gasto' => $e->id]), [
            'concept' => 'X', 'amount' => 80, 'expense_subcategory_id' => $e->expense_subcategory_id,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=CajaGastoCorrectionTest`
Expected: FAIL — `update`/`destroy` no existen.

- [ ] **Step 3: Implementar en `Caja\GastoController`**

Añade imports: `use App\Models\Expense;` (ya está), `use App\Services\AuditLogger;`, `use Illuminate\Validation\Rule;` (ya está). Añade el candado y los métodos:

```php
    private function assertCajaCanMutate(Expense $gasto): CashRegisterShift
    {
        if ($gasto->tenant_id !== app('tenant')->id) {
            abort(404);
        }
        $shift = CashRegisterShift::where('user_id', Auth::id())->whereNull('closed_at')->first();
        if (! $shift
            || $gasto->user_id !== Auth::id()
            || $gasto->cash_register_shift_id !== $shift->id) {
            abort(403, 'Solo puedes corregir tus gastos del turno abierto.');
        }

        return $shift;
    }

    public function update(Request $request, Expense $gasto): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($gasto);

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', app('tenant')->id)->where('status', 'active')),
            ],
            'description' => 'nullable|string|max:1000',
        ]);

        $auditor = app(AuditLogger::class);
        $before = $auditor->expenseSnapshot($gasto->loadMissing('subcategory', 'branch'));

        $gasto->update([
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'description' => $validated['description'] ?? null,
            'updated_by' => Auth::id(),
        ]);

        $after = $auditor->expenseSnapshot($gasto->fresh()->loadMissing('subcategory', 'branch'));
        $auditor->logUpdatedIfChanged($gasto, $before, $after);

        return back()->with('success', 'Gasto actualizado.');
    }

    public function destroy(Request $request, Expense $gasto): RedirectResponse
    {
        $this->ensureModuleEnabled(Auth::user()->branch_id);
        $this->assertCajaCanMutate($gasto);

        $reason = $request->validate([
            'cancellation_reason' => 'nullable|string|max:255',
        ])['cancellation_reason'] ?? null;

        $gasto->update(['cancelled_by' => Auth::id(), 'cancellation_reason' => $reason]);
        $gasto->delete();
        app(AuditLogger::class)->logCancelled($gasto, $reason ?? '');

        return back()->with('success', 'Gasto eliminado.');
    }
```

> Nota: el cajero solo edita/cancela gastos de su **turno abierto**; ese turno no está cerrado, así que el corte suma en vivo y **no** hace falta `RecalculateClosedShifts` aquí.

- [ ] **Step 4: Serializar gastos de Caja con `history` + `can_manage`**

En `Caja\GastoController::index`, añade `'history.user:id,name'` y `'branch:id,name'` y `'user:id,name'` al `->with([...])`, y transforma con `can_manage`. Tras `->paginate(25)->withQueryString()`, encadena:

```php
            ->through(function (Expense $e) {
                $shift = CashRegisterShift::where('user_id', Auth::id())->whereNull('closed_at')->first();
                $e->setAttribute('can_manage', $shift && $e->cash_register_shift_id === $shift->id);

                return $e;
            });
```

(Como es modelo crudo, `setAttribute('can_manage', ...)` lo añade al JSON serializado; las relaciones cargadas también se serializan.)

- [ ] **Step 5: Correr los tests**

Run: `vendor/bin/sail artisan test --compact --filter=CajaGastoCorrectionTest`
Expected: PASS.

- [ ] **Step 6: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Caja/GastoController.php tests/Feature/Caja/CajaGastoCorrectionTest.php
git commit -m "feat(caja): editar/cancelar gastos propios con candado de turno"
```

---

## Task 13: Frontend de Caja — filas clicables + reuso de modales

**Files:**
- Modify: `resources/js/Components/Compras/CompraDetailModal.vue` (prop `canManage`, validator + adjuntos opcionales)
- Modify: `resources/js/Components/Compras/CompraFormModal.vue` (ocultar pago al editar)
- Modify: `resources/js/Pages/Caja/Compras/Index.vue`
- Modify: `resources/js/Pages/Caja/Gastos/Index.vue`

- [ ] **Step 1: `CompraDetailModal` — prop `canManage` y rutas opcionales**

En el `defineProps`, añade:

```js
    canManage: { type: Boolean, default: true },
```

Cambia el `validator` de `routes` a:

```js
        validator: (v) => !!v.cancel,
```

Sustituye los `v-if="!isCancelled"` de los botones de acción para que respeten `canManage`:
- Botón "Cancelar compra": `v-if="!isCancelled && canManage"`
- Botón "Editar": `v-if="!isCancelled && canManage"`
- Botón "+ Registrar pago": cambia `v-if="!isCancelled && purchase.amount_pending > 0 && routes.pagoStore"` por `v-if="!isCancelled && canManage && purchase.amount_pending > 0 && routes.pagoStore"`
- Botón "Cancelar" pago (en la lista de pagos): `v-if="!isCancelled && canManage && routes.pagoDestroy"`

Protege el bloque de adjuntos para Caja (que no tiene esas rutas): cambia `v-if="purchase.attachments?.length"` por `v-if="purchase.attachments?.length && routes.adjuntoPreview"`.

- [ ] **Step 2: `CompraFormModal` — no tocar pago al editar**

En el bloque "Pagado en efectivo (modo caja)", cambia el `v-if` del contenedor de `cashMode` a `cashMode && !purchase` (el campo de pago solo aparece al **crear**, no al editar):

```html
                        <!-- Pagado en efectivo (modo caja, solo al crear) -->
                        <div v-if="cashMode && !purchase" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
```

- [ ] **Step 3: `Caja/Compras/Index.vue` — filas clicables + detalle**

Importa el detalle:

```js
import CompraDetailModal from '@/Components/Compras/CompraDetailModal.vue';
```

Añade estado y rutas en `<script setup>`:

```js
const detailOpen = ref(false);
const selected = ref(null);
const editFromDetail = ref(false);

const cajaCompraRoutes = {
    cancel: 'caja.compras.cancel',
    pagoStore: 'caja.compras.pagos.store',
    pagoDestroy: 'caja.compras.pagos.destroy',
};

const openDetail = (p) => { selected.value = p; detailOpen.value = true; };
const onEdit = () => {
    detailOpen.value = false;
    compraAiResult.value = null;
    editFromDetail.value = true;
    compraOpen.value = true;
};
const onDetailRefresh = () => router.reload({ preserveScroll: true });
```

En la fila de la tabla, hazla clicable:

```html
                        <tr v-for="p in purchases.data" :key="p.id" @click="openDetail(p)"
                            class="cursor-pointer transition hover:bg-red-50/30">
```

El `CompraFormModal` existente: cuando `editFromDetail` es true, debe abrir en modo edición de `selected`. Cambia su uso para pasar `:purchase`:

```html
        <CompraFormModal :open="compraOpen" :purchase="editFromDetail ? selected : null" cash-mode
            :providers="providers" :purchase-products="purchaseProducts"
            :fixed-branch-id="branchId" :ai-result="compraAiResult"
            :routes="{ store: 'caja.compras.store', update: 'caja.compras.update' }"
            @close="compraOpen = false; compraAiResult = null; editFromDetail = false" />
```

Monta el detalle antes de `<FlashToast />`:

```html
        <CompraDetailModal :open="detailOpen" :purchase="selected"
            :can-manage="selected?.can_manage ?? false" :routes="cajaCompraRoutes"
            @close="detailOpen = false" @edit="onEdit" @refresh="onDetailRefresh" />
```

- [ ] **Step 4: `Caja/Gastos/Index.vue` — filas clicables + detalle**

Importa:

```js
import GastoDetailModal from '@/Components/Gastos/GastoDetailModal.vue';
```

Estado:

```js
const detailOpen = ref(false);
const selected = ref(null);
const paymentMethods = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transferencia' },
];

const openDetail = (e) => { selected.value = e; detailOpen.value = true; };
const onEditGasto = () => {
    detailOpen.value = false;
    resetAi();
    editId.value = selected.value.id;
    formOpen.value = true;
};
const onDeleteGasto = () => {
    if (!selected.value) return;
    const reason = prompt('Motivo de cancelación (opcional):') ?? '';
    router.delete(route('caja.gastos.destroy', { tenant: props.tenant.slug, gasto: selected.value.id }), {
        data: { cancellation_reason: reason },
        preserveScroll: true,
        onSuccess: () => { detailOpen.value = false; },
    });
};
```

Añade `const editId = ref(null);` junto a los demás refs, y haz la fila clicable:

```html
                        <tr v-for="e in expenses.data" :key="e.id" @click="openDetail(e)"
                            class="cursor-pointer transition hover:bg-red-50/30">
```

El `GastoFormModal` debe soportar edición: cambia su uso para pasar `mode` y `expense` según `editId`:

```html
        <GastoFormModal
            :show="formOpen"
            :mode="editId ? 'edit' : 'create'"
            :tenant-slug="tenant.slug"
            :categories="categories"
            :allow-branch-select="false"
            :fixed-branch-id="branchId"
            :expense="editId ? selected : null"
            :ai-proposal="aiProposal"
            :ai-draft-id="aiDraftId"
            :ai-attachments="aiAttachments"
            :ai-transcription="aiTranscription"
            :submit-route-name="editId ? 'caja.gastos.update' : 'caja.gastos.store'"
            attachment-destroy-route-name="caja.gastos.store"
            @close="formOpen = false; editId = null; resetAi()"
            @success="formOpen = false; editId = null; resetAi()" />
```

Monta el detalle antes de `<FlashToast />`:

```html
        <GastoDetailModal
            :show="detailOpen"
            :expense="selected"
            :tenant-slug="tenant.slug"
            preview-route-name="caja.gastos.index"
            download-route-name="caja.gastos.index"
            :can-edit="selected?.can_manage ?? false"
            :can-delete="selected?.can_manage ?? false"
            :payment-methods="paymentMethods"
            @close="detailOpen = false"
            @edit="onEditGasto"
            @delete="onDeleteGasto" />
```

> Nota: `GastoDetailModal` requiere `previewRouteName`/`downloadRouteName`; Caja gastos no tiene rutas de adjuntos propias, pero el modal solo las invoca al abrir un adjunto. Si un gasto de caja tiene adjuntos, abrirlos quedará fuera de alcance (Phase futura); pasamos `caja.gastos.index` como placeholder válido de nombre de ruta para que el componente no falle al construir URLs perezosamente.

- [ ] **Step 5: Verificar que `GastoFormModal` soporta `mode="edit"` con `expense`**

Run: `grep -n "mode\|expense\|submitRouteName\|PUT\|put(" resources/js/Components/Gastos/GastoFormModal.vue | head -30`
Expected: confirma que el modal usa `mode`/`expense`/`submitRouteName` y hace `PUT` en edición (ya lo usa el panel de Sucursal/Empresa). Si el `submit` no envía el id de la ruta en edición, ajústalo para incluir `{ tenant, gasto: props.expense.id }`.

- [ ] **Step 6: Compilar y verificar manualmente**

Run: `vendor/bin/sail npm run build`
Expected: build sin errores.

Verificación manual (con turno abierto, como cajero):
1. Registra una compra sin pago → "DEBE $X". Click en la fila → abre detalle → "Registrar pago" → botón "Exacto" (Task 15) → queda "Pagada".
2. Edita la compra (cambia una línea) → el historial muestra "Editó".
3. Cancela la compra → desaparece de la lista (Task 14).
4. Igual con un gasto: editar y cancelar.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Compras/CompraDetailModal.vue resources/js/Components/Compras/CompraFormModal.vue resources/js/Pages/Caja/Compras/Index.vue resources/js/Pages/Caja/Gastos/Index.vue
git commit -m "feat(caja): filas clicables y modales de detalle para corregir compras/gastos"
```

---

# FASE 3 — Pulido (ocultar canceladas + botón "Exacto")

## Task 14: Ocultar compras canceladas

**Files:**
- Modify: `app/Http/Controllers/Concerns/HandlesPurchases.php` (`applyIndexFilters`)
- Modify: `resources/js/Pages/Empresa/Compras/Index.vue`, `resources/js/Pages/Sucursal/Compras/Index.vue`
- Test: `tests/Feature/Compras/PurchaseHiddenWhenCancelledTest.php`

(La de Caja ya se filtró en la Task 11 Step 5 con `where('status', '!=', Cancelled)`.)

- [ ] **Step 1: Escribir el test**

Crea `tests/Feature/Compras/PurchaseHiddenWhenCancelledTest.php`:

```php
<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseHiddenWhenCancelledTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cancelled_purchase_is_hidden_from_sucursal_index(): void
    {
        Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-CANCEL', 'purchased_at' => now(), 'status' => 'cancelled',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $this->adminSucursal->id, 'cancelled_at' => now(), 'cancelled_by' => $this->adminSucursal->id,
        ]);
        Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P2', 'type' => 'otro'])->id,
            'folio' => 'CMP-VIVA', 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $this->adminSucursal->id,
        ]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.compras.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->has('purchases', 1)
                ->where('purchases.0.folio', 'CMP-VIVA'));
    }
}
```

- [ ] **Step 2: Correr para verlo fallar**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHiddenWhenCancelledTest`
Expected: FAIL — devuelve 2 compras (incluye la cancelada).

- [ ] **Step 3: Excluir canceladas en `applyIndexFilters`**

En `HandlesPurchases::applyIndexFilters`, **elimina** el bloque del filtro de estado:

```php
        $status = $request->input('status', 'all');
        if ($status === 'received') {
            $query->where('status', PurchaseStatus::Received);
        } elseif ($status === 'cancelled') {
            $query->where('status', PurchaseStatus::Cancelled);
        }
```

y reemplázalo por una exclusión permanente:

```php
        // Las compras canceladas no se listan (siguen en BD para reportes).
        $query->where('status', '!=', PurchaseStatus::Cancelled);
```

- [ ] **Step 4: Quitar la opción "Canceladas" del filtro en las dos páginas**

En `resources/js/Pages/Empresa/Compras/Index.vue` y `resources/js/Pages/Sucursal/Compras/Index.vue`, elimina el bloque de botones de estado (el `v-for="s in ['all', 'received', 'cancelled']"`). Como ya no hay estado que filtrar, borra también el `ref` `statusFilter`, su uso en `navigate()` (`status: ...`) y en el `watch([...])`. (Busca `statusFilter` en cada archivo y quítalo por completo.)

- [ ] **Step 5: Correr el test + build**

Run: `vendor/bin/sail artisan test --compact --filter=PurchaseHiddenWhenCancelledTest`
Expected: PASS.

Run: `vendor/bin/sail npm run build`
Expected: build sin errores.

- [ ] **Step 6: Pint + Commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Concerns/HandlesPurchases.php resources/js/Pages/Empresa/Compras/Index.vue resources/js/Pages/Sucursal/Compras/Index.vue tests/Feature/Compras/PurchaseHiddenWhenCancelledTest.php
git commit -m "feat(compras): ocultar canceladas de todos los listados"
```

---

## Task 15: Botón "Exacto"

**Files:**
- Modify: `resources/js/Components/Compras/CompraFormModal.vue`
- Modify: `resources/js/Components/Compras/PagoProveedorModal.vue`

- [ ] **Step 1: Botón "Exacto" en `CompraFormModal`**

En el bloque "Pagado en efectivo (modo caja, solo al crear)", reemplaza el botón "Pagar total":

```html
                            <button type="button" @click="form.paid_amount = Number(total.toFixed(2))"
                                class="ml-2 text-xs font-semibold text-emerald-700 hover:underline">Pagar total ({{ fmt(total) }})</button>
```

por un botón "Exacto" con el estilo del POS, dentro de un contenedor relativo alrededor del input. Reemplaza el `<input ...>` + el botón anterior por:

```html
                            <div class="relative inline-block w-48">
                                <input v-model.number="form.paid_amount" type="number" step="0.01" min="0" :max="total"
                                    class="w-full rounded-lg border-gray-300 py-2 pl-3 pr-20 text-right text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                <button type="button" @click="form.paid_amount = Number(total.toFixed(2))"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-200 active:bg-emerald-300">
                                    Exacto
                                </button>
                            </div>
```

- [ ] **Step 2: Botón "Exacto" en `PagoProveedorModal`**

Reemplaza el bloque del input de monto (label + input + botón "Saldar total") por una versión con botón "Exacto" embebido:

```html
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Monto <span class="text-red-600">*</span></label>
                            <div class="relative">
                                <input v-model.number="form.amount" type="number" step="0.01" min="0.01"
                                    class="w-full rounded-xl border-gray-300 py-2 pl-3 pr-20 text-base font-semibold focus:border-orange-500 focus:ring-orange-500" />
                                <button v-if="mode === 'purchase' && purchase" type="button"
                                    @click="form.amount = purchase.amount_pending"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 active:bg-gray-300">
                                    Exacto
                                </button>
                            </div>
                            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                        </div>
```

- [ ] **Step 3: Compilar y verificar manualmente**

Run: `vendor/bin/sail npm run build`
Expected: build sin errores.

Verificación manual: en Caja, "Registrar compra" → el campo "Pagado en efectivo ahora" muestra el botón "Exacto"; al darle clic se rellena con el total. En el detalle de una compra → "Registrar pago" → el campo Monto muestra "Exacto" y rellena el pendiente.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Compras/CompraFormModal.vue resources/js/Components/Compras/PagoProveedorModal.vue
git commit -m "feat(caja): botón Exacto en campos de pago en efectivo"
```

---

## Cierre: suite completa

- [ ] **Step 1: Correr toda la suite**

Run: `vendor/bin/sail artisan test --compact`
Expected: PASS (toda la suite). Si algún test existente de Compras/Caja/Gastos rompe por el renombrado `update→updatePurchase`/`cancel→cancelPurchase`, ajusta esos tests para usar las rutas con nombre (que no cambian) — los tests llaman por `route(...)`, así que no deberían verse afectados.

- [ ] **Step 2: Pint final**

Run: `vendor/bin/sail bin pint --dirty --format agent`

---

## Self-Review (cobertura del spec)

- **A. Historial** → Tasks 1–8 (esquema, enum, modelo, trait, servicio con diff, wire en compras y gastos, serialización, UI).
- **B. Caja corrige** → Tasks 10–13 (rutas, controladores con candado, frontend).
- **C. Ocultar canceladas** → Task 14 (+ Caja en Task 11 Step 5).
- **D. Cancelar devuelve efectivo + recálculo de corte cerrado** → Task 9 (compras) y Task 6 (gastos admin).
- **E. Botón "Exacto"** → Task 15.
- **Regla de edición (total ≥ pagado)** → Task 5 Step 4.
- **Autorización por rol** → candado en Tasks 11–12; admin sin cambios de permisos.
- **Pruebas** (happy/failure/edge) → Tasks 4, 5, 6, 9, 11, 12, 14.
