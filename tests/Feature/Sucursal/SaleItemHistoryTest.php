<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Services\SaleItemEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleItemHistoryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_endpoint_returns_changes_ordered_newest_first_and_survives_delete(): void
    {
        $product = $this->makeProduct(['price' => 100, 'sale_mode' => 'piece', 'unit_type' => 'pieza']);
        $sale = $this->makeCompletedSale([
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'total' => 100,
            'locked_by' => $this->adminSucursal->id,
            'locked_at' => now(),
        ], [['product_id' => $product->id, 'unit_price' => 100, 'quantity' => 1]]);

        $sale->refresh();
        $editor = app(SaleItemEditor::class);
        $item = $sale->items->first();

        $editor->update($sale, $item, ['quantity' => 2, 'unit_price' => 100], 'Ajuste', $this->adminSucursal);
        $editor->remove($sale, $item->fresh(), 'Producto agotado', $this->adminSucursal);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.items.history', [$this->tenant->slug, $sale->id]));

        $response->assertOk();
        $changes = $response->json('changes');
        $this->assertCount(2, $changes);
        // Más nuevo primero: removed antes que updated.
        $this->assertSame('removed', $changes[0]['event']);
        $this->assertSame('updated', $changes[1]['event']);
        // El registro de removed conserva el snapshot en `before` aunque el item esté soft-deleted.
        $this->assertNotNull($changes[0]['before']);
        $this->assertSame($item->product_name, $changes[0]['before']['product_name']);
    }

    public function test_endpoint_forbids_users_from_another_branch(): void
    {
        $product = $this->makeProduct(['price' => 100, 'sale_mode' => 'piece', 'unit_type' => 'pieza']);
        $sale = $this->makeCompletedSale([
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
        ], [['product_id' => $product->id, 'unit_price' => 100, 'quantity' => 1]]);

        // Admin de otra sucursal sin acceso.
        $otherAdmin = $this->makeUser('admin2@test.local', 'admin-sucursal', $this->secondBranch->id);

        $this->actingAs($otherAdmin)
            ->getJson(route('sucursal.workbench.items.history', [$this->tenant->slug, $sale->id]))
            ->assertForbidden();
    }
}
