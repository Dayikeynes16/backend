# Fusión de productos de compra duplicados — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a admin-empresa una función para fusionar fichas duplicadas del catálogo de productos de compra (ej. 80+ "Canal de res N"): reapunta el historial de compras a la ficha canónica, normaliza el texto histórico moviendo el dato variable a la nota de la línea, y da de baja las fichas absorbidas.

**Architecture:** Un servicio de dominio `PurchaseProductMergeService` concentra toda la lógica transaccional y la regla de normalización (unit-testeable en aislamiento). Tres endpoints en `Empresa\PurchaseProductController` (candidatos para el buscador, preview de impacto, ejecutar) — solo empresa. Un modal Vue pulido estilo iOS en la pantalla Productos de compra. Sin migraciones: usa columnas existentes (`purchase_items.purchase_product_id`, `concept`, `notes`; soft-delete de `purchase_products`).

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL (Sail), Inertia v2, Vue 3 `<script setup>`, Tailwind v3, PHPUnit. `route()` es global (Ziggy). `window.axios` disponible para lecturas JSON.

**Spec:** `docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md`
**Rama:** `feat/productos-compra-fusion` (worktree: `/private/tmp/claude-501/-Users-sebas-Documents-version-2/e51ebe36-082f-48c1-9fba-d33483e9a303/scratchpad/fusion-productos`)

---

## Contexto para quien no conoce el codebase

- **Multi-tenant:** los modelos usan el trait `BelongsToTenant` que auto-filtra por `tenant_id`. En tests, `SeedsMetricsData::seedTenant()` crea `$this->tenant`, `$this->branch`, `$this->adminEmpresa`, `$this->adminSucursal`, `$this->cajero`. Rutas llevan slug: `route('empresa.productos-compra.index', $tenant->slug)`.
- **Catálogo de compra:** `PurchaseProduct` (tabla `purchase_products`) con `SoftDeletes`, índice único `(tenant_id, name)`, `RecordsHistory` (auditoría). Las líneas de compra viven en `purchase_items` con FK `purchase_product_id` (`nullOnDelete`) y un snapshot `concept` (string 160, NOT NULL) + `notes` (string 500, nullable).
- **Permisos:** admin-empresa gestiona el catálogo completo; el `destroy` de productos ya es exclusivo de empresa (`Empresa\PurchaseProductController::destroy`). La fusión sigue el mismo patrón: solo empresa.
- **Auditoría:** `App\Services\AuditLogger` es el único punto que escribe `audit_logs`. `PurchaseProduct` usa `RecordsHistory` (relación `history()`).
- **Tests:** PHPUnit vía Sail. Ejemplo canónico de setup: `tests/Feature/Compras/PurchaseProductCatalogTest.php`. Se crean modelos con `::create` directo (no hay factory de PurchaseProduct).
- **Frontend sin tests JS:** la verificación de Vue es `npm run build` + QA visual. No crear tests JS.

### Archivos que toca este plan

| Acción | Archivo | Responsabilidad |
|---|---|---|
| Modify | `app/Enums/AuditEvent.php` | Nuevo caso `Merged` |
| Create | `app/Services/Purchases/PurchaseProductMergeService.php` | Lógica de fusión + normalización |
| Create | `tests/Unit/PurchaseProductMergeNormalizationTest.php` | Unit test de la regla de normalización |
| Create | `tests/Feature/Compras/PurchaseProductMergeTest.php` | Feature test de endpoints + efecto en BD |
| Modify | `app/Http/Controllers/Empresa/PurchaseProductController.php` | Métodos `mergeCandidates`, `mergePreview`, `merge` |
| Modify | `routes/web.php` (grupo empresa, ~línea 201) | 3 rutas de fusión |
| Create | `resources/js/Components/Compras/FusionarProductosModal.vue` | Modal pulido estilo iOS |
| Modify | `resources/js/Components/Compras/PurchaseProductsManager.vue` | Botón "Fusionar duplicados" + montaje del modal (prop `canMerge`) |
| Modify | `resources/js/Pages/Empresa/ProductosCompra/Index.vue` | Pasar `:can-merge="true"` |
| Modify | `resources/js/Pages/Sucursal/ProductosCompra/Index.vue` | Pasar `:can-merge="false"` |
| Modify | `docs/modulos/compras.md` | Documentar la fusión |
| Modify | `docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md` | Estado → Implementado |

---

### Task 0: Preparar el worktree y confirmar el harness de tests

**Files:** ninguno (setup).

- [ ] **Step 1: Symlinks + dependencias JS**

Desde el worktree (`vendor` y `.env` viven en el checkout principal; el build de Vite y los tests los necesitan):

```bash
cd "/private/tmp/claude-501/-Users-sebas-Documents-version-2/e51ebe36-082f-48c1-9fba-d33483e9a303/scratchpad/fusion-productos"
ln -sfn "/Users/sebas/Documents/version 2/carniceria-saas/vendor" vendor
ln -sfn "/Users/sebas/Documents/version 2/carniceria-saas/.env" .env
npm install
```

