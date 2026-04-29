<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomersIndexSummaryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeCustomer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente '.uniqid(),
            'phone' => '993'.rand(1000000, 9999999),
            'status' => 'active',
        ], $attrs));
    }

    private function makeSale(Customer $customer, array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $customer->branch_id,
            'user_id' => $this->cajero->id,
            'customer_id' => $customer->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Pending,
        ], $attrs));
    }

    public function test_summary_counts_active_and_inactive_correctly(): void
    {
        $this->makeCustomer(['status' => 'active']);
        $this->makeCustomer(['status' => 'active']);
        $this->makeCustomer(['status' => 'inactive']);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.clientes.index', $this->tenant->slug));
        $summary = $response->viewData('page')['props']['customersSummary'];

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['active']);
        $this->assertSame(1, $summary['inactive']);
    }

    public function test_summary_aggregates_total_debt_excluding_cancelled(): void
    {
        $c1 = $this->makeCustomer();
        $c2 = $this->makeCustomer();

        $this->makeSale($c1, ['amount_pending' => 200]);
        $this->makeSale($c1, ['amount_pending' => 300]);
        $this->makeSale($c2, ['amount_pending' => 500]);
        // Cancelled NO debe contar
        $this->makeSale($c2, ['amount_pending' => 9999, 'status' => SaleStatus::Cancelled]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.clientes.index', $this->tenant->slug));
        $summary = $response->viewData('page')['props']['customersSummary'];

        $this->assertSame(1000.0, $summary['total_debt']);
        $this->assertSame(2, $summary['customers_with_debt']);
    }

    public function test_each_customer_carries_total_owed(): void
    {
        $debtor = $this->makeCustomer(['name' => 'Debtor']);
        $clean = $this->makeCustomer(['name' => 'Clean']);

        $this->makeSale($debtor, ['amount_pending' => 750]);
        $this->makeSale($debtor, ['amount_pending' => 250]);
        // Clean: una venta pagada
        $this->makeSale($clean, ['amount_pending' => 0, 'amount_paid' => 100, 'status' => SaleStatus::Completed]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.clientes.index', $this->tenant->slug));
        $customers = collect($response->viewData('page')['props']['customers'])->keyBy('name');

        $this->assertEquals(1000.0, (float) $customers['Debtor']['total_owed']);
        $this->assertEquals(0.0, (float) ($customers['Clean']['total_owed'] ?? 0));
    }

    public function test_summary_is_not_filtered_by_search_or_status(): void
    {
        $this->makeCustomer(['name' => 'Activo Uno', 'status' => 'active']);
        $this->makeCustomer(['name' => 'Inactivo Uno', 'status' => 'inactive']);
        $this->makeCustomer(['name' => 'Otro', 'status' => 'active']);

        $this->actingAs($this->adminSucursal);
        // Filtrar por search="Activo" deja la lista en 1 cliente, pero el resumen
        // debe seguir reportando los 3 totales de la cartera.
        $response = $this->get(route('sucursal.clientes.index', $this->tenant->slug).'?search=Activo');
        $page = $response->viewData('page')['props'];

        $this->assertCount(1, $page['customers']);
        $this->assertSame(3, $page['customersSummary']['total']);
        $this->assertSame(2, $page['customersSummary']['active']);
        $this->assertSame(1, $page['customersSummary']['inactive']);
    }
}
