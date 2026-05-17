<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\SaleStatus;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleResourceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_exposes_linked_order_id_field(): void
    {
        $sale = $this->makeSale(['linked_order_id' => null]);

        $data = SaleResource::make($sale)->resolve(Request::create('/'));

        $this->assertArrayHasKey('linked_order_id', $data);
        $this->assertNull($data['linked_order_id']);
    }

    public function test_returns_linked_order_id_when_set(): void
    {
        $web = $this->makeSale(['origin' => 'web', 'status' => SaleStatus::Pending->value, 'folio' => 'WEB-1']);
        $scale = $this->makeSale(['origin' => 'api', 'folio' => 'API-1', 'linked_order_id' => $web->id]);

        $data = SaleResource::make($scale)->resolve(Request::create('/'));

        $this->assertSame($web->id, $data['linked_order_id']);
    }

    public function test_linked_order_field_uses_when_loaded(): void
    {
        $web = $this->makeSale(['origin' => 'web', 'status' => SaleStatus::Pending->value, 'folio' => 'WEB-2']);
        $scale = $this->makeSale(['origin' => 'api', 'folio' => 'API-2', 'linked_order_id' => $web->id]);

        // Sin loadear la relación, la key 'linked_order' no debe aparecer (whenLoaded la omite)
        $withoutLoad = SaleResource::make($scale)->resolve(Request::create('/'));
        $this->assertArrayNotHasKey('linked_order', $withoutLoad);

        // Con la relación cargada, debe serializar id/folio/status
        $scale->load('linkedOrder');
        $withLoad = SaleResource::make($scale)->resolve(Request::create('/'));
        $this->assertArrayHasKey('linked_order', $withLoad);
        $this->assertSame($web->id, $withLoad['linked_order']['id']);
        $this->assertSame('WEB-2', $withLoad['linked_order']['folio']);
        $this->assertSame('pending', $withLoad['linked_order']['status']);
    }

    public function test_fulfilled_by_serializes_when_relation_is_loaded(): void
    {
        $web = $this->makeSale(['origin' => 'web', 'status' => SaleStatus::Fulfilled->value, 'folio' => 'WEB-3']);
        $scale = $this->makeSale(['origin' => 'api', 'folio' => 'API-3', 'linked_order_id' => $web->id]);

        $web->load('fulfilledBy');
        $data = SaleResource::make($web)->resolve(Request::create('/'));

        $this->assertArrayHasKey('fulfilled_by', $data);
        $this->assertSame($scale->id, $data['fulfilled_by']['id']);
        $this->assertSame('API-3', $data['fulfilled_by']['folio']);
        $this->assertSame('active', $data['fulfilled_by']['status']);
    }

    public function test_linked_order_is_null_when_loaded_but_relation_empty(): void
    {
        $sale = $this->makeSale(['linked_order_id' => null]);
        $sale->load('linkedOrder');

        $data = SaleResource::make($sale)->resolve(Request::create('/'));

        $this->assertArrayHasKey('linked_order', $data);
        $this->assertNull($data['linked_order']);
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