Expected: `npm install` termina sin errores.

- [ ] **Step 2: Confirmar que el build de frontend funciona (baseline)**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 3: Confirmar que el harness de PHP tests corre en verde ANTES de tocar nada**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductCatalogTest.php`
Expected: todos los tests PASS. Si el comando falla por entorno (contenedores Sail no levantados o conflicto de puertos), levantar con `vendor/bin/sail up -d` desde este worktree o reutilizar los contenedores del checkout principal, y reintentar hasta obtener verde. **No continuar hasta que este test existente pase** — es la prueba de que podemos ejecutar tests.

- [ ] **Step 4: (sin commit)** Task de setup, no genera commits.

---

### Task 1: Caso de auditoría `Merged`

**Files:**
- Modify: `app/Enums/AuditEvent.php`

- [ ] **Step 1: Escribir el test que falla**

Añadir a un test nuevo `tests/Unit/AuditEventMergedTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\AuditEvent;
use PHPUnit\Framework\TestCase;

class AuditEventMergedTest extends TestCase
{
    public function test_merged_case_exists_with_label(): void
    {
        $this->assertSame('merged', AuditEvent::Merged->value);
        $this->assertSame('Fusionó', AuditEvent::Merged->label());
    }
}
```

- [ ] **Step 2: Correr y verlo fallar**

Run: `vendor/bin/sail artisan test --compact tests/Unit/AuditEventMergedTest.php`
Expected: FAIL (`Merged` no existe).

- [ ] **Step 3: Implementar**

En `app/Enums/AuditEvent.php`, añadir el caso tras `PaymentCancelled` y su label en el `match`:

```php
    case PaymentCancelled = 'payment_cancelled';
    case Merged = 'merged';
```

y en `label()`:

```php
            self::PaymentCancelled => 'Canceló pago',
            self::Merged => 'Fusionó',
```

- [ ] **Step 4: Correr y verlo pasar**

Run: `vendor/bin/sail artisan test --compact tests/Unit/AuditEventMergedTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/AuditEvent.php tests/Unit/AuditEventMergedTest.php
git commit -m "feat(compras): evento de auditoría Merged para fusión de productos"
```

---

### Task 2: Regla de normalización (unit-testeada en aislamiento)

Esta es la parte más delicada del spec (§3.1). Se implementa como método público del servicio para poder testearla sin BD.

**Files:**
- Create: `app/Services/Purchases/PurchaseProductMergeService.php`
- Test: `tests/Unit/PurchaseProductMergeNormalizationTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Unit;

use App\Services\Purchases\PurchaseProductMergeService;
use PHPUnit\Framework\TestCase;

class PurchaseProductMergeNormalizationTest extends TestCase
{
    private PurchaseProductMergeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PurchaseProductMergeService();
    }

    public function test_moves_numeric_suffix_to_empty_notes(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 111', null);
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('111', $r['notes']);
    }

    public function test_prepends_suffix_when_notes_already_has_content(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 112', 'entregado frío');
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('112 · entregado frío', $r['notes']);
    }

    public function test_strips_non_space_separator(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res-123', null);
        $this->assertSame('123', $r['notes']);
    }

    public function test_exact_match_leaves_notes_untouched(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'canal de res', 'sin cambios');
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('sin cambios', $r['notes']);
    }

    public function test_non_prefix_preserves_whole_old_concept_in_notes(): void
    {
        $r = $this->svc->buildNormalizedLine('Canal de res', 'canal viejo raro', null);
        $this->assertSame('Canal de res', $r['concept']);
        $this->assertSame('canal viejo raro', $r['notes']);
    }

    public function test_false_prefix_is_not_treated_as_suffix(): void
    {
        // "Canal de resitas" empieza con "Canal de res" pero el siguiente char
        // es alfanumérico → es OTRO nombre, se preserva completo.
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de resitas', null);
        $this->assertSame('Canal de resitas', $r['notes']);
    }

    public function test_notes_truncated_to_500(): void
    {
        $long = str_repeat('x', 600);
        $r = $this->svc->buildNormalizedLine('Canal de res', 'Canal de res 111', $long);
        $this->assertSame(500, mb_strlen($r['notes']));
        $this->assertStringStartsWith('111 · ', $r['notes']);
    }
}
```

- [ ] **Step 2: Correr y verlo fallar**

Run: `vendor/bin/sail artisan test --compact tests/Unit/PurchaseProductMergeNormalizationTest.php`
Expected: FAIL (clase no existe).

- [ ] **Step 3: Implementar el servicio con el método de normalización**

Crear `app/Services/Purchases/PurchaseProductMergeService.php`:

```php
<?php

namespace App\Services\Purchases;

/**
 * Fusiona fichas duplicadas de purchase_products en una canónica: reapunta
 * las líneas de compra, normaliza su concept y mueve el dato variable a la
 * nota de la línea, y da de baja (soft-delete) las absorbidas.
 *
 * La regla de normalización (buildNormalizedLine) es pura y unit-testeable.
 */
