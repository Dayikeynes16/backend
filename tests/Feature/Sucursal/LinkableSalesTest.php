<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class LinkableSalesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_lists_only_active_scale_sales_without_link(): void
    {
        $eligible = $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value]);
        // Excluidas: web, ya vinculada, no active
        $this->makeSale(['origin' => 'web', 'status' => SaleStatus::Pending->value]);
        $alreadyLinkedTarget = $this->makeSale(['origin' => 'web', 'status' => SaleStatus::Pending->value]);
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value, 'linked_order_id' => $alreadyLinkedTarget->id]);
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Completed->value]);
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Cancelled->value]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.linkable-sales', $this->tenant->slug));

        $response->assertOk();
        $sales = $response->json('sales');
        $this->assertCount(1, $sales);
        $this->assertSame($eligible->id, $sales[0]['id']);
    }

    public function test_respects_branch_scope(): void
    {
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value, 'branch_id' => $this->branch->id]);
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value, 'branch_id' => $this->secondBranch->id]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.linkable-sales', $this->tenant->slug));

        $this->assertCount(1, $response->json('sales'));
    }

    public function test_cajero_endpoint_returns_same_shape(): void
    {
        $this->makeSale(['origin' => 'api', 'status' => SaleStatus::Active->value]);

        $response = $this->actingAs($this->cajero)
            ->getJson(route('caja.linkable-sales', $this->tenant->slug));

        $response->assertOk();
        $this->assertCount(1, $response->json('sales'));
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'F-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));
    }
}
