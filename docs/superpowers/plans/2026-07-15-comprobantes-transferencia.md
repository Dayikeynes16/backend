# Comprobantes de pago en transferencias — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** adjuntar comprobantes (JPG/PNG/WebP/PDF) a pagos por transferencia — de ventas y de cobros globales de fiado — con toggles por sucursal (`enabled`/`required`), en la web.

**Architecture:** tabla nueva `payment_receipts` con dos FKs nullable (CHECK exactamente-uno), servicio `PaymentReceiptService` espejo de `ExpenseAttachmentService` (disco privado), validación de `required` SOLO en los controladores de formularios web (los servicios de dominio y el asistente IA no cambian), controlador nuevo `PaymentReceiptController` compartido por los prefijos sucursal/caja, UI en `PaymentForm.vue` (cobro), `CustomerFinancesTab.vue` (cobro global) y clips en las listas de pagos.

**Tech Stack:** Laravel 13 + Inertia v2 + Vue 3 (`<script setup>`), PHPUnit, Sail, disco privado `config('expenses.disk')`.

**Spec:** `docs/superpowers/specs/2026-07-15-comprobantes-transferencia-design.md` (aprobado 2026-07-15).

## Global Constraints

- TODO comando via Sail: `./vendor/bin/sail artisan test --compact <ruta>`, `./vendor/bin/sail bin pint --dirty --format agent` antes de cada commit con PHP tocado.
- Mimes permitidos: `image/jpeg, image/png, image/webp, application/pdf`; máx. **5 MB** por archivo; máx. **3 comprobantes por pago** (`MAX_PER_PAYMENT = 3` — NO copiar el 5 de gastos).
- Storage SIEMPRE en disco privado `PaymentReceiptService::disk()` (= `config('expenses.disk','local')`), `visibility => private`, path `tenants/{tenant}/payment_receipts/{p|cg}-{id}/{uuid}.{ext}`. Nunca URLs públicas.
- Mensajes exactos: 403 flag apagado `Tu empresa no ha habilitado esta función para tu sucursal.` · 422 required `Adjunta el comprobante de la transferencia.` · 422 método `Solo los pagos por transferencia llevan comprobante.` · 422 hijo CG `El comprobante va en el cobro global.` · 422 tope `Máximo 3 comprobantes por pago.`
- `payment_receipts_required` implica comportamiento de `enabled` (si required está ON se puede adjuntar aunque enabled esté OFF: en backend tratar `canAttach = enabled || required`).
- El confirm del asistente IA (`CustomerGlobalPaymentDraftConfirmer`) queda EXENTO del required — no tocar nada bajo `app/Services/Ai/`.
- Regla de turno del cajero (decisión fijada, spec nota del revisor): el cajero puede adjuntar/eliminar comprobantes de un pago si `payment.user_id === su id` Y tiene turno abierto Y `payment.created_at >= shift.opened_at`. El admin-sucursal (y admin-empresa/superadmin) puede sobre cualquier pago de su sucursal.
- Tests: colocar en `tests/Feature/Sucursal/PaymentReceiptTest.php` y `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php`, usando el trait `Tests\Concerns\SeedsMetricsData` (`seedTenant()` crea `$this->tenant/$this->branch/$this->adminSucursal/$this->cajero`, password `password`). `Storage::fake(PaymentReceiptService::disk())` en cada test con archivos.
- Commits frecuentes, mensajes `feat(pagos): …` terminando con `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Migraciones y modelos (`payment_receipts` + toggles de sucursal)

**Files:**
- Create: `database/migrations/2026_07_15_000001_create_payment_receipts_table.php`
- Create: `database/migrations/2026_07_15_000002_add_payment_receipt_toggles_to_branches.php`
- Create: `app/Models/PaymentReceipt.php`
- Modify: `app/Models/Payment.php` (relación `receipts()`)
- Modify: `app/Models/CustomerPayment.php` (relación `receipts()`)
- Modify: `app/Models/Branch.php` (fillable + casts de los 2 toggles)
- Test: `tests/Feature/Sucursal/PaymentReceiptTest.php` (arranque)

**Interfaces:**
- Produces: modelo `App\Models\PaymentReceipt` (BelongsToTenant; fillable `tenant_id, payment_id, customer_payment_id, uploaded_by, original_name, path, mime_type, size_bytes`), `Payment::receipts(): HasMany`, `CustomerPayment::receipts(): HasMany`, columnas `branches.payment_receipts_enabled` y `branches.payment_receipts_required` (bool, default false).

- [ ] **Step 1: Test que falla** — crear el archivo de test con un caso de modelo:

```php
<?php

namespace Tests\Feature\Sucursal;

use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->branch->forceFill([
            'payment_receipts_enabled' => true,
            'payment_receipts_required' => false,
        ])->save();
    }

    private function makeSaleWithTransferPayment(): array
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'S-'.uniqid(),
            'status' => 'active',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
        ]);
        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'transfer',
            'amount' => 100,
        ]);

        return [$sale, $payment];
    }

    public function test_receipt_model_links_to_payment(): void
    {
        [, $payment] = $this->makeSaleWithTransferPayment();

        $receipt = PaymentReceipt::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'uploaded_by' => $this->cajero->id,
            'original_name' => 'comprobante.jpg',
            'path' => 'tenants/x/payment_receipts/p-1/a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1234,
        ]);

        $this->assertSame(1, $payment->receipts()->count());
        $this->assertSame($receipt->id, $payment->receipts()->first()->id);
    }
}
```

Nota: si `Sale::create` exige más campos NOT NULL en este esquema (p. ej. `user_id`), copiar el helper de creación de venta de `tests/Feature/Api/Hub/SaleApiTest.php` (método `makeSaleWithItem`) en lugar del bloque anterior.

- [ ] **Step 2: Verificar que falla** — `./vendor/bin/sail artisan test --compact tests/Feature/Sucursal/PaymentReceiptTest.php` → FAIL (tabla/modelo inexistentes).

- [ ] **Step 3: Migración de la tabla**

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
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
            $table->index(['payment_id']);
            $table->index(['customer_payment_id']);
        });

        // Exactamente un padre (pago de venta O cobro global).
        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_one_parent CHECK ((payment_id IS NULL) != (customer_payment_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
```