class PurchaseProductMergeService
{
    /**
     * Calcula el concept normalizado y la nota resultante de una línea al
     * reapuntarla a la ficha canónica (spec §3.1).
     *
     * @return array{concept: string, notes: ?string}
     */
    public function buildNormalizedLine(string $canonicalName, string $oldConcept, ?string $oldNotes): array
    {
        $canonical = trim($canonicalName);
        $old = trim($oldConcept);
        $lowerCanon = mb_strtolower($canonical);
        $lowerOld = mb_strtolower($old);

        if ($lowerOld === $lowerCanon) {
            $rest = '';
        } elseif (
            str_starts_with($lowerOld, $lowerCanon)
            && preg_match('/^[^\p{L}\p{N}]/u', mb_substr($old, mb_strlen($canonical))) === 1
        ) {
            // El texto tras el nombre canónico empieza con un separador → es un sufijo.
            $tail = mb_substr($old, mb_strlen($canonical));
            $rest = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $tail));
        } else {
            // No es prefijo limpio (o es un nombre distinto) → preservar completo.
            $rest = $old;
        }

        $notes = $oldNotes;
        if ($rest !== '') {
            if ($oldNotes === null || trim($oldNotes) === '') {
                $notes = $rest;
            } else {
                $notes = mb_substr($rest.' · '.$oldNotes, 0, 500);
            }
        }

        return ['concept' => $canonical, 'notes' => $notes];
    }
}
```

- [ ] **Step 4: Correr y verlo pasar**

Run: `vendor/bin/sail artisan test --compact tests/Unit/PurchaseProductMergeNormalizationTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Purchases/PurchaseProductMergeService.php tests/Unit/PurchaseProductMergeNormalizationTest.php
git commit -m "feat(compras): regla de normalización de concept/notes para fusión"
```

---

### Task 3: Métodos `merge` y `preview` del servicio (transaccional)

**Files:**
- Modify: `app/Services/Purchases/PurchaseProductMergeService.php`
- Test: `tests/Feature/Compras/PurchaseProductMergeServiceTest.php`

- [ ] **Step 1: Escribir el test de feature que falla**

Crear `tests/Feature/Compras/PurchaseProductMergeServiceTest.php`:

```php
<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Services\Purchases\PurchaseProductMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductMergeServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function product(string $name, string $unit = 'kg'): PurchaseProduct
    {
        return PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'unit' => $unit, 'status' => 'active',
        ]);
    }

    private function lineFor(PurchaseProduct $p, string $concept, ?string $notes = null): PurchaseItem
    {
        $provider = Provider::create(['name' => 'Prov '.uniqid(), 'type' => 'mayorista_carne']);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => $provider->id, 'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);

        return PurchaseItem::create([
            'purchase_id' => $purchase->id, 'purchase_product_id' => $p->id,
            'concept' => $concept, 'notes' => $notes,
            'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100,
        ]);
    }

    public function test_merge_relinks_items_and_soft_deletes_absorbed(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $b = $this->product('Canal de res 112');
        $itemA = $this->lineFor($a, 'Canal de res 111');
        $itemB = $this->lineFor($b, 'Canal de res 112', 'entregado frío');

        $result = app(PurchaseProductMergeService::class)->merge($canonical, [$a->id, $b->id]);

        $this->assertSame(2, $result['absorbed_count']);
        $this->assertSame(2, $result['relinked_items_count']);

        // Reapuntados al canónico
        $this->assertSame($canonical->id, $itemA->fresh()->purchase_product_id);
        $this->assertSame($canonical->id, $itemB->fresh()->purchase_product_id);
        // Concept normalizado, dato variable a notes
        $this->assertSame('Canal de res', $itemA->fresh()->concept);
        $this->assertSame('111', $itemA->fresh()->notes);
        $this->assertSame('Canal de res', $itemB->fresh()->concept);
        $this->assertSame('112 · entregado frío', $itemB->fresh()->notes);
        // Absorbidos soft-deleted; canónico vivo
        $this->assertSoftDeleted('purchase_products', ['id' => $a->id]);
        $this->assertSoftDeleted('purchase_products', ['id' => $b->id]);
        $this->assertNotNull($canonical->fresh());
    }

    public function test_merge_ignores_canonical_in_absorbed_list(): void
    {
        $canonical = $this->product('Canal de res');
        $this->lineFor($canonical, 'Canal de res');

        $result = app(PurchaseProductMergeService::class)->merge($canonical, [$canonical->id]);

        $this->assertSame(0, $result['absorbed_count']);
        $this->assertNotNull($canonical->fresh());
    }

    public function test_merge_logs_audit_event_on_canonical(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        app(PurchaseProductMergeService::class)->merge($canonical, [$a->id]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $canonical->getMorphClass(),
            'auditable_id' => $canonical->id,
            'event' => 'merged',
        ]);
    }

    public function test_preview_returns_counts_and_unit_mismatch(): void
    {
        $canonical = $this->product('Canal de res', 'kg');
        $a = $this->product('Canal de res 111', 'kg');
        $b = $this->product('Canal de res caja', 'pieza');
        $this->lineFor($a, 'Canal de res 111');
        $this->lineFor($b, 'Canal de res caja');
        $this->lineFor($b, 'Canal de res caja');

        $preview = app(PurchaseProductMergeService::class)->preview($canonical, [$a->id, $b->id]);

        $this->assertSame(2, $preview['absorbed_count']);
        $this->assertSame(3, $preview['items_count']);
        $this->assertTrue($preview['unit_mismatch']);
    }

    public function test_merge_ignores_nonexistent_absorbed_ids(): void
    {
        // Robustez/idempotencia: un id inexistente (o ya borrado por otra
        // sesión) se ignora sin romper la fusión. La atomicidad real la
        // garantiza estructuralmente el DB::transaction que envuelve merge().
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        $result = app(PurchaseProductMergeService::class)->merge($canonical, [$a->id, 999999]);

        $this->assertSame(1, $result['absorbed_count']);
        $this->assertSoftDeleted('purchase_products', ['id' => $a->id]);
    }
}
```

- [ ] **Step 2: Correr y verlo fallar**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductMergeServiceTest.php`
Expected: FAIL (métodos `merge`/`preview` no existen).

