# Catálogo de productos de compra — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Que cada línea de compra salga de un catálogo propio de productos de compra (independiente del de ventas); si el producto no existe, se crea al guardar (incluida la captura por IA).

**Architecture:** Nueva tabla/entidad `purchase_products` (tenant-wide, patrón `Provider`). `purchase_items.product_id` (→ products de venta) se reemplaza por `purchase_product_id` (→ purchase_products). En `store`/`update`, cada línea se resuelve por id o por nombre con **find-or-create** y se sella el `concept` con el nombre del producto. CRUD de catálogo para admin-empresa calcado de Proveedores. La IA sigue aportando solo nombres; la resolución ocurre al guardar.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia v2 + Vue 3, PostgreSQL, PHPUnit, Tailwind. Todo con `vendor/bin/sail`.

**Spec:** `docs/superpowers/specs/2026-05-20-catalogo-productos-compra-design.md`.

**Decisión de implementación (refina el spec):** en vez de exigir `purchase_product_id` y un endpoint separado de "crear al vuelo", cada línea manda `purchase_product_id` (si se eligió del catálogo) **o** el nombre en `concept`; el servidor resuelve por id o por nombre (find-or-create). Esto satisface "los productos se deben crear como catálogo" y hace que la IA (que solo da nombres) alimente el catálogo sin pasos extra ni tocar el servicio de IA.

---

## File Structure

**Crear:**
- `database/migrations/2026_05_20_110001_create_purchase_products_table.php`
- `database/migrations/2026_05_20_110002_swap_product_id_for_purchase_product_id_on_purchase_items.php`
- `app/Enums/PurchaseProductCategory.php`
- `app/Models/PurchaseProduct.php`
- `app/Http/Controllers/Empresa/PurchaseProductController.php`
- `resources/js/Pages/Empresa/ProductosCompra/Index.vue`
- `resources/js/Components/Compras/ProductoCompraFormModal.vue`
- `tests/Feature/Compras/PurchaseProductCatalogTest.php`

**Modificar:**
- `app/Models/PurchaseItem.php` — fillable + relación.
- `app/Http/Controllers/Concerns/HandlesPurchases.php` — validación, resolución find-or-create, store/update, serialize.
- `app/Http/Controllers/Empresa/PurchaseController.php` — pasar `purchaseProducts` al index.
- `app/Http/Controllers/Sucursal/PurchaseController.php` — pasar `purchaseProducts` al index.
- `routes/web.php` — recurso `productos-compra` (empresa).
- `resources/js/Components/Compras/CompraFormModal.vue` — datalist de catálogo en la línea.
- `resources/js/composables/usePurchaseAiDraft.js` — mapear líneas IA a `purchase_product_id: null` (resuelve el server).
- `resources/js/Pages/Empresa/Compras/Index.vue` y `resources/js/Pages/Sucursal/Compras/Index.vue` — pasar `purchaseProducts` al form.
- `tests/Feature/Compras/PurchaseModelTest.php` — reescribir el test de `product_id`.

---

## Task 1: Migraciones + enum de categoría

**Files:**
- Create: `database/migrations/2026_05_20_110001_create_purchase_products_table.php`
- Create: `database/migrations/2026_05_20_110002_swap_product_id_for_purchase_product_id_on_purchase_items.php`
- Create: `app/Enums/PurchaseProductCategory.php`

- [ ] **Step 1: Migración del catálogo**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('unit', 10)->default('kg');
            $table->string('category', 20)->nullable(); // res|cerdo|pollo|insumos|otro
            $table->string('status', 12)->default('active'); // active|inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name'], 'purchase_products_tenant_name_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_products');
    }
};
```

- [ ] **Step 2: Migración del swap en purchase_items**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('purchase_product_id')
                ->nullable()
                ->after('purchase_id')
                ->constrained('purchase_products')
                ->nullOnDelete();
            $table->index('purchase_product_id');
        });

        // Quitar el viejo FK a products de venta (en Postgres, drop de la
        // columna arrastra su índice).
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('purchase_id')->constrained('products')->nullOnDelete();
            $table->index('product_id');
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_product_id');
        });
    }
};
```

- [ ] **Step 3: Enum de categoría**

