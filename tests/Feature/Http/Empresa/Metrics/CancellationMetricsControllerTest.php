<?php

namespace Tests\Feature\Http\Empresa\Metrics;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CancellationMetricsControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCancelledSale(int $branchId, array $attrs = []): Sale
    {
        $cancelledAt = $attrs['cancelled_at'] ?? '2026-04-15 10:00:00';
        unset($attrs['cancelled_at']);

        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Cancelled->value,
            'cancelled_by' => $this->adminSucursal->id,
        ], $attrs));

        $sale->forceFill(['cancelled_at' => $cancelledAt])->save();

        return $sale;
    }

    public function test_admin_empresa_sees_all_branches_when_no_branch_filter(): void
    {
        $this->makeCancelledSale($this->branch->id, ['total' => 100]);
        $this->makeCancelledSale($this->secondBranch->id, ['total' => 250]);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.cancelaciones', $this->tenant->slug).'?from=2026-04-01&to=2026-04-30')
            ->assertInertia(fn ($page) => $page
                ->component('Empresa/Metricas/Cancelaciones')
                ->where('data.summary.current.cancelled_count', 2)
                ->where('data.summary.current.cancelled_amount', 350)
                ->has('data.by_branch', 2)
                ->has('branches')
            );
    }

    public function test_admin_empresa_can_filter_by_branch(): void
    {
        $this->makeCancelledSale($this->branch->id, ['total' => 100]);
        $this->makeCancelledSale($this->secondBranch->id, ['total' => 999]);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.cancelaciones', $this->tenant->slug).'?from=2026-04-01&to=2026-04-30&branch_id='.$this->branch->id)
            ->assertInertia(fn ($page) => $page
                ->where('data.summary.current.cancelled_count', 1)
                ->where('data.summary.current.cancelled_amount', 100)
                ->where('selected_branch_id', $this->branch->id)
            );
    }
}