- [ ] **Step 3: Implementar `merge` y `preview` en el servicio**

Añadir a `app/Services/Purchases/PurchaseProductMergeService.php` los `use` y métodos (dentro de la clase, encima de `buildNormalizedLine`):

```php
use App\Enums\AuditEvent;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
```

```php
    public function __construct(private AuditLogger $auditor) {}

    /**
     * Reapunta las líneas de las fichas absorbidas al canónico, normaliza su
     * texto y da de baja las absorbidas. Todo en una transacción.
     *
     * @param  array<int, int>  $absorbedIds
     * @return array{absorbed_count: int, relinked_items_count: int}
     */
    public function merge(PurchaseProduct $canonical, array $absorbedIds): array
    {
        return DB::transaction(function () use ($canonical, $absorbedIds) {
            $canonical = PurchaseProduct::whereKey($canonical->id)->lockForUpdate()->firstOrFail();

            $absorbed = PurchaseProduct::whereIn('id', $absorbedIds)
                ->where('id', '!=', $canonical->id)
                ->lockForUpdate()
                ->get();

            $relinked = 0;
            foreach ($absorbed as $product) {
                $items = PurchaseItem::where('purchase_product_id', $product->id)->lockForUpdate()->get();
                foreach ($items as $item) {
                    $normalized = $this->buildNormalizedLine($canonical->name, $item->concept, $item->notes);
                    $item->update([
                        'purchase_product_id' => $canonical->id,
                        'concept' => $normalized['concept'],
                        'notes' => $normalized['notes'],
                    ]);
                    $relinked++;
                }
                $product->delete();
            }

            if ($absorbed->isNotEmpty()) {
                $this->auditor->log($canonical, AuditEvent::Merged, [
                    'absorbed' => $absorbed->pluck('name')->all(),
                    'items_relinked' => $relinked,
                ]);
            }

            return ['absorbed_count' => $absorbed->count(), 'relinked_items_count' => $relinked];
        });
    }

    /**
     * Calcula el impacto sin ejecutar nada.
     *
     * @param  array<int, int>  $absorbedIds
     * @return array{absorbed_count: int, items_count: int, unit_mismatch: bool}
     */
    public function preview(PurchaseProduct $canonical, array $absorbedIds): array
    {
        $absorbed = PurchaseProduct::whereIn('id', $absorbedIds)
            ->where('id', '!=', $canonical->id)
            ->get();

        $itemsCount = PurchaseItem::whereIn('purchase_product_id', $absorbed->pluck('id'))->count();
        $units = $absorbed->pluck('unit')->push($canonical->unit)->unique();

        return [
            'absorbed_count' => $absorbed->count(),
            'items_count' => $itemsCount,
            'unit_mismatch' => $units->count() > 1,
        ];
    }
```

Nota: el constructor con `AuditLogger` reemplaza la ausencia de constructor. El unit test de la Task 2 instancia `new PurchaseProductMergeService()` sin argumentos — cambiar esa línea del unit test a inyectar un `AuditLogger`:

En `tests/Unit/PurchaseProductMergeNormalizationTest.php`, cambiar `setUp`:

```php
    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PurchaseProductMergeService(new \App\Services\AuditLogger());
    }
```

- [ ] **Step 4: Correr ambos tests (unit + feature del servicio)**

