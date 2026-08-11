<?php

namespace Tests\Feature\Console;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * `sales:link-orphan-phones` recupera los teléfonos que quedaron sueltos en
 * `sales.contact_phone` de cuando capturarlos no creaba cliente.
 *
 * No usa `AssignCustomerToSale` a propósito: ese servicio recalcula precios
 * preferenciales y podría alterar el total de ventas históricas ya cobradas.
 * Aquí solo se rellena `customer_id`.
 */
class LinkOrphanSalePhonesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_dry_run_no_crea_ni_asocia_nada(): void
    {
        $sale = $this->orphanSale('+529939999999');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'true'])->assertSuccessful();

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_asocia_la_venta_al_cliente_existente(): void
    {
        $customer = $this->makeCustomer('9931234567');
        $sale = $this->orphanSale('+529931234567');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_crea_cliente_sin_nombre_para_telefonos_nuevos(): void
    {
        $sale = $this->orphanSale('+529939999999');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $customer = $sale->fresh()->customer;

        $this->assertNotNull($customer);
        $this->assertTrue($customer->name_pending);
        $this->assertSame('Cliente 993 999 9999', $customer->name);
    }

    public function test_varias_ventas_del_mismo_numero_comparten_cliente(): void
    {
        $una = $this->orphanSale('+529939999999');
        $otra = $this->orphanSale('+529939999999');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame($una->fresh()->customer_id, $otra->fresh()->customer_id);
    }

    public function test_omite_ventas_canceladas(): void
    {
        $sale = $this->orphanSale('+529939999999', ['status' => SaleStatus::Cancelled]);

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertNull($sale->fresh()->customer_id);
    }

    public function test_no_toca_ventas_que_ya_tienen_cliente(): void
    {
        $otro = $this->makeCustomer('9938888888');
        $sale = $this->orphanSale('+529939999999', ['customer_id' => $otro->id]);

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $this->assertSame($otro->id, $sale->fresh()->customer_id);
    }

    /** El total de una venta histórica no puede cambiar por asociarle un cliente. */
    public function test_no_altera_importes_ni_estado(): void
    {
        $sale = $this->orphanSale('+529939999999', [
            'total' => 250,
            'amount_paid' => 250,
            'amount_pending' => 0,
            'status' => SaleStatus::Completed,
        ]);

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])->assertSuccessful();

        $fresh = $sale->fresh();
        $this->assertSame('250.00', $fresh->total);
        $this->assertSame('250.00', $fresh->amount_paid);
        $this->assertSame('0.00', $fresh->amount_pending);
        $this->assertSame(SaleStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->customer_id);
    }

    public function test_omite_telefonos_que_no_lo_son(): void
    {
        $sale = $this->orphanSale('+8556');

        $this->artisan('sales:link-orphan-phones', ['--dry-run' => 'false'])
            ->expectsOutputToContain('no parece un teléfono')
            ->assertSuccessful();

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_puede_acotarse_a_una_sucursal(): void
    {
        $otra = $this->orphanSale('+529939999999', ['branch_id' => $this->secondBranch->id]);

        $this->artisan('sales:link-orphan-phones', [
            '--dry-run' => 'false',
            '--branch' => $this->branch->id,
        ])->assertSuccessful();

        $this->assertNull($otra->fresh()->customer_id);
    }

    private function makeCustomer(string $phone): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente existente',
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    private function orphanSale(string $phone, array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
            'contact_phone' => $phone,
        ], $attrs));
    }
}