- [ ] **Step 4: Migración de toggles** (mismo patrón que `2026_05_29_000001_add_branch_admin_catalog_toggles_to_branches.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('payment_receipts_enabled')->default(false)->after('branch_admin_expense_categories_enabled');
            $table->boolean('payment_receipts_required')->default(false)->after('payment_receipts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['payment_receipts_enabled', 'payment_receipts_required']);
        });
    }
};
```

- [ ] **Step 5: Modelo `PaymentReceipt`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'payment_id', 'customer_payment_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size_bytes'])]
class PaymentReceipt extends Model
{
    use BelongsToTenant;

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

Verificar el namespace real del trait: `grep -rn "trait BelongsToTenant" app/` (puede ser `App\Models\Concerns` o `App\Traits`) y usar el mismo import que usa `app/Models/Expense.php`.

- [ ] **Step 6: Relaciones y toggles en modelos existentes.** En `app/Models/Payment.php` y `app/Models/CustomerPayment.php` agregar:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }
```

En `app/Models/Branch.php`: añadir `'payment_receipts_enabled', 'payment_receipts_required'` al `#[Fillable]` y `'payment_receipts_enabled' => 'boolean', 'payment_receipts_required' => 'boolean'` a `casts()` (copiar cómo están declarados los `branch_admin_*`).

- [ ] **Step 7: Test en verde** — `./vendor/bin/sail artisan test --compact tests/Feature/Sucursal/PaymentReceiptTest.php` → PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_15_000001_create_payment_receipts_table.php database/migrations/2026_07_15_000002_add_payment_receipt_toggles_to_branches.php app/Models/PaymentReceipt.php app/Models/Payment.php app/Models/CustomerPayment.php app/Models/Branch.php tests/Feature/Sucursal/PaymentReceiptTest.php
git commit -m "feat(pagos): tabla payment_receipts + toggles de comprobantes por sucursal"
```

---

### Task 2: `PaymentReceiptService`

**Files:**
- Create: `app/Services/PaymentReceiptService.php`
- Test: `tests/Feature/Sucursal/PaymentReceiptTest.php` (ampliar)

**Interfaces:**
- Consumes: `PaymentReceipt`, `Payment`, `CustomerPayment` (Task 1).
- Produces: `PaymentReceiptService::ALLOWED_MIMES` (array), `MAX_BYTES = 5*1024*1024`, `MAX_PER_PAYMENT = 3`, `static disk(): string`, `attach(Payment|CustomerPayment $parent, iterable $files, ?int $uploadedBy): array<PaymentReceipt>`, `delete(PaymentReceipt $receipt): void`.

- [ ] **Step 1: Tests que fallan** — añadir a `PaymentReceiptTest`:

```php
use App\Services\PaymentReceiptService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

    public function test_service_attaches_file_to_private_disk(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [, $payment] = $this->makeSaleWithTransferPayment();

        $created = app(PaymentReceiptService::class)->attach(
            $payment,
            [UploadedFile::fake()->image('captura.jpg', 400, 400)],
            $this->cajero->id,
        );

        $this->assertCount(1, $created);
        $receipt = $created[0];
        $this->assertSame($payment->id, $receipt->payment_id);
        $this->assertNull($receipt->customer_payment_id);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/payment_receipts/p-{$payment->id}/", $receipt->path);
        Storage::disk(PaymentReceiptService::disk())->assertExists($receipt->path);
    }

    public function test_service_attaches_to_customer_payment_and_delete_removes_file(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $cg = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => \App\Models\Customer::create([
                'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
                'name' => 'Cliente F', 'status' => 'active',
            ])->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-TEST1',
            'method' => 'transfer',
            'amount_applied' => 200,
        ]);

        $svc = app(PaymentReceiptService::class);
        $created = $svc->attach($cg, [UploadedFile::fake()->create('comp.pdf', 100, 'application/pdf')], $this->cajero->id);

        $this->assertSame($cg->id, $created[0]->customer_payment_id);

        $path = $created[0]->path;
        $svc->delete($created[0]);
        Storage::disk(PaymentReceiptService::disk())->assertMissing($path);
        $this->assertSame(0, PaymentReceipt::count());
    }
```

Nota: si `CustomerPayment::create` exige otras columnas NOT NULL, copiar el helper de creación de cobro global de `tests/Feature/Api/Hub/CustomerPaymentApiTest.php`.

- [ ] **Step 2: Verificar FAIL** — clase inexistente.

- [ ] **Step 3: Implementación**

```php
<?php

namespace App\Services;

use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprobantes de pago por transferencia (venta o cobro global de fiado).
 * Espejo de ExpenseAttachmentService: disco privado, visibility=private.
 * No decide permisos ni flags — eso vive en los controladores.
 */