```php
<?php

namespace App\Enums;

enum PurchaseProductCategory: string
{
    case Res = 'res';
    case Cerdo = 'cerdo';
    case Pollo = 'pollo';
    case Insumos = 'insumos';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Res => 'Res',
            self::Cerdo => 'Cerdo',
            self::Pollo => 'Pollo',
            self::Insumos => 'Insumos',
            self::Otro => 'Otro',
        };
    }
}
```

- [ ] **Step 4: Migrar**

Run: `vendor/bin/sail artisan migrate`
Expected: dos `DONE` (`create_purchase_products_table`, `swap_product_id_for_purchase_product_id_on_purchase_items`).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_20_110001_create_purchase_products_table.php database/migrations/2026_05_20_110002_swap_product_id_for_purchase_product_id_on_purchase_items.php app/Enums/PurchaseProductCategory.php
git commit -m "Catálogo compras: tabla purchase_products + swap en purchase_items + enum categoría"
```

---

## Task 2: Modelos

**Files:**
- Create: `app/Models/PurchaseProduct.php`
- Modify: `app/Models/PurchaseItem.php`

- [ ] **Step 1: Modelo `PurchaseProduct`** (patrón `Provider`)

```php
<?php

namespace App\Models;

use App\Enums\PurchaseProductCategory;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'name', 'unit', 'category', 'status', 'created_by',
])]
class PurchaseProduct extends Model
{
    use BelongsToTenant, SoftDeletes;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    protected function casts(): array
    {
        return [
            'category' => PurchaseProductCategory::class,
        ];
    }
}
```

- [ ] **Step 2: `PurchaseItem` — swap fillable + relación**

Reemplaza el `#[Fillable([...])]`:

```php
#[Fillable([
    'purchase_id', 'product_id', 'concept', 'quantity', 'unit',
    'unit_price', 'subtotal', 'notes',
])]
```

por:

```php
#[Fillable([
    'purchase_id', 'purchase_product_id', 'concept', 'quantity', 'unit',
    'unit_price', 'subtotal', 'notes',
])]
```

Reemplaza el método `product()`:

```php
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
```

por:

```php
    public function purchaseProduct(): BelongsTo
    {
        return $this->belongsTo(PurchaseProduct::class);
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/PurchaseProduct.php app/Models/PurchaseItem.php
git commit -m "Catálogo compras: modelo PurchaseProduct y relación en PurchaseItem"
```

---

## Task 3: HandlesPurchases — validación, find-or-create, store/update, serialize

**Files:**
- Modify: `app/Http/Controllers/Concerns/HandlesPurchases.php`
- Test: `tests/Feature/Compras/PurchaseProductCatalogTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Compras;

use App\Models\PurchaseProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductCatalogTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function provider(): \App\Models\Provider
    {
        return \App\Models\Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne']);
    }

    private function payload(array $line): array
    {
        return [
            'provider_id' => $this->provider()->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [$line],
        ];
    }

    public function test_creates_catalog_product_from_line_name(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'concept' => 'Media canal de res',
            'quantity' => 2,
            'unit' => 'kg',
            'unit_price' => 100,
        ]))->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Media canal de res',
            'unit' => 'kg',
        ]);
        $this->assertDatabaseHas('purchase_items', [
            'concept' => 'Media canal de res',
        ]);
        $this->assertSame(1, PurchaseProduct::count());
    }

    public function test_reuses_existing_catalog_product_by_name_case_insensitive(): void
    {
        $existing = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pierna de cerdo',
            'unit' => 'kg',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'concept' => 'pierna de CERDO',
            'quantity' => 1,
            'unit' => 'kg',
            'unit_price' => 80,
        ]))->assertRedirect();

        $this->assertSame(1, PurchaseProduct::count());
        $this->assertDatabaseHas('purchase_items', [
            'purchase_product_id' => $existing->id,
            'concept' => 'Pierna de cerdo', // snapshot del nombre canónico
        ]);
    }

    public function test_uses_explicit_purchase_product_id(): void
    {
        $pp = PurchaseProduct::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pollo entero',
            'unit' => 'pieza',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $this->payload([
            'purchase_product_id' => $pp->id,
            'concept' => 'lo que sea',
            'quantity' => 3,
            'unit' => 'pieza',
            'unit_price' => 50,
        ]))->assertRedirect();

        $this->assertDatabaseHas('purchase_items', [
            'purchase_product_id' => $pp->id,
            'concept' => 'Pollo entero', // snapshot, ignora el texto enviado
        ]);
    }
}
```

