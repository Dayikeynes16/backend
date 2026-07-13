<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleItemApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
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

    private function itemOf(Sale $sale, float $qty = 1, float $price = 100): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->makeProduct()->id,
            'product_name' => 'Bistec',
            'unit_type' => 'kg',
            'quantity' => $qty,
            'unit_price' => $price,
            'subtotal' => round($qty * $price, 2),
        ]);
    }

    public function test_cajero_cannot_edit_items(): void
    {
        $sale = $this->activeSale();
        $item = $this->itemOf($sale);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson("/api/v1/hub/sales/{$sale->id}/items", [
                'product_id' => $this->makeProduct()->id, 'quantity' => 1, 'unit_price' => 50,
            ])
            ->assertForbidden();

        $this->withToken($this->tokenFor($this->cajero))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", ['quantity' => 2, 'unit_price' => 100])
            ->assertForbidden();

        $this->withToken($this->tokenFor($this->cajero))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", ['reason' => 'x'])
            ->assertForbidden();

        $this->withToken($this->tokenFor($this->cajero))
            ->getJson("/api/v1/hub/sales/{$sale->id}/items-history")
            ->assertForbidden();
    }

    public function test_admin_adds_item_and_totals_recalculate(): void
    {
        $sale = $this->activeSale(100);
        $this->itemOf($sale);
        $product = $this->makeProduct(['name' => 'Chuleta', 'price' => 80]);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$sale->id}/items", [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 80,
                'reason' => 'Se pesó de más',
            ])
            ->assertCreated();

        // 100 del item original + 160 del nuevo.
        $this->assertEquals(260, $res->json('data.total'));
        $this->assertCount(2, $res->json('data.items'));
        $this->assertEquals(260, $sale->refresh()->total);
    }

    public function test_admin_updates_item_quantity_and_price(): void
    {
        $sale = $this->activeSale(100);
        $item = $this->itemOf($sale, 1, 100);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [
                'quantity' => 2, 'unit_price' => 90,
            ])
            ->assertOk();

        $this->assertEquals(180, $res->json('data.total'));
        $this->assertEquals(180, $sale->refresh()->total);
    }

    public function test_update_without_changes_is_rejected(): void
    {
        $sale = $this->activeSale(100);
        $item = $this->itemOf($sale, 1, 100);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [
                'quantity' => 1, 'unit_price' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_destroy_requires_reason_and_soft_deletes(): void
    {
        $sale = $this->activeSale(100);
        $item = $this->itemOf($sale);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [])
            ->assertStatus(422);

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", ['reason' => 'Producto devuelto'])
            ->assertOk();

        $this->assertEquals(0, $res->json('data.total'));
        $this->assertSoftDeleted('sale_items', ['id' => $item->id]);
    }

    public function test_reason_is_required_when_branch_demands_it(): void
    {
        $this->branch->update(['sale_item_edit_reason_mode' => 'required']);
        $sale = $this->activeSale(100);
        $item = $this->itemOf($sale);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [
                'quantity' => 2, 'unit_price' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_completed_sale_cannot_be_edited(): void
    {
        $sale = $this->activeSale(100);
        $sale->update(['status' => SaleStatus::Completed, 'amount_paid' => 100, 'amount_pending' => 0]);
        $item = $this->itemOf($sale);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [
                'quantity' => 3, 'unit_price' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_history_lists_item_changes(): void
    {
        $sale = $this->activeSale(100);
        $item = $this->itemOf($sale);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->patchJson("/api/v1/hub/sales/{$sale->id}/items/{$item->id}", [
                'quantity' => 2, 'unit_price' => 100, 'reason' => 'Repesaje',
            ])
            ->assertOk();

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson("/api/v1/hub/sales/{$sale->id}/items-history")
            ->assertOk();

        $this->assertNotEmpty($res->json('changes'));
        $this->assertSame('Repesaje', $res->json('changes.0.reason'));
        $this->assertSame($this->adminSucursal->name, $res->json('changes.0.user.name'));
    }
}