class PaymentReceiptService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public const MAX_PER_PAYMENT = 3;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Comparte el disco privado de gastos (EXPENSES_DISK en prod). */
    public static function disk(): string
    {
        return config('expenses.disk', 'local');
    }

    /**
     * @param  Payment|CustomerPayment  $parent
     * @param  iterable<UploadedFile>  $files
     * @return array<int, PaymentReceipt>
     */
    public function attach(Payment|CustomerPayment $parent, iterable $files, ?int $uploadedBy): array
    {
        $isSalePayment = $parent instanceof Payment;
        $tenantId = $isSalePayment ? $parent->sale?->tenant_id : $parent->tenant_id;
        $prefix = $isSalePayment ? "p-{$parent->id}" : "cg-{$parent->id}";
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $filename = Str::uuid()->toString().'.'.$ext;
            $directory = "tenants/{$tenantId}/payment_receipts/{$prefix}";

            $stored = $file->storeAs($directory, $filename, [
                'disk' => self::disk(),
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }

            $created[] = PaymentReceipt::create([
                'tenant_id' => $tenantId,
                'payment_id' => $isSalePayment ? $parent->id : null,
                'customer_payment_id' => $isSalePayment ? null : $parent->id,
                'uploaded_by' => $uploadedBy,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $stored,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
            ]);
        }

        return $created;
    }

    public function delete(PaymentReceipt $receipt): void
    {
        Storage::disk(self::disk())->delete($receipt->path);
        $receipt->delete();
    }
}
```

OJO `tenant_id` del Payment: `payments` no tiene `tenant_id` propio — se obtiene vía `$parent->sale->tenant_id` (cargar `loadMissing('sale')` si hace falta). Verificar con `grep -n "tenant" database/migrations/2026_03_28_000002_create_payments_table.php`; si la columna sí existe, usarla directo.

- [ ] **Step 4: PASS** — correr el archivo de test completo.

- [ ] **Step 5: Commit** — `feat(pagos): PaymentReceiptService (adjuntos en disco privado)`.

---

### Task 3: Cobro de venta acepta `receipts[]` + regla `required`

**Files:**
- Modify: `app/Http/Controllers/Sucursal/PaymentController.php:51-72` (método `store` — compartido por las rutas sucursal Y caja; NO existe `Caja\PaymentController`)
- Test: `tests/Feature/Sucursal/PaymentReceiptTest.php` (ampliar)

**Interfaces:**
- Consumes: `PaymentReceiptService::attach/ALLOWED_MIMES/MAX_BYTES/MAX_PER_PAYMENT` (Task 2), toggles de Task 1.
- Produces: `POST` de pago acepta `receipts[]` multipart; 422 `Adjunta el comprobante de la transferencia.` cuando `required` + transfer + sin archivos.

- [ ] **Step 1: Tests que fallan**

```php
    private function payUrl(Sale $sale): string
    {
        // Ruta de sucursal; la de caja reusa el mismo controlador.
        return route('sucursal.workbench.payment', [$this->tenant->slug, $sale->id]);
    }
```

Verificar el name real con `./vendor/bin/sail artisan route:list --path=pago --except-vendor` y `grep -n "payment" routes/web.php | grep -i "store\|post"`; usar el name exacto que apunte a `Sucursal\PaymentController@store` en el prefijo sucursal.

```php
    public function test_paying_by_transfer_with_receipt_stores_file(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [$sale] = $this->makeSaleWithTransferPayment(); // usar una venta SIN pago: crear helper makeActiveSale() que solo cree la venta
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), [
                'method' => 'transfer',
                'amount' => 100,
                'receipts' => [UploadedFile::fake()->image('captura.jpg')],
            ])->assertSessionHas('success');

        $payment = $sale->payments()->first();
        $this->assertSame(1, $payment->receipts()->count());
    }

    public function test_required_blocks_transfer_without_receipt(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), ['method' => 'transfer', 'amount' => 100])
            ->assertSessionHasErrors(['receipts' => 'Adjunta el comprobante de la transferencia.']);

        $this->assertSame(0, $sale->payments()->count());
    }

    public function test_required_does_not_affect_cash(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $sale = $this->makeActiveSale();
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)
            ->post($this->payUrl($sale), ['method' => 'cash', 'amount' => 100])
            ->assertSessionHas('success');
    }
```

Helpers a añadir al test: `makeActiveSale()` (la venta sin pagos del Step 1 de Task 1) y `openShiftFor(User $u)` (`CashRegisterShift::create([... 'user_id' => $u->id, 'branch_id' => $this->branch->id, 'tenant_id' => $this->tenant->id, 'opened_at' => now(), 'opening_amount' => 0])` — copiar campos exactos de cómo lo hace `tests/Feature/Api/Hub/ShiftApiTest.php`).

- [ ] **Step 2: FAIL** (el store ignora `receipts` y no valida required).

- [ ] **Step 3: Implementación.** En `Sucursal\PaymentController@store`, tras obtener `$branch` (línea 47) y ANTES del `$request->validate` existente, ampliar la validación:

```php
use App\Services\PaymentReceiptService;

        $canAttach = (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required);

        $rules = [
            'method' => "required|in:{$allowedStr}",
            'amount' => 'required|numeric|gt:0',
        ];
        if ($canAttach) {
            $rules['receipts'] = 'nullable|array|max:'.PaymentReceiptService::MAX_PER_PAYMENT;
            $rules['receipts.*'] = [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(PaymentReceiptService::MAX_BYTES / 1024),
            ];
        }

        $validated = $request->validate($rules, [
            'method.in' => 'El metodo de pago seleccionado no esta habilitado para esta sucursal.',
            'receipts.max' => 'Máximo 3 comprobantes por pago.',
            'receipts.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'receipts.*.max' => 'Cada archivo no puede superar 5 MB.',
        ]);

        // Solo transferencias llevan comprobante; required lo exige.
        $receiptFiles = $canAttach && $validated['method'] === 'transfer'
            ? ($request->file('receipts') ?? [])
            : [];
        if ($branch->payment_receipts_required && $validated['method'] === 'transfer' && $receiptFiles === []) {
            return back()->withErrors(['receipts' => 'Adjunta el comprobante de la transferencia.']);
        }
```

Y dentro del `DB::transaction` existente, después de `Payment::create` (guardar la instancia en `$payment = Payment::create(...)`):

```php
            if ($receiptFiles !== []) {
                app(PaymentReceiptService::class)->attach($payment, $receiptFiles, $user->id);
            }
