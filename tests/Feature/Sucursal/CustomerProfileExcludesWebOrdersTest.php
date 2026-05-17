<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Garantiza que el perfil del cliente NO doble-cuente pedidos web pendientes ni
 * cumplidos. El dinero real vive en la venta de báscula vinculada; el pedido web
 * es solo referencia.
 */
class CustomerProfileExcludesWebOrdersTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'María López',
            'phone' => '+529931234567',
            'status' => 'active',
        ]);
    }

    public function test_stats_endpoint_excludes_fulfilled_web_order_from_total_owed(): void
    {
        // Venta real de báscula cobrada — la única que debería contar
        $scale = $this->makeSale([
            'origin' => 'api',
            'status' => SaleStatus::Completed->value,
            'total' => 200,
            'amount_paid' => 200,
            'amount_pending' => 0,
        ]);
        // Pedido web fulfilled — NO debe contar en deuda
        $webFulfilled = $this->makeSale([
            'origin' => 'web',
            'status' => SaleStatus::Fulfilled->value,
            'total' => 1749.97,
            'amount_paid' => 0,
            'amount_pending' => 1749.97,
        ]);
        // Pedido web pending — NO debe contar
        $this->makeSale([
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
            'total' => 929.97,
            'amount_paid' => 0,
            'amount_pending' => 929.97,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.clientes.stats', [$this->tenant->slug, $this->customer->id]));

        $response->assertOk();
        $this->assertSame(1, $response->json('sale_count'), 'Solo la venta real debe contar');
        $this->assertEquals(200, $response->json('total_spent'));
        $this->assertEquals(0, $response->json('total_owed'), 'Deuda debe excluir pedidos web pending/fulfilled');
        $this->assertEquals(200, $response->json('total_paid'));
    }

    public function test_payments_endpoint_excludes_web_orders_from_pending_sales(): void
    {
        // Venta normal con saldo pendiente — debe aparecer
        $pendingReal = $this->makeSale([
            'origin' => 'api',
            'status' => SaleStatus::Completed->value,
            'total' => 500,
            'amount_paid' => 0,
            'amount_pending' => 500,
        ]);
        // Pedido web fulfilled con amount_pending — NO debe aparecer
        $this->makeSale([
            'origin' => 'web',
            'status' => SaleStatus::Fulfilled->value,
            'total' => 1749.97,
            'amount_paid' => 0,
            'amount_pending' => 1749.97,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.clientes.pagos', [$this->tenant->slug, $this->customer->id]));

        $response->assertOk();
        $pendingSales = $response->json('pending_sales');
        $this->assertCount(1, $pendingSales);
        $this->assertSame($pendingReal->id, $pendingSales[0]['id']);
        $this->assertEquals(500, $response->json('total_owed'));
    }

    public function test_history_endpoint_excludes_web_orders(): void
    {
        $real = $this->makeSale([
            'origin' => 'api',
            'status' => SaleStatus::Completed->value,
            'total' => 200,
            'amount_paid' => 200,
            'amount_pending' => 0,
        ]);
        $this->makeSale([
            'origin' => 'web',
            'status' => SaleStatus::Fulfilled->value,
            'total' => 1749.97,
            'amount_paid' => 0,
            'amount_pending' => 1749.97,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.clientes.historial', [$this->tenant->slug, $this->customer->id]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($real->id, $data[0]['id']);
    }

    public function test_customers_index_does_not_count_web_orders_in_total_owed(): void
    {
        // Venta real cobrada
        $this->makeSale([
            'origin' => 'api',
            'status' => SaleStatus::Completed->value,
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
        ]);
        // Pedido web fulfilled con amount_pending
        $this->makeSale([
            'origin' => 'web',
            'status' => SaleStatus::Fulfilled->value,
            'total' => 500,
            'amount_paid' => 0,
            'amount_pending' => 500,
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.clientes.index', $this->tenant->slug));

        $response->assertOk();
        $customers = $response->viewData('page')['props']['customers']['data'] ?? [];
        $mine = collect($customers)->firstWhere('id', $this->customer->id);
        $this->assertNotNull($mine);
        $this->assertEquals(0, (float) $mine['total_owed'], 'total_owed del listado no debe contar pedidos web fulfilled');
        $this->assertSame(1, (int) $mine['sales_count'], 'sales_count solo cuenta la venta real');
    }

    private function makeSale(array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'folio' => 'F-'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
        ], $attrs));

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Item',
            'unit_type' => 'kg',
            'quantity' => 1,
            'unit_price' => $sale->total,
            'original_unit_price' => $sale->total,
            'subtotal' => $sale->total,
        ]);

        return $sale;
    }
}
