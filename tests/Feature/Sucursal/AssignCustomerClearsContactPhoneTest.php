<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Cuando se asigna un cliente a una venta POS, el `contact_phone` capturado
 * manualmente debe limpiarse para evitar tener un dato fantasma que ya no
 * se usa (la prioridad pasa a `customer.phone`). Las ventas origen `web`
 * conservan su `contact_phone` porque ahí ese dato vino del checkout.
 */
class AssignCustomerClearsContactPhoneTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_assigning_customer_clears_contact_phone_on_pos_sale(): void
    {
        $sale = $this->makeSale(['origin' => 'admin', 'contact_phone' => '+529998887766']);
        $customer = $this->makeCustomer();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        $this->assertNull($sale->fresh()->contact_phone);
    }

    public function test_assigning_customer_keeps_contact_phone_on_web_origin(): void
    {
        $sale = $this->makeSale(['origin' => 'web', 'contact_phone' => '+529998887766']);
        $customer = $this->makeCustomer();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect();

        $this->assertSame('+529998887766', $sale->fresh()->contact_phone);
    }

    public function test_unassigning_customer_does_not_touch_contact_phone(): void
    {
        $customer = $this->makeCustomer();
        $sale = $this->makeSale([
            'origin' => 'admin',
            'customer_id' => $customer->id,
            'contact_phone' => null,
        ]);

        // Simulamos que después de asignar, alguien guardó manualmente un teléfono
        // (caso poco común pero posible). Al desasignar, no debe tocarse.
        $sale->update(['contact_phone' => '+525511112222']);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.workbench.assign-customer', [$this->tenant->slug, $sale->id]), [
                'customer_id' => null,
            ])
            ->assertRedirect();

        $this->assertSame('+525511112222', $sale->fresh()->contact_phone);
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 250,
            'amount_paid' => 0,
            'amount_pending' => 250,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ], $attrs));
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Test',
            'phone' => '5511112222',
            'status' => 'active',
        ]);
    }
}