```

- [ ] **Step 4: PASS** — archivo completo verde.

- [ ] **Step 5: Regresión** — `./vendor/bin/sail artisan test --compact --filter=Payment` (los tests existentes de pagos siguen verdes).

- [ ] **Step 6: Commit** — `feat(pagos): cobro de venta acepta comprobantes y exige en transferencia (flag)`.

---

### Task 4: Cobro global de fiado acepta `receipts[]` + `required`

**Files:**
- Modify: `app/Http/Requests/RegisterCustomerPaymentRequest.php` (reglas de archivos)
- Modify: `app/Http/Controllers/Sucursal/CustomerPaymentController.php` (método `store`, ~línea 34; attach tras crear el CG)
- Test: `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php` (nuevo)

**Interfaces:**
- Consumes: Task 2. `CustomerGlobalPaymentService` NO se toca — el attach ocurre en el controlador con el `CustomerPayment` devuelto.
- Produces: `POST` de cobro global (JSON) acepta `receipts[]`; 422 `{errors: {receipts: [...]}}` con required.

- [ ] **Step 1: Tests que fallan** — nuevo archivo (mismo setUp que Task 1; helper de cliente con deuda copiado de `tests/Feature/Api/Hub/CustomerPaymentApiTest.php` — venta completada a crédito + `amount_pending > 0`):

```php
    public function test_global_collection_by_transfer_stores_receipt_on_parent(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        $customer = $this->makeCustomerWithDebt(200);
        $this->openShiftFor($this->cajero);

        $res = $this->actingAs($this->cajero)->post(
            route('sucursal.clientes.pagos.store', [$this->tenant->slug, $customer->id]),
            ['amount' => 200, 'method' => 'transfer', 'receipts' => [UploadedFile::fake()->image('cap.jpg')]],
        )->assertOk();

        $cg = CustomerPayment::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(1, $cg->receipts()->count());
        // Los pagos hijos NO llevan comprobante propio.
        $this->assertSame(0, PaymentReceipt::whereNotNull('payment_id')->count());
    }

    public function test_required_blocks_global_transfer_without_receipt(): void
    {
        $this->branch->forceFill(['payment_receipts_required' => true])->save();
        $customer = $this->makeCustomerWithDebt(200);
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)->postJson(
            route('sucursal.clientes.pagos.store', [$this->tenant->slug, $customer->id]),
            ['amount' => 200, 'method' => 'transfer'],
        )->assertStatus(422)->assertJsonValidationErrors('receipts');
    }
```

Verificar el route name real con `grep -n "pagos" routes/web.php | grep -i customer` (y si el flujo del cajero usa otra ruta con el mismo controlador, cubrirla con un tercer test análogo).

- [ ] **Step 2: FAIL.**

- [ ] **Step 3: Implementación.** En `RegisterCustomerPaymentRequest::rules()` añadir (siempre presentes; el "solo con flag" se decide en el controlador porque el Request no conoce el branch):

```php
            'receipts' => 'nullable|array|max:'.\App\Services\PaymentReceiptService::MAX_PER_PAYMENT,
            'receipts.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', \App\Services\PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(\App\Services\PaymentReceiptService::MAX_BYTES / 1024),
            ],
```

En `CustomerPaymentController@store`, después de `$validated = $request->validated()` y antes de llamar al servicio:

```php
        $branch = \App\Models\Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $canAttach = (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required);
        $receiptFiles = $canAttach && ($validated['method'] ?? null) === 'transfer'
            ? ($request->file('receipts') ?? [])
            : [];
        if ($branch->payment_receipts_required && ($validated['method'] ?? null) === 'transfer' && $receiptFiles === []) {
            return response()->json([
                'message' => 'Adjunta el comprobante de la transferencia.',
                'errors' => ['receipts' => ['Adjunta el comprobante de la transferencia.']],
            ], 422);
        }
```

Y tras obtener el `CustomerPayment` creado por `CustomerGlobalPaymentService` (leer el método para ver la variable exacta que devuelve — el servicio retorna el CG o un array con él):

```php
        if ($receiptFiles !== []) {
            app(\App\Services\PaymentReceiptService::class)->attach($customerPayment, $receiptFiles, $user->id);
        }
