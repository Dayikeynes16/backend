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