Run: `vendor/bin/sail artisan test --compact tests/Unit/PurchaseProductMergeNormalizationTest.php tests/Feature/Compras/PurchaseProductMergeServiceTest.php`
Expected: PASS (7 unit + 5 feature).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Purchases/PurchaseProductMergeService.php tests/Unit/PurchaseProductMergeNormalizationTest.php tests/Feature/Compras/PurchaseProductMergeServiceTest.php
git commit -m "feat(compras): PurchaseProductMergeService merge+preview transaccional"
```

---

### Task 4: Endpoints del controlador (candidatos, preview, merge) — solo empresa

**Files:**
- Modify: `app/Http/Controllers/Empresa/PurchaseProductController.php`
- Modify: `routes/web.php` (grupo empresa)
- Test: `tests/Feature/Compras/PurchaseProductMergeTest.php`

- [ ] **Step 1: Escribir el test de endpoints que falla**

Crear `tests/Feature/Compras/PurchaseProductMergeTest.php`:

```php
<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseProductMergeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function product(string $name, string $unit = 'kg'): PurchaseProduct
    {
        return PurchaseProduct::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'unit' => $unit, 'status' => 'active',
        ]);
    }

    private function lineFor(PurchaseProduct $p, string $concept): void
    {
        $provider = Provider::create(['name' => 'Prov '.uniqid(), 'type' => 'mayorista_carne']);
        $purchase = Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => $provider->id, 'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'purchase_product_id' => $p->id,
            'concept' => $concept, 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100,
        ]);
    }

    public function test_candidates_search_returns_matching_active_products(): void
    {
        $this->product('Canal de res 111');
        $this->product('Canal de res 112');
        $this->product('Pollo entero');

        $this->actingAs($this->adminEmpresa);
        $this->getJson(route('empresa.productos-compra.fusionar.candidatos', [$this->tenant->slug, 'q' => 'canal']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Canal de res 111');
    }

    public function test_preview_returns_impact(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar.preview', $this->tenant->slug), [
            'canonical_id' => $canonical->id,
            'absorbed_ids' => [$a->id],
        ])->assertOk()
            ->assertJsonPath('absorbed_count', 1)
            ->assertJsonPath('items_count', 1)
            ->assertJsonPath('unit_mismatch', false);
    }

    public function test_merge_executes_and_redirects(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');
        $this->lineFor($a, 'Canal de res 111');

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.productos-compra.index', $this->tenant->slug))
            ->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
                'canonical_id' => $canonical->id,
                'absorbed_ids' => [$a->id],
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('purchase_products', ['id' => $a->id]);
    }

    public function test_sucursal_cannot_merge(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');

        $this->actingAs($this->adminSucursal);
        $this->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$a->id],
        ])->assertForbidden();
    }

    public function test_cajero_cannot_merge(): void
    {
        $canonical = $this->product('Canal de res');
        $a = $this->product('Canal de res 111');

        $this->actingAs($this->cajero);
        $this->post(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$a->id],
        ])->assertForbidden();
    }

    public function test_cannot_merge_product_from_another_tenant(): void
    {
        $canonical = $this->product('Canal de res');

        // Ficha de otro tenant
        $other = \App\Models\Tenant::create(['name' => 'Otra', 'slug' => 'otra-'.uniqid(), 'status' => 'active']);
        $foreign = PurchaseProduct::create(['tenant_id' => $other->id, 'name' => 'Ajena', 'unit' => 'kg', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.productos-compra.fusionar', $this->tenant->slug), [
            'canonical_id' => $canonical->id, 'absorbed_ids' => [$foreign->id],
        ])->assertStatus(422);

        $this->assertNull($foreign->fresh()->deleted_at);
    }
}
```

- [ ] **Step 2: Correr y verlo fallar**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductMergeTest.php`
Expected: FAIL (rutas/métodos no existen).

- [ ] **Step 3: Añadir las rutas (grupo empresa)**

En `routes/web.php`, dentro del grupo empresa, junto a las demás rutas `productos-compra` (tras la línea del `destroy`, ~línea 201), añadir:

```php
                // Fusión de duplicados (solo empresa). 'fusionar' no colisiona con
                // {producto_compra} porque ese parámetro está restringido a numérico.
                Route::get('productos-compra/fusionar/candidatos', [EmpresaPurchaseProductController::class, 'mergeCandidates'])->name('productos-compra.fusionar.candidatos');
                Route::post('productos-compra/fusionar/preview', [EmpresaPurchaseProductController::class, 'mergePreview'])->name('productos-compra.fusionar.preview');
                Route::post('productos-compra/fusionar', [EmpresaPurchaseProductController::class, 'merge'])->name('productos-compra.fusionar');
```

- [ ] **Step 4: Implementar los métodos del controlador**

En `app/Http/Controllers/Empresa/PurchaseProductController.php`, añadir `use` y métodos. Añadir a los imports (el archivo ya importa `JsonResponse`, `RedirectResponse`, `Request`, `PurchaseProduct`):

```php
use App\Services\Purchases\PurchaseProductMergeService;
use Illuminate\Validation\Rule;
```

Y los métodos dentro de la clase:

```php
    public function mergeCandidates(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        $products = PurchaseProduct::query()
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'unit']);

        return response()->json(['data' => $products]);
    }

    public function mergePreview(Request $request, PurchaseProductMergeService $service): JsonResponse
    {
        [$canonical, $absorbedIds] = $this->validatedMergeInput($request);

        return response()->json($service->preview($canonical, $absorbedIds));
    }

    public function merge(Request $request, PurchaseProductMergeService $service): RedirectResponse
    {
        [$canonical, $absorbedIds] = $this->validatedMergeInput($request);

        $result = $service->merge($canonical, $absorbedIds);

        return back()->with('success', "Se fusionaron {$result['absorbed_count']} fichas en «{$canonical->name}» ({$result['relinked_items_count']} líneas reapuntadas).");
    }

    /**
     * Valida y resuelve el input de fusión, forzando pertenencia al tenant
     * tanto del canónico como de cada absorbido (defensa multi-tenant).
     *
     * @return array{0: PurchaseProduct, 1: array<int, int>}
     */
    private function validatedMergeInput(Request $request): array
    {
        $tenantId = app('tenant')->id;
        $inTenant = fn ($q) => $q->where('tenant_id', $tenantId);

        $validated = $request->validate([
            'canonical_id' => ['required', 'integer', Rule::exists('purchase_products', 'id')->where($inTenant)->whereNull('deleted_at')],
            'absorbed_ids' => ['required', 'array', 'min:1'],
            'absorbed_ids.*' => ['integer', Rule::exists('purchase_products', 'id')->where($inTenant)->whereNull('deleted_at')],
        ]);

        $canonical = PurchaseProduct::findOrFail($validated['canonical_id']);

        return [$canonical, $validated['absorbed_ids']];
    }
```

Nota: `PurchaseItem` no se usa directamente en el controlador; quitar de los `use` si el linter (Pint) lo marca — o dejar solo `PurchaseProductMergeService` y `Rule`. Ajustar los imports a lo realmente usado.

- [ ] **Step 5: Correr y verlo pasar**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductMergeTest.php`
Expected: PASS (6 tests). Los `assertForbidden` pasan porque el grupo empresa ya exige rol admin-empresa vía middleware.

- [ ] **Step 6: Pint + commit**

Run: `vendor/bin/sail bin pint --dirty --format agent`

```bash
git add app/Http/Controllers/Empresa/PurchaseProductController.php routes/web.php tests/Feature/Compras/PurchaseProductMergeTest.php
git commit -m "feat(compras): endpoints de fusión (candidatos, preview, merge) solo empresa"
```

---

### Task 5: Modal de fusión pulido estilo iOS

**Files:**
- Create: `resources/js/Components/Compras/FusionarProductosModal.vue`

Requisito de calidad visual (spec §5): estética tipo app de iOS. Aplicar los skills `emil-design-eng` y/o `frontend-design` al construir el componente — no es un formulario genérico. El review de esta tarea evalúa el pulido, no solo que compile.

- [ ] **Step 1: Crear el componente**

Crear `resources/js/Components/Compras/FusionarProductosModal.vue`. Requisitos concretos que el componente DEBE cumplir:

- **Props:** `open: Boolean`, `tenantSlug: String`. **Emits:** `close`, `merged`.
- **Datos:** al abrir, buscador (`q`) con debounce ~250 ms que llama `GET route('empresa.productos-compra.fusionar.candidatos', {tenant: tenantSlug, q})` vía `window.axios`, guarda `candidates` (`[{id, name, unit}]`).
- **Selección:** cada candidato es una fila tipo lista iOS (no tabla): nombre, chip de unidad, y un check circular animado al seleccionar. Casilla "Seleccionar todas las coincidencias".
- **Canónica:** de entre las seleccionadas, un selector marca cuál es la canónica (radio con acento). Por defecto la de **nombre más corto** entre las seleccionadas.
- **Preview:** al tener canónica + ≥1 absorbido, llamar `POST route('empresa.productos-compra.fusionar.preview', tenantSlug)` con `{canonical_id, absorbed_ids}` (absorbed = seleccionados menos la canónica) y mostrar un **panel de impacto**: número grande (`items_count`) + etiqueta ("líneas se reapuntarán"), y "N fichas → «nombre canónico»". Si `unit_mismatch`, mostrar un banner suave de advertencia (ámbar, redondeado, no agresivo).
- **Confirmar:** botón primario que, tras un `ConfirmDialog`-style de confirmación fuerte, hace `router.post(route('empresa.productos-compra.fusionar', tenantSlug), {canonical_id, absorbed_ids}, { onSuccess: () => { emit('merged'); emit('close'); }, preserveScroll: true })`.
- **Estética iOS (obligatoria):** panel `rounded-3xl`, sombra difusa (`shadow-2xl`), overlay `bg-black/40 backdrop-blur-sm`, spacing amplio (`p-6`/`gap-4`), tipografía con jerarquía (título `text-lg font-bold`, cifras del impacto `text-3xl font-extrabold`), transición de entrada/salida (fade + scale, ~200 ms `ease-out`) usando `<Transition>` de Vue y respetando `prefers-reduced-motion` (clases `motion-reduce:transition-none`). Estado de carga del buscador con spinner discreto; estado vacío ("Sin coincidencias"). Paleta Tailwind existente (naranjas/emerald/gray); nada de Material.
- **Un solo elemento raíz.**

Estructura de referencia (esqueleto — el implementador la completa con el pulido; NO copiar como final sin refinar la estética):

```vue
<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    open: { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
});
const emit = defineEmits(['close', 'merged']);