```

Si la creación del CG corre dentro de una transacción del servicio, adjuntar inmediatamente después de que retorne (el archivo no necesita atomicidad con el CG; si el attach falla, el cobro queda válido sin comprobante — aceptable y preferible a envolver el servicio).

- [ ] **Step 4: PASS** + regresión `--filter=CustomerPayment`.

- [ ] **Step 5: Commit** — `feat(pagos): cobro global de fiado acepta comprobantes (padre CG)`.

---

### Task 5: Endpoints de comprobantes de pago de venta (adjuntar después / descargar / eliminar)

**Files:**
- Create: `app/Http/Controllers/Sucursal/PaymentReceiptController.php`
- Modify: `routes/web.php` (grupo sucursal donde viven las rutas `workbench.payment.*`, y grupo caja donde vive `payment.store` — mismas rutas nuevas en ambos prefijos apuntando al MISMO controlador)
- Test: `tests/Feature/Sucursal/PaymentReceiptTest.php` (ampliar)

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces: rutas `POST/GET/DELETE pagos/{payment}/comprobantes[/{receipt}]` (names `*.receipts.store/download/destroy`) en prefijos sucursal y caja. Reglas: flag 403, solo transfer 422, hijo CG 422, turno del cajero.

- [ ] **Step 1: Tests que fallan**

```php
    public function test_attach_later_and_download_and_destroy(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [, $payment] = $this->makeSaleWithTransferPayment(); // pago del cajero
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)->post(
            route('sucursal.pagos.receipts.store', [$this->tenant->slug, $payment->id]),
            ['receipts' => [UploadedFile::fake()->image('tarde.jpg')]],
        )->assertSessionHas('success');

        $receipt = $payment->receipts()->firstOrFail();

        $this->actingAs($this->cajero)->get(
            route('sucursal.pagos.receipts.download', [$this->tenant->slug, $payment->id, $receipt->id]),
        )->assertOk()->assertDownload('tarde.jpg');

        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.pagos.receipts.destroy', [$this->tenant->slug, $payment->id, $receipt->id]),
        )->assertSessionHas('success');
        $this->assertSame(0, $payment->receipts()->count());
    }

    public function test_flag_off_returns_403(): void
    {
        $this->branch->forceFill(['payment_receipts_enabled' => false, 'payment_receipts_required' => false])->save();
        [, $payment] = $this->makeSaleWithTransferPayment();

        $this->actingAs($this->adminSucursal)->post(
            route('sucursal.pagos.receipts.store', [$this->tenant->slug, $payment->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403);
    }

    public function test_cash_payment_rejects_receipt(): void
    {
        $sale = $this->makeActiveSale();
        $cash = Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 50]);
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero)->post(
            route('sucursal.pagos.receipts.store', [$this->tenant->slug, $cash->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertSessionHasErrors(['receipts' => 'Solo los pagos por transferencia llevan comprobante.']);
    }

    public function test_cajero_cannot_mutate_payment_outside_his_open_shift(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [, $payment] = $this->makeSaleWithTransferPayment();
        // Sin turno abierto → 403.
        $this->actingAs($this->cajero)->post(
            route('sucursal.pagos.receipts.store', [$this->tenant->slug, $payment->id]),
            ['receipts' => [UploadedFile::fake()->image('x.jpg')]],
        )->assertStatus(403);
    }

    public function test_deleting_payment_cascades_receipts(): void
    {
        Storage::fake(PaymentReceiptService::disk());
        [$sale, $payment] = $this->makeSaleWithTransferPayment();
        $receipt = app(PaymentReceiptService::class)->attach($payment, [UploadedFile::fake()->image('c.jpg')], $this->cajero->id)[0];
        $path = $receipt->path;

        $this->actingAs($this->adminSucursal)->delete(
            route('sucursal.workbench.payment.destroy', [$this->tenant->slug, $sale->id, $payment->id]),
        );

        $this->assertSame(0, PaymentReceipt::count());
        Storage::disk(PaymentReceiptService::disk())->assertMissing($path);
    }
```

Verificar los route names de destroy de pago con `grep -n "payment" routes/web.php` y usar los reales.

- [ ] **Step 2: FAIL.**

- [ ] **Step 3: Controlador**

```php
<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprobantes de pagos de venta. Compartido por los prefijos sucursal y
 * caja (mismo patrón que PaymentController). Los de cobro global viven en
 * CustomerPaymentReceiptController.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptService $receipts) {}

    public function store(Request $request, Payment $payment): RedirectResponse
    {
        $user = Auth::user();
        $branch = $this->authorizeMutation($user, $payment);

        if ($payment->method !== 'transfer') {
            return back()->withErrors(['receipts' => 'Solo los pagos por transferencia llevan comprobante.']);
        }
        if ($payment->customer_payment_id !== null) {
            return back()->withErrors(['receipts' => 'El comprobante va en el cobro global.']);
        }

        $request->validate([
            'receipts' => 'required|array|max:'.PaymentReceiptService::MAX_PER_PAYMENT,
            'receipts.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PaymentReceiptService::ALLOWED_MIMES),
                'max:'.(PaymentReceiptService::MAX_BYTES / 1024),
            ],
        ], [
            'receipts.max' => 'Máximo 3 comprobantes por pago.',
            'receipts.*.mimes' => 'Solo se permiten imágenes (jpg, png, webp) o PDF.',
            'receipts.*.max' => 'Cada archivo no puede superar 5 MB.',
        ]);

        $existing = $payment->receipts()->count();
        $incoming = count($request->file('receipts'));
        if ($existing + $incoming > PaymentReceiptService::MAX_PER_PAYMENT) {
            return back()->withErrors(['receipts' => 'Máximo 3 comprobantes por pago.']);
        }

        $this->receipts->attach($payment, $request->file('receipts'), $user->id);

        return back()->with('success', 'Comprobante adjuntado.');
    }

    public function download(Payment $payment, PaymentReceipt $receipt): StreamedResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        return Storage::disk(PaymentReceiptService::disk())->download($receipt->path, $receipt->original_name);
    }

    public function destroy(Payment $payment, PaymentReceipt $receipt): RedirectResponse
    {
        $user = Auth::user();
        $this->authorizeMutation($user, $payment);
        abort_unless($receipt->payment_id === $payment->id, 404);

        $this->receipts->delete($receipt);

        return back()->with('success', 'Comprobante eliminado.');
    }

    /** Flag encendido + pago de la sucursal del usuario. */
    private function authorizeView($user, Payment $payment): Branch
    {
        $payment->loadMissing('sale');
        abort_unless($payment->sale && $payment->sale->branch_id === $user->branch_id, 404);

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        abort_unless(
            $branch->payment_receipts_enabled || $branch->payment_receipts_required,
            403,
            'Tu empresa no ha habilitado esta función para tu sucursal.'
        );

        return $branch;
    }

    /**
     * Mutación: admin (sucursal/empresa/superadmin) cualquiera de su sucursal;
     * cajero solo pagos SUYOS dentro de su turno abierto (payments no tiene
     * shift_id: se deriva por user_id + created_at >= opened_at — decisión
     * fijada en el spec).
     */
    private function authorizeMutation($user, Payment $payment): Branch
    {
        $branch = $this->authorizeView($user, $payment);

        if ($user->hasRole('admin-sucursal') || $user->hasRole('admin-empresa') || $user->hasRole('superadmin')) {
            return $branch;
        }

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        abort_unless(
            $shift && $payment->user_id === $user->id && $payment->created_at >= $shift->opened_at,
            403,
            'Solo puedes modificar comprobantes de tus pagos del turno abierto.'
        );

        return $branch;
    }
}
```

- [ ] **Step 4: Rutas.** En `routes/web.php`, dentro del grupo sucursal (junto a las rutas `workbench.payment.*`) añadir:

```php
        Route::post('pagos/{payment}/comprobantes', [\App\Http\Controllers\Sucursal\PaymentReceiptController::class, 'store'])->whereNumber('payment')->name('pagos.receipts.store');
        Route::get('pagos/{payment}/comprobantes/{receipt}', [\App\Http\Controllers\Sucursal\PaymentReceiptController::class, 'download'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.download');
        Route::delete('pagos/{payment}/comprobantes/{receipt}', [\App\Http\Controllers\Sucursal\PaymentReceiptController::class, 'destroy'])->whereNumber('payment')->whereNumber('receipt')->name('pagos.receipts.destroy');
```

Y las mismas tres líneas en el grupo caja (names con el prefijo del grupo, p. ej. `caja.pagos.receipts.*`). Seguir el estilo de imports del archivo (ver cómo importa los demás controladores arriba en vez de FQCN inline si esa es la convención).

- [ ] **Step 5: Cascade al eliminar pago.** En `Sucursal\PaymentController@destroy`, dentro del `DB::transaction` antes de `$payment->delete()`:

```php
            foreach ($payment->receipts()->get() as $receipt) {
                app(\App\Services\PaymentReceiptService::class)->delete($receipt);
            }
```

- [ ] **Step 6: PASS** archivo completo + regresión `--filter=Payment`.

- [ ] **Step 7: Commit** — `feat(pagos): endpoints de comprobantes (adjuntar tarde, descargar, eliminar) + cascade`.

---

### Task 6: Endpoints de comprobantes de cobro global

**Files:**
- Create: `app/Http/Controllers/Sucursal/CustomerPaymentReceiptController.php`
- Modify: `routes/web.php` (grupo sucursal junto a `clientes.pagos.*`; grupo caja solo adjuntar-tarde/descargar)
- Test: `tests/Feature/Sucursal/CustomerPaymentReceiptTest.php` (ampliar)

**Interfaces:**
- Consumes: Tasks 1-2. Estructura del controlador calcada de Task 5 cambiando `Payment` → `CustomerPayment` (que sí tiene `branch_id` y `user_id` directos, y `method` propio).
- Produces: rutas `cobros/{customerPayment}/comprobantes[/{receipt}]`, names `cobros.receipts.store/download/destroy`.

- [ ] **Step 1: Tests que fallan** — espejo de Task 5 sobre un CG por transferencia: adjuntar tarde (admin y cajero dueño con turno), 403 flag off, 422 método cash, descarga, destroy, y **cancelar el CG conserva o borra según el flujo de cancelación existente**: leer `CustomerPaymentController@cancel`/servicio — si el cancel es soft (marca `cancelled_*`), los comprobantes SE CONSERVAN (evidencia); solo el borrado físico (si existiera) los borra. Escribir el test conforme a lo que el código haga hoy (conservar en cancel).

- [ ] **Step 2: FAIL.**

- [ ] **Step 3: Controlador** — copiar `PaymentReceiptController` con estos cambios: tipo `CustomerPayment $customerPayment`; `authorizeView` usa `$customerPayment->branch_id === $user->branch_id` (sin loadMissing de sale); la regla de turno del cajero usa `$customerPayment->user_id` y `$customerPayment->created_at`; el chequeo de "hijo CG" no aplica (esto ES el padre); `method !== 'transfer'` → mismo 422.

- [ ] **Step 4: Rutas** — grupo sucursal: las tres; grupo caja: solo `store` (adjuntar tarde) y `download` (el cajero no elimina comprobantes de CG cancelables por admin; mantener simetría con lo que el cajero puede hacer hoy sobre CGs: verificar con `grep -n "cobros\|customer-payments\|clientes/{" routes/web.php` qué rutas de CG tiene caja y alinear).

- [ ] **Step 5: PASS + regresión + Commit** — `feat(pagos): comprobantes en cobros globales de fiado`.

---

### Task 7: Toggles en Empresa → Editar Sucursal

**Files:**
- Modify: `app/Http/Controllers/Empresa/SucursalController.php` (método `update` — validación de los 2 campos; verificar nombre real del controlador con `grep -n "Sucursales/Edit" app/Http/Controllers/Empresa/*.php`)
- Modify: `resources/js/Pages/Empresa/Sucursales/Edit.vue` (dos toggles junto a `branch_admin_providers_enabled`, líneas ~41 y ~351)
- Test: `tests/Feature/Empresa/` — ampliar el test existente de edición de sucursal (localizar con `grep -rln "branch_admin_providers_enabled" tests/Feature/Empresa/`)

**Interfaces:**
- Consumes: columnas de Task 1.
- Produces: admin-empresa puede activar `payment_receipts_enabled`/`payment_receipts_required` por sucursal.

- [ ] **Step 1: Test que falla** — en el test de update de sucursal existente, añadir caso: update con `payment_receipts_enabled => true, payment_receipts_required => true` persiste ambos.

- [ ] **Step 2: FAIL.** — el update no incluye los campos en `validate()`.

- [ ] **Step 3: Backend** — añadir a las reglas del update (copiar cómo valida `branch_admin_providers_enabled`, típicamente `'boolean'`):

```php
            'payment_receipts_enabled' => 'boolean',
            'payment_receipts_required' => 'boolean',
```

y al array del `$branch->update([...])` los dos campos.

- [ ] **Step 4: Frontend** — en `Edit.vue`: agregar al `useForm` (línea ~41):

```js
    payment_receipts_enabled: !!props.sucursal.payment_receipts_enabled,
    payment_receipts_required: !!props.sucursal.payment_receipts_required,
```

y en el template, junto al toggle de proveedores (~línea 351), dos bloques copiados del patrón de toggle existente con estos textos:

- Título: `Comprobantes de transferencia` / descripción: `Permite adjuntar el comprobante (imagen o PDF) a los cobros por transferencia.`
- Título: `Exigir comprobante` / descripción: `No se podrá cobrar por transferencia sin adjuntar el comprobante.` — este segundo toggle se muestra deshabilitado (`:disabled`) si el primero está apagado, y al apagar el primero se apaga también (`watch` simple o `@change`).

- [ ] **Step 5: PASS + build** — test verde y `./vendor/bin/sail npm run build` verde.

- [ ] **Step 6: Commit** — `feat(pagos): toggles de comprobantes en editar sucursal`.

---

### Task 8: UI de cobro (venta y fiado)

**Files:**
- Modify: `resources/js/Components/PaymentForm.vue` (formulario de cobro de venta)
- Modify: `resources/js/Components/Clientes/CustomerFinancesTab.vue` (formulario de cobro global)
- Modify: los controladores que sirven las páginas para exponer los flags: `Sucursal\WorkbenchController@index`, `Caja\WorkbenchController@index`, `Sucursal\SaleHistoryController@index`, `Caja\HistorialController@index` (añadir a `branchInfo`: `'payment_receipts_enabled' => (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required), 'payment_receipts_required' => (bool) $branch->payment_receipts_required`), y el controlador de `Sucursal/Clientes/Show` (localizar con `grep -n "Clientes/Show" app/Http/Controllers/Sucursal/*.php`).

**Interfaces:**
- Consumes: stores de Tasks 3-4 (campo `receipts[]` multipart), flags en `branchInfo`.
- Produces: al elegir Transferencia con flag activo aparece "Adjuntar comprobante"; con required el botón de cobrar se deshabilita sin archivo.

- [ ] **Step 1: PaymentForm.vue.** Leer el componente para ubicar: el `useForm` del pago, el selector de método y el botón submit. Agregar:

```js
// props: el padre ya pasa branchInfo o los flags — si no, añadir prop:
const props = defineProps({ /* existentes */, receiptsEnabled: { type: Boolean, default: false }, receiptsRequired: { type: Boolean, default: false } });

