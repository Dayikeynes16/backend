<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerShowControllerTest extends TestCase
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
            'name' => 'Cliente Test',
            'phone' => '5550000'.random_int(100, 999),
            'status' => 'active',
        ], $attrs));
    }

    public function test_show_returns_customer_with_seed_stats_for_admin(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['price' => 100]);

        // Una venta completada y otra con saldo pendiente.
        $this->makeCompletedSale([
            'customer_id' => $customer->id,
            'total' => 200,
            'amount_paid' => 200,
            'amount_pending' => 0,
        ], [['product_id' => $product->id, 'unit_price' => 100, 'quantity' => 2]]);
        $this->makeCompletedSale([
            'customer_id' => $customer->id,
            'total' => 150,
            'amount_paid' => 50,
            'amount_pending' => 100,
        ], [['product_id' => $product->id, 'unit_price' => 75, 'quantity' => 2]]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.clientes.show', [$this->tenant->slug, $customer->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Clientes/Show')
                ->where('customer.id', $customer->id)
                ->where('customer.name', $customer->name)
                ->where('statsSeed.sale_count', 2)
                ->where('statsSeed.total_spent', 350)
                ->where('statsSeed.total_owed', 100)
                ->where('statsSeed.pending_sales_count', 1)
                ->has('customer.prices')
                // T8: flags de comprobantes de pago expuestos al frontend del
                // cobro global; por defecto (migración) ambos son false.
                ->where('paymentReceiptsEnabled', false)
                ->where('paymentReceiptsRequired', false)
            );
    }

    public function test_show_exposes_payment_receipts_flags_when_branch_enables_them(): void
    {
        $this->branch->forceFill(['payment_receipts_enabled' => true, 'payment_receipts_required' => true])->save();
        $customer = $this->makeCustomer();

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.clientes.show', [$this->tenant->slug, $customer->id]))
            ->assertInertia(fn ($page) => $page
                ->where('paymentReceiptsEnabled', true)
                ->where('paymentReceiptsRequired', true)
            );
    }

    public function test_show_forbidden_for_customer_of_another_branch(): void
    {
        $customer = $this->makeCustomer(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.clientes.show', [$this->tenant->slug, $customer->id]))
            ->assertForbidden();
    }

    public function test_show_cancelled_sales_excluded_from_seed(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeCompletedSale([
            'customer_id' => $customer->id,
            'status' => SaleStatus::Cancelled->value,
            'total' => 500,
            'amount_paid' => 0,
            'amount_pending' => 0,
            'cancelled_at' => now(),
        ], [['product_id' => $product->id, 'unit_price' => 250, 'quantity' => 2]]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.clientes.show', [$this->tenant->slug, $customer->id]))
            ->assertInertia(fn ($page) => $page
                ->where('statsSeed.sale_count', 0)
                ->where('statsSeed.total_spent', 0)
            );
    }
}
