<?php

namespace Tests\Unit;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleScopeAccountableTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    /**
     * Matriz origin × status. La regla es: excluir si origin='web' AND
     * status IN (pending, fulfilled). El resto sí cuenta.
     *
     * @return array<string, array{0:string, 1:SaleStatus, 2:bool}>
     */
    public static function originStatusMatrix(): array
    {
        return [
            // origin api/admin — siempre cuentan
            'api active' => ['api', SaleStatus::Active, true],
            'api pending' => ['api', SaleStatus::Pending, true],
            'api completed' => ['api', SaleStatus::Completed, true],
            'api cancelled' => ['api', SaleStatus::Cancelled, true],
            'admin active' => ['admin', SaleStatus::Active, true],
            'admin completed' => ['admin', SaleStatus::Completed, true],
            'admin cancelled' => ['admin', SaleStatus::Cancelled, true],
            // origin web — solo cuentan cancelled (rechazado) y completed (no debería pasar)
            'web pending EXCLUDED' => ['web', SaleStatus::Pending, false],
            'web fulfilled EXCLUDED' => ['web', SaleStatus::Fulfilled, false],
            'web cancelled' => ['web', SaleStatus::Cancelled, true],
            'web completed (edge)' => ['web', SaleStatus::Completed, true],
            'web active (edge)' => ['web', SaleStatus::Active, true],
        ];
    }

    #[DataProvider('originStatusMatrix')]
    public function test_scope_includes_or_excludes_based_on_origin_and_status(string $origin, SaleStatus $status, bool $shouldBeIncluded): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'F-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => $origin,
            'status' => $status->value,
        ]);

        $isIncluded = Sale::accountable()->where('id', $sale->id)->exists();

        $this->assertSame(
            $shouldBeIncluded,
            $isIncluded,
            'Esperaba '.($shouldBeIncluded ? 'incluir' : 'excluir')." una venta {$origin}+{$status->value}"
        );
    }

    public function test_scope_composes_with_other_constraints(): void
    {
        $included = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'INC-1',
            'payment_method' => 'cash',
            'total' => 500,
            'amount_paid' => 500,
            'amount_pending' => 0,
            'origin' => 'api',
            'status' => SaleStatus::Completed->value,
        ]);
        // Excluido por el scope (web pending)
        Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'EXC-1',
            'payment_method' => 'cash',
            'total' => 500,
            'amount_paid' => 0,
            'amount_pending' => 500,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);

        $totalCounted = (float) Sale::accountable()
            ->where('branch_id', $this->branch->id)
            ->sum('total');

        $this->assertEquals(500, $totalCounted);
        $this->assertContains($included->id, Sale::accountable()->pluck('id')->all());
    }
}