const receiptFiles = ref([]);
const onReceiptChange = (e) => { receiptFiles.value = Array.from(e.target.files ?? []).slice(0, 3); };
const needsReceipt = computed(() => props.receiptsRequired && form.method === 'transfer' && receiptFiles.value.length === 0);
```

En el submit existente, incluir los archivos (Inertia manda multipart automáticamente cuando hay Files):

```js
form.transform((data) => ({ ...data, receipts: receiptFiles.value }))
    .post(/* la ruta existente */, { forceFormData: true, /* opciones existentes */ });
```

Template — bloque visible solo con `receiptsEnabled && form.method === 'transfer'` (colocar entre el selector de método y el botón):

```html
<div v-if="(receiptsEnabled || receiptsRequired) && form.method === 'transfer'" class="mt-3">
    <label class="mb-1 block text-xs font-semibold text-gray-600">
        Comprobante de la transferencia <span v-if="receiptsRequired" class="text-red-600">*</span>
    </label>
    <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple
           class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200"
           @change="onReceiptChange" />
    <p v-if="receiptFiles.length" class="mt-1 text-xs text-gray-500">{{ receiptFiles.map(f => f.name).join(', ') }}</p>
    <p v-else-if="receiptsRequired" class="mt-1 text-xs text-amber-600">Adjunta el comprobante para poder cobrar.</p>
    <InputError :message="form.errors.receipts" class="mt-1" />
