<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    public function test_dashboard_reports_today_metrics_and_open_shift(): void
    {
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->cajero->id,
            'opened_at' => now()->subHour(), 'opening_amount' => 100,
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'folio' => 'S-1', 'payment_method' => 'cash', 'total' => 200,
            'amount_paid' => 200, 'amount_pending' => 0, 'origin' => 'api', 'status' => SaleStatus::Completed,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_name' => 'Bistec', 'unit_type' => 'kg',
            'quantity' => 1, 'unit_price' => 200, 'subtotal' => 200,
        ]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 200]);

        $res = $this->withToken($this->token())
            ->getJson('/api/v1/hub/dashboard')
            ->assertOk();

        $this->assertSame(1, $res->json('today.sales_count'));
        $this->assertEquals(200, $res->json('today.sales_total'));
        $this->assertEquals(200, $res->json('by_method.cash'));
        $this->assertSame('S-1', $res->json('recent_sales.0.folio'));
        $this->assertSame('Bistec', $res->json('top_products.0.product_name'));
        $this->assertNotNull($res->json('shift'));
        $this->assertTrue($res->json('shift.is_open'));
    }

    public function test_dashboard_without_shift_is_null(): void
    {
        $res = $this->withToken($this->token())
            ->getJson('/api/v1/hub/dashboard')
            ->assertOk();

        $this->assertNull($res->json('shift'));
        $this->assertSame(0, $res->json('today.sales_count'));
    }

    public function test_dashboard_reports_comparative_and_hourly_series(): void
    {
        // Venta de hoy (300) y de ayer (100) → delta +200%.
        Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'folio' => 'S-HOY', 'payment_method' => 'cash', 'total' => 300,
            'amount_paid' => 300, 'amount_pending' => 0, 'origin' => 'api', 'status' => SaleStatus::Completed,
        ]);
        $ayer = Sale::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'folio' => 'S-AYER', 'payment_method' => 'cash', 'total' => 100,
            'amount_paid' => 100, 'amount_pending' => 0, 'origin' => 'api', 'status' => SaleStatus::Completed,
        ]);
        Sale::where('id', $ayer->id)->update(['created_at' => now()->subDay()]);

        $res = $this->withToken($this->token())
            ->getJson('/api/v1/hub/dashboard')
            ->assertOk();

        $this->assertEquals(300, $res->json('today.sales_total'));
        $this->assertEquals(100, $res->json('today.sales_total_yesterday'));
        $this->assertEquals(200.0, $res->json('today.sales_delta_pct'));
        $this->assertEquals(300, $res->json('today.avg_ticket'));
        $this->assertCount(24, $res->json('hourly'));
        $this->assertCount(24, $res->json('hourly_yesterday'));
        $this->assertIsArray($res->json('recent_shifts'));
        $this->assertArrayHasKey('expenses_delta_pct', $res->json('today'));
        $this->assertArrayHasKey('top_expense_categories', $res->json());
    }

    public function test_requires_hub_role(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/dashboard')
            ->assertStatus(403);
    }
}
