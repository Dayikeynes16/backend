<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Sale;
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
}
