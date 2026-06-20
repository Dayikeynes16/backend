<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSale(int $branchId, SaleStatus $status, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => $status,
        ]);
    }

    public function test_index_lists_only_active_and_pending_of_own_branch(): void
    {
        $this->makeSale($this->branch->id, SaleStatus::Active);
        $this->makeSale($this->branch->id, SaleStatus::Pending);
        $this->makeSale($this->branch->id, SaleStatus::Completed);
        $this->makeSale($this->secondBranch->id, SaleStatus::Active);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/sales')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_show_returns_sale_with_items_and_payments(): void
    {
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.folio', $sale->folio);
    }

    public function test_show_forbids_other_branch_sale(): void
    {
        $sale = $this->makeSale($this->secondBranch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertStatus(404);
    }

    public function test_show_exposes_enabled_payment_methods_and_status_label(): void
    {
        // Sucursal con solo efectivo y tarjeta habilitados.
        $this->branch->forceFill(['payment_methods_enabled' => ['cash', 'card']])->save();
        $sale = $this->makeSale($this->branch->id, SaleStatus::Pending);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('payment_methods', ['cash', 'card'])
            ->assertJsonPath('data.status_label', 'Pendiente');
    }

    public function test_show_includes_item_unit_fields(): void
    {
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Pierna de cerdo',
            'unit_type' => 'kg',
            'quantity_unit' => 'kg',
            'quantity' => 1.25,
            'unit_price' => 80,
            'subtotal' => 100,
        ]);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_type', 'kg')
            ->assertJsonPath('data.items.0.quantity_unit', 'kg')
            ->assertJsonPath('data.items.0.quantity', 1.25);
    }

    public function test_show_includes_assigned_customer(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'phone' => '5551234',
            'status' => 'active',
        ]);
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);
        $sale->forceFill(['customer_id' => $customer->id])->save();

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Cliente Fiado')
            ->assertJsonPath('data.customer.phone', '5551234');
    }
}
