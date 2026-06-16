<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class HistoryApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function sale(int $branchId, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $total,
            'amount_pending' => 0,
            'origin' => 'api',
            'status' => SaleStatus::Completed,
        ]);
    }

    private function payBy(int $userId, Sale $sale): void
    {
        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $userId,
            'method' => 'cash',
            'amount' => $sale->total,
        ]);
    }

    public function test_lists_only_sales_the_user_charged_today(): void
    {
        $mine = $this->sale($this->branch->id);
        $this->payBy($this->cajero->id, $mine);

        $other = $this->sale($this->branch->id);
        $this->payBy($this->adminSucursal->id, $other); // pagada por otro usuario

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($mine->folio));
        $this->assertFalse($folios->contains($other->folio));
    }

    public function test_filters_by_date(): void
    {
        $today = $this->sale($this->branch->id);
        $this->payBy($this->cajero->id, $today);

        $old = $this->sale($this->branch->id);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->payBy($this->cajero->id, $old);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history?date='.now()->subDays(3)->toDateString())
            ->assertOk();

        $folios = collect($res->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains($old->folio));
        $this->assertFalse($folios->contains($today->folio));
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/history')
            ->assertStatus(403);
    }
}
