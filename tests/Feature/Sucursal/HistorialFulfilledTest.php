<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class HistorialFulfilledTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_daily_listing_includes_fulfilled_web_orders(): void
    {
        $today = now()->toDateString();
        $web = $this->makeWebOrder(['status' => SaleStatus::Fulfilled->value]);
        $this->touchCreatedAt($web, now());

        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today);

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['sales']['data'] ?? [])
            ->pluck('id')
            ->all();

        $this->assertContains($web->id, $ids, 'El pedido Fulfilled debe aparecer en el historial diario');
    }

    public function test_daily_listing_excludes_web_pending_orders(): void
    {
        $today = now()->toDateString();
        $webPending = $this->makeWebOrder(['status' => SaleStatus::Pending->value]);

        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today);

        $ids = collect($response->viewData('page')['props']['sales']['data'] ?? [])
            ->pluck('id')
            ->all();

        $this->assertNotContains($webPending->id, $ids, 'El pedido web pendiente NO debe aparecer en historial (sigue en Workbench)');
    }

    public function test_fulfilled_web_order_carries_fulfilled_by_reference(): void
    {
        $today = now()->toDateString();
        $web = $this->makeWebOrder(['status' => SaleStatus::Fulfilled->value]);
        $scale = $this->makeScaleSale(['linked_order_id' => $web->id]);
        $this->touchCreatedAt($web, now());

        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today);

        $sales = $response->viewData('page')['props']['sales']['data'] ?? [];
        $webPayload = collect($sales)->firstWhere('id', $web->id);

        $this->assertNotNull($webPayload, 'El pedido fulfilled debe estar en la respuesta');
        $this->assertSame($scale->id, $webPayload['fulfilled_by']['id'] ?? null);
        $this->assertSame($scale->folio, $webPayload['fulfilled_by']['folio'] ?? null);
    }

    private function makeWebOrder(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ], $attrs));
    }

    private function makeScaleSale(array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'API-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Carne',
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => 100,
            'original_unit_price' => 100,
            'subtotal' => 100,
        ]);

        return $sale;
    }

    private function touchCreatedAt(Sale $sale, \DateTimeInterface $at): void
    {
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $at]);
    }
}