const q = ref('');
const candidates = ref([]);
const loading = ref(false);
const selectedIds = ref(new Set());
const canonicalId = ref(null);
const preview = ref(null);
const confirming = ref(false);
const submitting = ref(false);

let debounce;
watch(q, () => { clearTimeout(debounce); debounce = setTimeout(search, 250); });
watch(() => props.open, (v) => { if (v) { reset(); search(); } });

async function search() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('empresa.productos-compra.fusionar.candidatos', { tenant: props.tenantSlug, q: q.value }));
        candidates.value = data.data;
    } finally {
        loading.value = false;
    }
}

function reset() {
    q.value = ''; candidates.value = []; selectedIds.value = new Set();
    canonicalId.value = null; preview.value = null; confirming.value = false;
}

function toggle(id) {
    const s = new Set(selectedIds.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selectedIds.value = s;
    if (!s.has(canonicalId.value)) canonicalId.value = null;
    if (canonicalId.value === null && s.size) pickDefaultCanonical();
    refreshPreview();
}

function selectAll() {
    selectedIds.value = new Set(candidates.value.map((c) => c.id));
    pickDefaultCanonical();
    refreshPreview();
}

function pickDefaultCanonical() {
    const chosen = candidates.value
        .filter((c) => selectedIds.value.has(c.id))
        .sort((a, b) => a.name.length - b.name.length)[0];
    canonicalId.value = chosen ? chosen.id : null;
}

const absorbedIds = computed(() => [...selectedIds.value].filter((id) => id !== canonicalId.value));
const canonicalName = computed(() => candidates.value.find((c) => c.id === canonicalId.value)?.name || '');

async function refreshPreview() {
    if (!canonicalId.value || absorbedIds.value.length === 0) { preview.value = null; return; }
    const { data } = await window.axios.post(route('empresa.productos-compra.fusionar.preview', props.tenantSlug), {
        canonical_id: canonicalId.value, absorbed_ids: absorbedIds.value,
    });
    preview.value = data;
}

function submit() {
    submitting.value = true;
    router.post(route('empresa.productos-compra.fusionar', props.tenantSlug), {
        canonical_id: canonicalId.value, absorbed_ids: absorbedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { emit('merged'); emit('close'); },
        onFinish: () => { submitting.value = false; confirming.value = false; },
    });
}
</script>

<template>
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0"
        leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
            @click.self="emit('close')">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl motion-reduce:transition-none">
                <!-- Cabecera, buscador, lista de candidatos, selector de canónica,
                     panel de impacto, banner de unidades, footer con confirmación.
                     Aplicar aquí el pulido iOS descrito arriba. -->
            </div>
        </div>
    </Transition>
</template>
```

- [ ] **Step 2: Verificar que compila**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Compras/FusionarProductosModal.vue
git commit -m "feat(compras): modal de fusión de productos pulido estilo iOS"
```

---

### Task 6: Integrar el botón y el modal en la pantalla

**Files:**
- Modify: `resources/js/Components/Compras/PurchaseProductsManager.vue`
- Modify: `resources/js/Pages/Empresa/ProductosCompra/Index.vue`
- Modify: `resources/js/Pages/Sucursal/ProductosCompra/Index.vue`

- [ ] **Step 1: Añadir prop `canMerge`, estado y modal al manager**

En `resources/js/Components/Compras/PurchaseProductsManager.vue`:

1. Import (junto a los otros de `@/Components/Compras/...`):

```js
import FusionarProductosModal from '@/Components/Compras/FusionarProductosModal.vue';
```

2. En `defineProps`, tras `canDelete`:

```js
    canMerge: { type: Boolean, default: false },
```

3. En el `<script setup>`, un ref para abrir el modal (junto a los otros `ref`):

```js
const showMerge = ref(false);
const onMerged = () => router.reload({ only: ['products', 'stats'] });
```

4. En la "Barra de herramientas", junto al botón "+ Nuevo producto" (que hoy tiene `class="ml-auto ..."`), quitar el `ml-auto` de ese botón y envolver ambos botones en un contenedor con `ml-auto flex gap-2`, agregando el de fusión **solo si `canMerge`**:

```html
                <div class="ml-auto flex gap-2">
                    <button v-if="canMerge" @click="showMerge = true"
                        class="rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        Fusionar duplicados
                    </button>
                    <button @click="openCreate"
                        class="rounded-xl bg-gradient-to-r from-orange-500 to-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-orange-600 hover:to-red-700">
                        + Nuevo producto
                    </button>
                </div>
```

5. Antes del cierre del template (junto a los otros modales/drawers del manager), montar:

```html
        <FusionarProductosModal :open="showMerge" :tenant-slug="slug" @close="showMerge = false" @merged="onMerged" />
```

- [ ] **Step 2: Pasar `can-merge` desde las páginas**

En `resources/js/Pages/Empresa/ProductosCompra/Index.vue`, en el `<PurchaseProductsManager ... />`, añadir tras `:can-delete="true"`:

```html
            :can-merge="true"
```

En `resources/js/Pages/Sucursal/ProductosCompra/Index.vue`, tras `:can-delete="false"`:

```html
            :can-merge="false"
```

- [ ] **Step 3: Verificar build**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Compras/PurchaseProductsManager.vue resources/js/Pages/Empresa/ProductosCompra/Index.vue resources/js/Pages/Sucursal/ProductosCompra/Index.vue
git commit -m "feat(compras): botón Fusionar duplicados en productos de compra (solo empresa)"
```

---

### Task 7: Documentación

**Files:**
- Modify: `docs/modulos/compras.md`
- Modify: `docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md`

- [ ] **Step 1: Documentar la fusión en el doc vivo**

En `docs/modulos/compras.md`, en la sección de productos de compra (buscar el heading que describe el catálogo de productos de compra; si no hay una subsección clara, añadir esta tras la descripción del CRUD del catálogo):

```markdown
### Fusión de productos de compra duplicados (2026-07-15)

Solo **admin-empresa**. En la pantalla Productos de compra, el botón **"Fusionar
duplicados"** abre un modal (`Components/Compras/FusionarProductosModal.vue`):
buscas las fichas duplicadas, las seleccionas, eliges la **canónica** y fusionas.
La lógica vive en `App\Services\Purchases\PurchaseProductMergeService`:

- Reapunta las `purchase_items` de las fichas absorbidas a la canónica.
- Normaliza el `concept` de cada línea al nombre canónico y **mueve el dato
  variable a `notes`** (ej. "Canal de res 111" → concept "Canal de res", nota
  "111"). Si el texto viejo no era un sufijo limpio del canónico, se preserva
  completo en la nota; nunca se pierde información.
- Da de baja (soft-delete) las fichas absorbidas.
- Registra un evento de auditoría `merged` sobre la canónica.

Endpoints (solo empresa): `GET productos-compra/fusionar/candidatos`,
`POST productos-compra/fusionar/preview` (impacto sin ejecutar),
`POST productos-compra/fusionar`. Sucursal y hub no la tienen. Sin migraciones.
La **prevención** de nuevos duplicados (sugerir producto existente al capturar,
nota por línea, IA) es trabajo aparte.
```

- [ ] **Step 2: Actualizar el Estado del spec**

En `docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md` reemplazar:

```markdown
**Estado:** Aprobado — pendiente de plan
```

por:

```markdown
**Estado:** Implementado (2026-07-15) — ver docs/modulos/compras.md § Fusión de productos de compra duplicados
```

- [ ] **Step 3: Commit**

```bash
git add docs/modulos/compras.md docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md
git commit -m "docs(compras): fusión de productos duplicados; spec a Implementado"
```

---

### Task 8: Verificación final y PR

- [ ] **Step 1: Suite completa de la fusión + build**

Run: `vendor/bin/sail artisan test --compact tests/Unit/PurchaseProductMergeNormalizationTest.php tests/Unit/AuditEventMergedTest.php tests/Feature/Compras/PurchaseProductMergeServiceTest.php tests/Feature/Compras/PurchaseProductMergeTest.php`
Expected: todos PASS.

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 2: Regresión del catálogo existente (no rompimos nada)**

Run: `vendor/bin/sail artisan test --compact tests/Feature/Compras/PurchaseProductCatalogTest.php`
Expected: todos PASS.

- [ ] **Step 3: Push y PR**

```bash
git push -u origin feat/productos-compra-fusion
gh pr create --base main --head feat/productos-compra-fusion \
  --title "feat(compras): fusión de productos de compra duplicados (solo empresa)" \
  --body "Implementa docs/superpowers/specs/2026-07-15-productos-compra-fusion-design.md. Fusiona fichas duplicadas del catálogo reapuntando el historial al canónico, normalizando concept y moviendo el dato variable a la nota de la línea; soft-delete de absorbidos. Solo admin-empresa. Sin migraciones. Modal pulido estilo iOS. La prevención de nuevos duplicados va en spec aparte."
```

- [ ] **Step 4: QA manual (entorno del usuario)**

1. `admin@eltoro.test`: en Productos de compra aparece "Fusionar duplicados"; buscar un término, seleccionar varias fichas, elegir canónica, ver el panel de impacto, confirmar; las líneas se reapuntan y las fichas absorbidas desaparecen de la lista.
2. `sucursal@eltoro.test`: el botón "Fusionar duplicados" NO aparece.
3. Verificar en una compra histórica que el concept quedó normalizado y el número está en la nota de la línea.
```