</div>
```

Y en el botón de cobrar existente, sumar `needsReceipt` a su condición de disabled: `:disabled="form.processing || needsReceipt /* + condiciones existentes */"`.

Los padres de `PaymentForm` (SaleDetail de Sucursal y Caja) deben pasarle los flags desde `branchInfo`: `:receipts-enabled="branchInfo?.payment_receipts_enabled"` `:receipts-required="branchInfo?.payment_receipts_required"`.

- [ ] **Step 2: CustomerFinancesTab.vue.** Mismo bloque adaptado a su form de cobro global (usa axios/JSON hoy — cambiar ese submit a `FormData` cuando haya archivos: construir `const fd = new FormData(); fd.append('amount', ...); fd.append('method', ...); receiptFiles.value.forEach(f => fd.append('receipts[]', f));` y postear con el header multipart, conservando el manejo de respuesta actual). El flag llega por prop desde `Show.vue` (exponerlo en el controlador de Show).

- [ ] **Step 3: Build + prueba manual** — `./vendor/bin/sail npm run build`; verificar en el navegador: cobrar por transferencia con flag on/off/required (usuario demo `sucursal@eltoro.test` / `password`, activando los toggles con `admin@eltoro.test`).

- [ ] **Step 4: Commit** — `feat(pagos): UI de comprobante en cobro de venta y cobro global`.

---

### Task 9: Clips de comprobantes en listas + panel ver/gestionar

**Files:**
- Create: `resources/js/Components/PaymentReceiptsPanel.vue` (modal/panel reusable: lista de comprobantes con ver/descargar/eliminar/agregar)
- Modify: `resources/js/Components/Sucursal/SaleDetail.vue` y `resources/js/Components/Caja/SaleDetail.vue` (clip 📎 con contador junto a cada pago por transferencia)
- Modify: `resources/js/Pages/Sucursal/Pagos/Index.vue` y `resources/js/Pages/Caja/Pagos/Index.vue` (clip en filas de transferencia)
- Modify: `resources/js/Components/Clientes/CustomerFinancesTab.vue` (clip en el ledger de CGs)
- Modify: los controladores/serializaciones que alimentan esas listas para incluir `receipts` (`->with('receipts:id,payment_id,customer_payment_id,original_name,mime_type,size_bytes')` en los queries de payments/CGs de: `Sucursal\PagosController`, `Caja\PagosController`, Workbench (ambos), SaleHistory (ambos), y el ledger de clientes).

**Interfaces:**
- Consumes: endpoints de Tasks 5-6 (adjuntar tarde/descargar/eliminar), `receipts` serializados.
- Produces: componente `PaymentReceiptsPanel` con props `{ receipts: Array, parentType: 'payment'|'customer-payment', parentId: Number, canManage: Boolean, tenantSlug: String, routePrefix: 'sucursal'|'caja' }`, emits `changed` (el padre recarga).

- [ ] **Step 1: Componente `PaymentReceiptsPanel.vue`** — estructura (siguiendo `AttachmentsSection` de gastos como referencia visual):

```html
<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    parentType: { type: String, required: true }, // 'payment' | 'customer-payment'
    parentId: { type: Number, required: true },
    canManage: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    routePrefix: { type: String, default: 'sucursal' },
});
const emit = defineEmits(['changed', 'close']);

