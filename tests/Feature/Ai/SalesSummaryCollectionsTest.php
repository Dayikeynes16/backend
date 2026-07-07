<?php

namespace Tests\Feature\Ai;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Ai\Assistant\Tools\SalesSummaryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La tool de ventas debe reportar la cobranza del periodo con la misma
 * semántica que el dashboard: pagos recibidos en el rango, separando los
 * abonos a ventas creadas en días anteriores. Sin este dato el modelo
 * inventaba respuestas ("no hay abonos") al preguntar por el desglose.
 */
class SalesSummaryCollectionsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_collections_split_same_day_vs_previous_days(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'status' => 'active',
        ]);

        $today = now()->toDateString();
        $yesterday = now()->subDay();

        // Venta a crédito creada AYER, cobrada HOY (abono a venta anterior).
        // created_at no es fillable en Sale: se fuerza tras crear.
        $creditSale = $this->makeCompletedSale([
            'customer_id' => $customer->id,
            'total' => 300,
            'amount_paid' => 0,
            'amount_pending' => 300,
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
        ]);
        $creditSale->forceFill(['created_at' => $yesterday])->saveQuietly();
        Payment::create([
            'sale_id' => $creditSale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 300,
            'created_at' => now(),
        ]);

        // Venta de HOY cobrada HOY.
        $todaySale = $this->makeCompletedSale([
            'total' => 500,
            'amount_paid' => 500,
            'amount_pending' => 0,
            'completed_at' => now(),
            'created_at' => now(),
        ]);
        Payment::create([
            'sale_id' => $todaySale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 500,
            'created_at' => now(),
        ]);

        $tool = app(SalesSummaryTool::class);
        $params = $tool->validate($this->adminSucursal, [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
        ]);
        $result = $tool->execute($this->adminSucursal, $params);
        $data = $result->data;

        $this->assertEqualsWithDelta(800.0, $data['collected_total'], 0.001);
        $this->assertEqualsWithDelta(500.0, $data['collected_from_same_day'], 0.001);
        $this->assertEqualsWithDelta(300.0, $data['collected_from_previous_days'], 0.001);

        // El resumen que lee el modelo debe incluir el desglose y la semántica.
        $this->assertStringContainsString('abonos a ventas creadas en días anteriores', $result->summary);
        $this->assertStringContainsString('300.00', $result->summary);
    }
}