- [ ] **Step 2: Correr (debe fallar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductCatalogTest.php`
Expected: FAIL (la columna `product_id` ya no existe / no se crea catálogo).

- [ ] **Step 3: Validación — swap de la regla de línea**

En `validatedPurchasePayload`, reemplaza:

```php
            'items.*.product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'items.*.concept' => 'required|string|max:160',
```

por:

```php
            'items.*.purchase_product_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('deleted_at')),
            ],
            'items.*.concept' => 'required|string|max:160',
```

- [ ] **Step 4: Helper de resolución find-or-create**

Añade el import al inicio de `HandlesPurchases.php` (junto a los otros `use App\Models\...`):

```php
use App\Models\PurchaseProduct;
```

Añade este método privado al trait (después de `validatedPurchasePayload`):

```php
    /**
     * Resuelve la línea a un producto de catálogo: usa el id si vino, si no
     * busca por nombre (case-insensitive) dentro del tenant y, si no existe,
     * lo crea. Devuelve el PurchaseProduct (su name se usa como snapshot).
     */
    private function resolvePurchaseProduct(int $tenantId, ?int $id, string $name, string $unit): PurchaseProduct
    {
        if ($id) {
            $found = PurchaseProduct::where('tenant_id', $tenantId)->whereKey($id)->first();
            if ($found) {
                return $found;
            }
        }

        $name = trim($name);
        $byName = PurchaseProduct::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($byName) {
            return $byName;
        }

        return PurchaseProduct::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'unit' => $unit ?: 'kg',
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
    }