const base = () => {
    const seg = props.parentType === 'payment' ? 'pagos' : 'cobros';
    return route(`${props.routePrefix}.${seg}.receipts.store`, [props.tenantSlug, props.parentId]).replace(/\/comprobantes$/, '/comprobantes');
};
// Usar route() con names: store/download/destroy según parentType.
const downloadUrl = (r) => route(`${props.routePrefix}.${props.parentType === 'payment' ? 'pagos' : 'cobros'}.receipts.download`, [props.tenantSlug, props.parentId, r.id]);

const uploading = ref(false);
const error = ref('');
async function upload(e) {
    const files = Array.from(e.target.files ?? []);
    if (!files.length) return;
    const fd = new FormData();
    files.forEach((f) => fd.append('receipts[]', f));
    uploading.value = true; error.value = '';
    try {
        await axios.post(route(`${props.routePrefix}.${props.parentType === 'payment' ? 'pagos' : 'cobros'}.receipts.store`, [props.tenantSlug, props.parentId]), fd);
        emit('changed');
    } catch (err) {
        error.value = err.response?.data?.errors?.receipts?.[0] ?? err.response?.data?.message ?? 'No se pudo subir.';
    } finally { uploading.value = false; }
}
async function destroy(r) {
    if (!window.confirm(`¿Eliminar "${r.original_name}"?`)) return;
    await axios.delete(route(`${props.routePrefix}.${props.parentType === 'payment' ? 'pagos' : 'cobros'}.receipts.destroy`, [props.tenantSlug, props.parentId, r.id]));
    emit('changed');
}
</script>
```

(OJO: los endpoints de Task 5/6 responden redirect Inertia con flash; para usarlos vía axios devolver JSON cuando `expectsJson()` — ajustar los tres métodos del controlador: `return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', ...)` — e incluir ese caso en los tests de Task 5. Alternativa más simple: usar `router.post` de Inertia con `preserveScroll` y `forceFormData` en vez de axios; elegir UNA de las dos y ser consistente. Recomendada: Inertia `router` + flash, como el resto de la app.)

Template: lista de `receipts` (nombre + tamaño legible + botón descargar `<a :href="downloadUrl(r)">` + botón eliminar si `canManage`), input de archivo para agregar si `canManage && receipts.length < 3`, contador `{{ receipts.length }}/3`.

- [ ] **Step 2: Clips en las listas.** En cada lista de pagos (SaleDetail ×2, Pagos Index ×2, ledger de fiado): junto a los pagos con `method === 'transfer'` y flag activo, mostrar botón clip `📎 {{ p.receipts?.length ?? 0 }}` que abre el `PaymentReceiptsPanel` (en un `Modal` existente del proyecto). `canManage` = regla del rol en esa página (admin siempre; cajero según lo que la página ya sabe de su turno — pasar `true` y dejar que el backend regrese 403 con mensaje si no puede, mostrando el error del panel).

- [ ] **Step 3: Serialización.** Añadir `receipts` a los `with()`/`map()` de los controladores listados en Files (grep de cada uno: `->with(['payments` → agregar `'payments.receipts'`).

- [ ] **Step 4: Build + prueba manual** de los tres lugares (mesa, pagos, cliente).

- [ ] **Step 5: Commit** — `feat(pagos): clips y panel de comprobantes en listas de pagos`.

---

### Task 10: Documentación, suite completa y cierre

**Files:**
- Create: `docs/modulos/comprobantes-pago.md` (doc viva: estructura de `docs/modulos/gastos.md` — intro, Responsabilidades, Decisiones, Modelo de datos, Roles y permisos, Flujos, Rutas, Frontend, Riesgos, Tests)
- Modify: `docs/README.md` (índice + tabla "Estado del sistema")
- Modify: `docs/superpowers/specs/2026-07-15-comprobantes-transferencia-design.md` (header `Estado:` → `Implementado (2026-07-15) — ver docs/modulos/comprobantes-pago.md`)
- Modify: `/Users/sebas/Documents/version 2/CLAUDE.md` (agregar `PaymentReceipt` a la lista de modelos `BelongsToTenant`)

- [ ] **Step 1: Doc viva** con el contenido real implementado (rutas exactas, reglas, mensajes).
- [ ] **Step 2: Suite completa** — `./vendor/bin/sail artisan test --compact` TODA verde (no solo los filtros).
- [ ] **Step 3: Pint** — `./vendor/bin/sail bin pint --dirty --format agent`.
- [ ] **Step 4: Build** — `./vendor/bin/sail npm run build`.
- [ ] **Step 5: Commit final** — `docs(pagos): doc viva de comprobantes + estados actualizados`.
- [ ] **Step 6: PR** — push de la rama de trabajo → `gh pr create --base main` → merge (deploy automático).

---

## Self-Review (hecho al escribir)

- **Cobertura del spec:** modelo (T1), servicio (T2), store venta con required (T3), store CG con required + exención asistente (T4 — no toca servicios ni `app/Services/Ai/`), endpoints tarde/descarga/borrado + permisos turno + cascade (T5), CG endpoints (T6), toggles empresa (T7), UI cobro (T8), UI listas (T9), docs (T10). Bordes del spec: método ≠ transfer (T5 test), hijo CG (T5 controller + T4 test), flag off 403 (T5 test), edición de método conserva comprobantes (no hay código que los borre — se cumple por omisión; el cascade solo corre en delete).
- **Sin placeholders:** cada paso de código incluye el código; los pasos de verificación incluyen el comando. Donde el plan depende de nombres reales (route names, campos NOT NULL de factories), el paso indica el `grep`/`route:list` exacto para resolverlos.
- **Consistencia de tipos:** `attach(Payment|CustomerPayment, iterable, ?int): array` y `delete(PaymentReceipt): void` usados igual en T2/T3/T4/T5/T6; constantes `MAX_PER_PAYMENT=3` en todos los usos.