```

- [ ] **Step 5: `store` — resolver cada línea**

En `store`, dentro del `foreach ($validated['items'] as $line)` que crea los `PurchaseItem`, reemplaza el bloque:

```php
            foreach ($validated['items'] as $line) {
                $lineSubtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line['product_id'] ?? null,
                    'concept' => $line['concept'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $lineSubtotal,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
```

por:

```php
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
```

- [ ] **Step 6: `update` — mismo cambio en su `foreach`**

En `update`, reemplaza el `foreach` de recreación de items:

```php
            foreach ($validated['items'] as $line) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line['product_id'] ?? null,
                    'concept' => $line['concept'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }
```

por:

```php
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
```

- [ ] **Step 7: `serializePurchase` — exponer `purchase_product_id`**

En el `.map` de `items`, reemplaza:

```php
                'id' => $i->id,
                'product_id' => $i->product_id,
                'concept' => $i->concept,
```

por:

```php
                'id' => $i->id,
                'purchase_product_id' => $i->purchase_product_id,
                'concept' => $i->concept,
```

- [ ] **Step 8: Correr (debe pasar)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductCatalogTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Concerns/HandlesPurchases.php tests/Feature/Compras/PurchaseProductCatalogTest.php
git commit -m "Catálogo compras: resolución find-or-create de líneas + snapshot de nombre"
```

---

## Task 4: CRUD de catálogo para admin-empresa (calcado de Proveedores)

**Files:**
- Create: `app/Http/Controllers/Empresa/PurchaseProductController.php`
- Modify: `routes/web.php`
- Test: añadir casos a `tests/Feature/Compras/PurchaseProductCatalogTest.php`

- [ ] **Step 1: Controlador** (calca `Empresa/ProviderController`: index/store/update/destroy con bloqueo de borrado si tiene compras)

```php
<?php

namespace App\Http\Controllers\Empresa;

use App\Enums\PurchaseProductCategory;
use App\Http\Controllers\Controller;
use App\Models\PurchaseProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD del catálogo de productos de compra para admin-empresa. Tenant-wide,
 * igual que Proveedores.
 */
class PurchaseProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('q', ''));
        $categoryFilter = $request->input('category');
        $statusFilter = $request->input('status', 'active');

        $products = PurchaseProduct::query()
            ->withCount('purchaseItems')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($categoryFilter, fn ($q) => $q->where('category', $categoryFilter))
            ->when($statusFilter !== 'all', fn ($q) => $q->where('status', $statusFilter))
            ->orderBy('name')
            ->get()
            ->map(fn (PurchaseProduct $p) => $this->serialize($p));

        return Inertia::render('Empresa/ProductosCompra/Index', [
            'products' => $products,
            'filters' => ['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter],
            'categories' => array_map(fn (PurchaseProductCategory $c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ], PurchaseProductCategory::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $validated = $this->validated($request, $tenant->id);

        PurchaseProduct::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]));

        return back()->with('success', 'Producto de compra creado.');
    }

    public function update(Request $request, PurchaseProduct $producto_compra): RedirectResponse
    {
        $tenant = app('tenant');
        if ($producto_compra->tenant_id !== $tenant->id) {
            abort(403);
        }

        $validated = $this->validated($request, $tenant->id, $producto_compra->id, withStatus: true);
        $producto_compra->update($validated);

        return back()->with('success', 'Producto de compra actualizado.');
    }

    public function destroy(PurchaseProduct $producto_compra): RedirectResponse
    {
        $tenant = app('tenant');
        if ($producto_compra->tenant_id !== $tenant->id) {
            abort(403);
        }

        $hasItems = DB::table('purchase_items')->where('purchase_product_id', $producto_compra->id)->exists();
        if ($hasItems) {
            return back()->withErrors([
                'producto' => 'No puedes eliminar un producto con compras. Márcalo como inactivo.',
            ]);
        }

        $producto_compra->delete();

        return back()->with('success', 'Producto de compra eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $tenantId, ?int $ignoreId = null, bool $withStatus = false): array
    {
        $nameRule = Rule::unique('purchase_products', 'name')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'));
        if ($ignoreId) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $rules = [
            'name' => ['required', 'string', 'max:160', $nameRule],
            'unit' => 'required|string|max:10',
            'category' => ['nullable', Rule::enum(PurchaseProductCategory::class)],
        ];
        if ($withStatus) {
            $rules['status'] = 'required|in:active,inactive';
        }

        return $request->validate($rules, [
            'name.unique' => 'Ya existe un producto de compra con ese nombre.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PurchaseProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'unit' => $p->unit,
            'category' => $p->category instanceof PurchaseProductCategory ? $p->category->value : $p->category,
            'category_label' => $p->category instanceof PurchaseProductCategory ? $p->category->label() : null,
            'status' => $p->status,
            'purchase_items_count' => (int) ($p->purchase_items_count ?? 0),
        ];
    }
}
```

- [ ] **Step 2: Rutas** (en el grupo `empresa`, junto a `proveedores`)

Añade el import al inicio de `routes/web.php`:

```php
use App\Http\Controllers\Empresa\PurchaseProductController as EmpresaPurchaseProductController;
```

En el grupo empresa (cerca de `Route::resource('proveedores', ...)`), añade:

```php
                Route::get('productos-compra', [EmpresaPurchaseProductController::class, 'index'])->name('productos-compra.index');
                Route::post('productos-compra', [EmpresaPurchaseProductController::class, 'store'])->name('productos-compra.store');
                Route::put('productos-compra/{producto_compra}', [EmpresaPurchaseProductController::class, 'update'])->name('productos-compra.update');
                Route::delete('productos-compra/{producto_compra}', [EmpresaPurchaseProductController::class, 'destroy'])->name('productos-compra.destroy');
```

- [ ] **Step 3: Tests de CRUD** — añade a `PurchaseProductCatalogTest`:

```php
    public function test_admin_empresa_creates_catalog_product(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.productos-compra.store', $this->tenant->slug), [
            'name' => 'Costilla de res',
            'unit' => 'kg',
            'category' => 'res',
        ])->assertRedirect();

        $this->assertDatabaseHas('purchase_products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Costilla de res',
            'category' => 'res',
        ]);
    }

    public function test_catalog_name_unique_per_tenant(): void
    {
        \App\Models\PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Dup', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->post(route('empresa.productos-compra.store', $this->tenant->slug), ['name' => 'Dup', 'unit' => 'kg'])
            ->assertSessionHasErrors('name');
    }

    public function test_cannot_delete_catalog_product_with_purchases(): void
    {
        $pp = \App\Models\PurchaseProduct::create(['tenant_id' => $this->tenant->id, 'name' => 'Con compras', 'unit' => 'kg', 'status' => 'active']);
        \App\Models\PurchaseItem::create([
            'purchase_id' => \App\Models\Purchase::create([
                'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
                'provider_id' => $this->provider()->id, 'folio' => 'CMP-2026-00001',
                'purchased_at' => now(), 'status' => 'received',
                'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
            ])->id,
            'purchase_product_id' => $pp->id, 'concept' => 'Con compras',
            'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100,
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->delete(route('empresa.productos-compra.destroy', ['tenant' => $this->tenant->slug, 'producto_compra' => $pp->id]))
            ->assertSessionHasErrors('producto');
    }

    public function test_cajero_cannot_access_catalog_crud(): void
    {
        $this->actingAs($this->cajero);
        $this->get(route('empresa.productos-compra.index', $this->tenant->slug))->assertForbidden();
    }
```

Añade los imports necesarios al principio del test: `use App\Models\PurchaseItem;` y `use App\Models\Purchase;`.

- [ ] **Step 4: Correr**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductCatalogTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Empresa/PurchaseProductController.php routes/web.php tests/Feature/Compras/PurchaseProductCatalogTest.php
git commit -m "Catálogo compras: CRUD admin-empresa (calcado de Proveedores)"
```

---

## Task 5: UI — picker de catálogo en la compra + pantalla de administración

**Files:**
- Modify: `app/Http/Controllers/Empresa/PurchaseController.php`
- Modify: `app/Http/Controllers/Sucursal/PurchaseController.php`
- Modify: `resources/js/Components/Compras/CompraFormModal.vue`
- Modify: `resources/js/composables/usePurchaseAiDraft.js`
- Modify: `resources/js/Pages/Empresa/Compras/Index.vue`
- Modify: `resources/js/Pages/Sucursal/Compras/Index.vue`
- Create: `resources/js/Pages/Empresa/ProductosCompra/Index.vue`
- Create: `resources/js/Components/Compras/ProductoCompraFormModal.vue`

- [ ] **Step 1: Pasar `purchaseProducts` al index de compras (empresa)**

En `Empresa/PurchaseController::index`, añade el import `use App\Models\PurchaseProduct;` y dentro del payload de `Inertia::render('Empresa/Compras/Index', [...])`, junto a `'providers' => ...`, añade:

```php
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
```

- [ ] **Step 2: Igual en `Sucursal/PurchaseController::index`**

Añade `use App\Models\PurchaseProduct;` y al payload de `Inertia::render('Sucursal/Compras/Index', [...])`:

```php
            'purchaseProducts' => PurchaseProduct::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
```

- [ ] **Step 3: `CompraFormModal.vue` — datalist de catálogo en la línea**

Añade la prop:

```js
    purchaseProducts: { type: Array, default: () => [] },
```

En `emptyLine()`, reemplaza `product_id: null,` por `purchase_product_id: null,`.

En el `watch` que carga `props.purchase.items`, reemplaza `product_id: i.product_id ?? null,` por `purchase_product_id: i.purchase_product_id ?? null,`.

Añade un resolvedor de nombre→producto en `<script setup>` (después de `units`):

```js
const onConceptInput = (line) => {
    const match = props.purchaseProducts.find(
        (p) => p.name.toLowerCase() === (line.concept || '').trim().toLowerCase()
    );
    line.purchase_product_id = match ? match.id : null;
    if (match && match.unit) line.unit = match.unit;
};
```

Reemplaza el input de concepto:

```html
                                                <input v-model="line.concept" type="text" placeholder="Ej. Pulpa de res"
                                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
```

por (input con datalist y resolución):

```html
                                                <input v-model="line.concept" type="text" list="catalogo-compra" placeholder="Busca o escribe un producto"
                                                    @input="onConceptInput(line)" @change="onConceptInput(line)"
                                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500" />
```

Justo antes de `</form>` (o dentro del modal, una sola vez), añade el datalist:

```html
                        <datalist id="catalogo-compra">
                            <option v-for="p in purchaseProducts" :key="p.id" :value="p.name" />
                        </datalist>
```

- [ ] **Step 4: `usePurchaseAiDraft.js` — mapear líneas IA al catálogo**

En `applyProposalToForm`, reemplaza el `.map` de `proposal.lineas`:

```js
        form.items = proposal.lineas.map((l) => ({
            product_id: l.product_id ?? null,
            concept: l.concepto ?? '',
            quantity: Number(l.quantity ?? 0),
            unit: l.unit ?? 'kg',
            unit_price: Number(l.unit_price ?? 0),
            notes: l.notas ?? '',
        }));
```

por:

```js
        form.items = proposal.lineas.map((l) => ({
            purchase_product_id: null, // el server resuelve por nombre (find-or-create)
            concept: l.concepto ?? '',
            quantity: Number(l.quantity ?? 0),
            unit: l.unit ?? 'kg',
            unit_price: Number(l.unit_price ?? 0),
            notes: l.notas ?? '',
        }));
```

- [ ] **Step 5: Pasar `purchaseProducts` al `CompraFormModal` en ambos Index**

En `resources/js/Pages/Empresa/Compras/Index.vue` y `resources/js/Pages/Sucursal/Compras/Index.vue`:
- Añade `purchaseProducts: { type: Array, default: () => [] },` al `defineProps`.
- En el `<CompraFormModal ... />`, añade el binding `:purchase-products="purchaseProducts"`.

- [ ] **Step 6: Pantalla de administración del catálogo** — crea `resources/js/Components/Compras/ProductoCompraFormModal.vue` (calca `Components/Proveedores/ProveedorFormModal.vue`, campos: name, unit, category select, status en edición; rutas `empresa.productos-compra.store|update`):

```vue
<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    product: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const isEdit = computed(() => !!props.product?.id);

const units = ['kg', 'g', 'l', 'ml', 'pieza', 'caja', 'bulto', 'cabeza'];

const form = useForm({ name: '', unit: 'kg', category: '', status: 'active' });

watch(() => props.open, (open) => {
    if (!open) return;
    if (props.product) {
        form.name = props.product.name ?? '';
        form.unit = props.product.unit ?? 'kg';
        form.category = props.product.category ?? '';
        form.status = props.product.status ?? 'active';
    } else {
        form.reset();
        form.clearErrors();
    }
});

const close = () => { form.clearErrors(); emit('close'); };

const submit = () => {
    if (isEdit.value) {
        form.put(route('empresa.productos-compra.update', { tenant: slug.value, producto_compra: props.product.id }), {
            preserveScroll: true, onSuccess: () => close(),
        });
    } else {
        form.post(route('empresa.productos-compra.store', slug.value), {
            preserveScroll: true, onSuccess: () => { close(); form.reset(); },
        });
    }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition" leave-active-class="transition"
            enter-from-class="opacity-0" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4">
                <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" @click.stop>
                    <header class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">{{ isEdit ? 'Editar producto' : 'Nuevo producto de compra' }}</h2>
                        <button @click="close" class="text-gray-400 hover:text-gray-700">✕</button>
                    </header>

                    <form @submit.prevent="submit" class="space-y-4 px-5 py-5">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Nombre <span class="text-red-600">*</span></label>
                            <input v-model="form.name" type="text"
                                class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                                placeholder="Ej. Media canal de res" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Unidad <span class="text-red-600">*</span></label>
                                <select v-model="form.unit" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Categoría</label>
                                <select v-model="form.category" class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                                    <option value="">— sin categoría —</option>
                                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                                </select>
                            </div>
                        </div>
                        <div v-if="isEdit">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                            <div class="flex gap-2">
                                <button type="button" @click="form.status = 'active'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700']">Activo</button>
                                <button type="button" @click="form.status = 'inactive'"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-semibold', form.status === 'inactive' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700']">Inactivo</button>
                            </div>
                        </div>
                    </form>

                    <footer class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <button type="button" @click="close" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">Cancelar</button>
                        <button @click="submit" :disabled="form.processing"
                            class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700 disabled:opacity-50">
                            {{ form.processing ? 'Guardando…' : (isEdit ? 'Actualizar' : 'Crear') }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 7: Página de administración** — crea `resources/js/Pages/Empresa/ProductosCompra/Index.vue` (calca `Pages/Empresa/Proveedores/Index.vue`: tabla con búsqueda + filtro de categoría/estado, botón "Nuevo producto", editar/eliminar). Usa `EmpresaLayout`, props `products`, `filters`, `categories`. Implementación mínima:

```vue
<script setup>
import EmpresaLayout from '@/Layouts/EmpresaLayout.vue';
import ProductoCompraFormModal from '@/Components/Compras/ProductoCompraFormModal.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
});

const page = usePage();
const slug = computed(() => page.props.auth.tenant_slug);
const search = ref(props.filters?.q || '');
const statusFilter = ref(props.filters?.status || 'active');

let t;
const navigate = () => {
    router.get(route('empresa.productos-compra.index', slug.value), {
        q: search.value || undefined,
        status: statusFilter.value !== 'active' ? statusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
watch(search, () => { clearTimeout(t); t = setTimeout(navigate, 300); });
const setStatus = (k) => { statusFilter.value = k; navigate(); };

const formOpen = ref(false);
const editing = ref(null);
const openCreate = () => { editing.value = null; formOpen.value = true; };
const openEdit = (p) => { editing.value = { ...p }; formOpen.value = true; };
const remove = (p) => {
    if (!confirm(`¿Eliminar "${p.name}"?`)) return;
    router.delete(route('empresa.productos-compra.destroy', { tenant: slug.value, producto_compra: p.id }), { preserveScroll: true });
};
const flash = computed(() => page.props.flash || {});
</script>

<template>
    <Head title="Productos de compra" />
    <EmpresaLayout>
        <template #header><h1 class="text-lg font-bold text-gray-900">Productos de compra</h1></template>

        <div class="space-y-5">
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <input v-model="search" type="text" placeholder="Buscar producto…"
                        class="w-full rounded-xl border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500 sm:max-w-sm" />
                    <div class="flex gap-1">
                        <button v-for="k in ['active','inactive','all']" :key="k" @click="setStatus(k)"
                            :class="['rounded-lg px-3 py-2 text-xs font-semibold transition', statusFilter === k ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']">
                            {{ k === 'active' ? 'Activos' : k === 'inactive' ? 'Inactivos' : 'Todos' }}
                        </button>
                    </div>
                </div>
                <button @click="openCreate" class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">+ Nuevo producto</button>
            </div>

            <div v-if="flash.success" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ flash.success }}</div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Producto</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Unidad</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Categoría</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Compras</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="p in products" :key="p.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="font-semibold text-gray-900">{{ p.name }}</span>
                                <span v-if="p.status === 'inactive'" class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">Inactivo</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.unit }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ p.category_label || '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-gray-900">{{ p.purchase_items_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="openEdit(p)" class="text-sm font-medium text-orange-700 hover:text-orange-900">Editar</button>
                                <button @click="remove(p)" class="ml-3 text-sm font-medium text-red-600 hover:text-red-800">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!products.length">
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500">Sin productos. <button @click="openCreate" class="font-semibold text-orange-700 hover:underline">Agregar el primero</button>.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ProductoCompraFormModal :open="formOpen" :product="editing" :categories="categories" @close="formOpen = false" />
    </EmpresaLayout>
</template>
```

- [ ] **Step 8: Enlace de navegación** — añade "Productos de compra" al menú de `EmpresaLayout.vue` cerca de "Proveedores" (busca el `<Link :href="route('empresa.proveedores.index', ...)">` y duplica con `route('empresa.productos-compra.index', ...)`, etiqueta "Productos de compra").

- [ ] **Step 9: Build**

Run: `vendor/bin/sail npm run build`
Expected: `built in ...` sin errores.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Empresa/PurchaseController.php app/Http/Controllers/Sucursal/PurchaseController.php resources/js/Components/Compras/CompraFormModal.vue resources/js/composables/usePurchaseAiDraft.js resources/js/Pages/Empresa/Compras/Index.vue resources/js/Pages/Sucursal/Compras/Index.vue resources/js/Pages/Empresa/ProductosCompra/Index.vue resources/js/Components/Compras/ProductoCompraFormModal.vue resources/js/Layouts/EmpresaLayout.vue
git commit -m "Catálogo compras: picker en la compra, mapeo IA y pantalla de administración"
```

---

## Task 6: Reescribir tests existentes que usan `product_id`

**Files:**
- Modify: `tests/Feature/Compras/PurchaseModelTest.php`
- (verificar otros)

- [ ] **Step 1: Localizar usos de `product_id` en compras**

Run: `grep -rn "product_id" tests/Feature/Compras database/seeders/TestDataSeeder.php`
Expected: ver las líneas a ajustar. Para cada `PurchaseItem::create([... 'product_id' => ...])`, cambiar a `'purchase_product_id' => <PurchaseProduct>->id`.

- [ ] **Step 2: Reescribir el test de preservación de concepto** en `tests/Feature/Compras/PurchaseModelTest.php::test_purchase_item_preserves_concept_when_product_is_deleted` para apuntar a `purchase_product_id` (crear un `PurchaseProduct`, ligar el item, borrar el producto con `forceDelete()` y verificar que el item sobrevive con `concept` intacto y `purchase_product_id` nulo por `nullOnDelete`). Código:

```php
    public function test_purchase_item_preserves_concept_when_product_is_deleted(): void
    {
        $tenant = \App\Models\Tenant::first() ?? $this->tenant;
        $product = \App\Models\PurchaseProduct::create([
            'tenant_id' => $tenant->id, 'name' => 'Pulpa', 'unit' => 'kg', 'status' => 'active',
        ]);
        $item = \App\Models\PurchaseItem::create([
            'purchase_id' => $this->purchase->id,
            'purchase_product_id' => $product->id,
            'concept' => 'Pulpa',
            'quantity' => 1, 'unit' => 'kg', 'unit_price' => 10, 'subtotal' => 10,
        ]);

        $product->forceDelete();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Pulpa', $fresh->concept);
        $this->assertNull($fresh->purchase_product_id);
    }
```

> Ajusta `$this->purchase` / `$this->tenant` a como ya estén definidos en ese archivo de test (léelo primero). No borres el test; reescríbelo.

- [ ] **Step 3: Correr la suite de compras completa**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Compras
git commit -m "Catálogo compras: reescribe tests que usaban product_id de ventas"
```

---

## Task 7: Pint + suite completa

- [ ] **Step 1:** `vendor/bin/sail bin pint --dirty --format agent` → `{"result":"pass"}`.
- [ ] **Step 2:** `vendor/bin/sail artisan test --compact` → toda la suite verde.
- [ ] **Step 3:** Commit si Pint cambió algo.

---

## Self-Review

**Cobertura del spec:**
- Tabla `purchase_products` tenant-wide + enum categoría → Task 1. ✓
- `purchase_items.purchase_product_id` requerido (vía resolución) + se quita `product_id` de ventas → Tasks 1–3. ✓
- Snapshot de `concept` desde el nombre del producto (store y update) → Task 3. ✓
- Picker de catálogo en la compra + crear al vuelo (find-or-create) → Tasks 3 y 5. ✓
- IA empareja por nombre / crea al guardar → Tasks 3 (resolver) y 5 (mapeo IA a `purchase_product_id: null`). ✓
- CRUD admin-empresa + bloqueo de borrado con compras → Task 4. ✓
- Tests (CRUD, find-or-create, reuse, id explícito, permisos, reescritura) → Tasks 3, 4, 6. ✓

**Desviación consciente del spec:** la resolución es **find-or-create por nombre** al guardar (en vez de exigir `purchase_product_id` + endpoint separado de "crear al vuelo"). Cumple "los productos se deben crear como catálogo", maneja la IA sin tocar su servicio y simplifica la UI (datalist en vez de combobox a medida). El `purchase_product_id` de ventas que devuelve la IA se ignora en el front (limpieza futura del prompt opcional).

**Type consistency:** `resolvePurchaseProduct(int,?int,string,string): PurchaseProduct` se usa en `store` y `update`. La línea del form usa `purchase_product_id` (no `product_id`) en `emptyLine`, watch de edición, mapeo IA y serialize. La ruta usa el binding `{producto_compra}` consistente con el controlador.
