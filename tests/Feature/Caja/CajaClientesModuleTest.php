<?php

namespace Tests\Feature\Caja;

use App\Enums\SaleStatus;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Módulo Clientes del cajero: gateado por `cashier_customers_enabled`, expone
 * cartera, alta/edición y cobro global FIFO — pero nunca precios preferenciales
 * (descuentos), baja de clientes ni cancelación de cobros.
 */
class CajaClientesModuleTest extends TestCase
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

    /**
     * `created_at` no es mass-assignable en Sale, así que se fija después de
     * crear: el orden FIFO depende de él y no puede quedar al azar.
     */
    private function makePendingSale(Customer $customer, float $total, ?Carbon $createdAt = null): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $customer->branch_id,
            'user_id' => $this->cajero->id,
            'customer_id' => $customer->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => SaleStatus::Pending,
        ]);

        if ($createdAt) {
            $sale->created_at = $createdAt;
            $sale->save();
            $sale->refresh();
        }

        return $sale;
    }

    private function openShiftFor(User $user): CashRegisterShift
    {
        return CashRegisterShift::create([
            'user_id' => $user->id,
            'branch_id' => $this->branch->id,
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);
    }

    // --- Gate del feature flag ---------------------------------------------

    public function test_flag_nace_habilitado_para_no_romper_sucursales_existentes(): void
    {
        $this->assertTrue($this->branch->fresh()->cashier_customers_enabled);
    }

    public function test_cajero_ve_el_listado_de_clientes_cuando_el_modulo_esta_habilitado(): void
    {
        $this->makeCustomer(['name' => 'Doña Chelo']);

        $this->actingAs($this->cajero);
        $response = $this->get(route('caja.clientes.index', $this->tenant->slug));

        $response->assertOk();
        $this->assertSame(
            'Caja/Clientes/Index',
            $response->viewData('page')['component'],
        );
    }

    public function test_cajero_recibe_403_en_todo_el_modulo_cuando_esta_deshabilitado(): void
    {
        $this->branch->update(['cashier_customers_enabled' => false]);
        $customer = $this->makeCustomer();

        $this->actingAs($this->cajero);

        $this->get(route('caja.clientes.index', $this->tenant->slug))->assertForbidden();
        $this->get(route('caja.clientes.show', [$this->tenant->slug, $customer->id]))->assertForbidden();
        $this->get(route('caja.clientes.stats', [$this->tenant->slug, $customer->id]))->assertForbidden();
        $this->post(route('caja.clientes.store', $this->tenant->slug), [
            'name' => 'Nuevo', 'phone' => '9931234567',
        ])->assertForbidden();
        $this->postJson(route('caja.clientes.cobro-global', [$this->tenant->slug, $customer->id]), [
            'amount_received' => 100, 'method' => 'cash',
        ])->assertForbidden();
    }

    // --- Cartera y ficha ----------------------------------------------------

    public function test_el_listado_solo_muestra_clientes_de_su_sucursal(): void
    {
        $mio = $this->makeCustomer(['name' => 'De mi sucursal']);
        $this->makeCustomer(['name' => 'De otra sucursal', 'branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->cajero);
        $response = $this->get(route('caja.clientes.index', $this->tenant->slug));

        $names = collect($response->viewData('page')['props']['customers']['data'])->pluck('name');
        $this->assertTrue($names->contains($mio->name));
        $this->assertFalse($names->contains('De otra sucursal'));
    }

    public function test_la_ficha_no_expone_precios_preferenciales_ni_catalogo_de_productos(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->cajero);
        $props = $this->get(route('caja.clientes.show', [$this->tenant->slug, $customer->id]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('products', $props);
        $this->assertArrayNotHasKey('prices', $props['customer']);
    }

    public function test_cajero_no_puede_ver_la_ficha_de_un_cliente_de_otra_sucursal(): void
    {
        $ajeno = $this->makeCustomer(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->cajero);
        $this->get(route('caja.clientes.show', [$this->tenant->slug, $ajeno->id]))->assertForbidden();
    }

    // --- Altas y ediciones --------------------------------------------------

    public function test_cajero_da_de_alta_un_cliente_en_su_sucursal(): void
    {
        $this->actingAs($this->cajero);

        $this->post(route('caja.clientes.store', $this->tenant->slug), [
            'name' => 'Señora María',
            'phone' => '9931112233',
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'name' => 'Señora María',
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_cajero_edita_datos_de_contacto_pero_no_el_estado(): void
    {
        $customer = $this->makeCustomer(['name' => 'Antes', 'status' => 'active']);

        $this->actingAs($this->cajero);
        $this->put(route('caja.clientes.update', [$this->tenant->slug, $customer->id]), [
            'name' => 'Después',
            'phone' => $customer->phone,
            'status' => 'inactive', // se ignora: el cajero no cambia el estado
        ])->assertRedirect();

        $customer->refresh();
        $this->assertSame('Después', $customer->name);
        $this->assertSame('active', $customer->status);
    }

    // --- Cobro global -------------------------------------------------------

    public function test_cajero_registra_un_cobro_global_distribuido_fifo(): void
    {
        $customer = $this->makeCustomer();
        $vieja = $this->makePendingSale($customer, 100, now()->subDays(3));
        $nueva = $this->makePendingSale($customer, 100, now()->subDay());
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero);
        $response = $this->postJson(
            route('caja.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 150, 'method' => 'cash'],
        );

        $response->assertCreated();
        $this->assertEquals(150.0, (float) $response->json('customer_payment.amount_applied'));

        // FIFO: la venta más antigua se salda primero.
        $this->assertSame(0.0, (float) $vieja->fresh()->amount_pending);
        $this->assertSame(50.0, (float) $nueva->fresh()->amount_pending);

        $this->assertDatabaseHas('customer_payments', [
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_cobro_global_requiere_turno_abierto(): void
    {
        $customer = $this->makeCustomer();
        $this->makePendingSale($customer, 100);

        $this->actingAs($this->cajero);
        $this->postJson(
            route('caja.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 100, 'method' => 'cash'],
        )->assertForbidden();

        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_cajero_no_puede_cobrar_a_un_cliente_de_otra_sucursal(): void
    {
        $ajeno = $this->makeCustomer(['branch_id' => $this->secondBranch->id]);
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero);
        $this->postJson(
            route('caja.clientes.cobro-global', [$this->tenant->slug, $ajeno->id]),
            ['amount_received' => 100, 'method' => 'cash'],
        )->assertForbidden();
    }

    public function test_cajero_consulta_el_detalle_de_un_cobro_global(): void
    {
        $customer = $this->makeCustomer();
        $this->makePendingSale($customer, 100);
        $this->openShiftFor($this->cajero);

        $this->actingAs($this->cajero);
        $created = $this->postJson(
            route('caja.clientes.cobro-global', [$this->tenant->slug, $customer->id]),
            ['amount_received' => 100, 'method' => 'cash'],
        )->json('customer_payment.id');

        $this->get(route('caja.clientes.cobro-global.show', [$this->tenant->slug, $customer->id, $created]))
            ->assertOk()
            ->assertJsonPath('id', $created);
    }

    // --- Lo que el cajero NO puede hacer ------------------------------------

    public function test_no_existen_rutas_de_precios_preferenciales_en_caja(): void
    {
        $this->assertFalse(app('router')->has('caja.clientes.precios.store'));
        $this->assertFalse(app('router')->has('caja.clientes.precios.update'));
        $this->assertFalse(app('router')->has('caja.clientes.precios.destroy'));
    }

    public function test_no_existen_rutas_de_baja_de_cliente_ni_de_cancelacion_de_cobro_en_caja(): void
    {
        $this->assertFalse(app('router')->has('caja.clientes.destroy'));
        $this->assertFalse(app('router')->has('caja.clientes.cobro-global.cancel'));
    }

    public function test_cajero_no_puede_cancelar_un_cobro_global_por_la_ruta_de_sucursal(): void
    {
        $customer = $this->makeCustomer();
        $payment = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-'.uniqid(),
            'method' => 'cash',
            'amount_received' => 100,
            'amount_applied' => 100,
            'change_given' => 0,
            'sales_affected_count' => 1,
        ]);

        $this->actingAs($this->cajero);
        $this->deleteJson(
            route('sucursal.clientes.cobro-global.cancel', [$this->tenant->slug, $customer->id, $payment->id]),
            ['cancel_reason' => 'me equivoqué'],
        )->assertForbidden();

        $this->assertNull($payment->fresh()->cancelled_at);
    }

    public function test_cajero_no_puede_tocar_precios_preferenciales_por_la_ruta_de_sucursal(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->cajero);
        $this->post(route('sucursal.clientes.precios.store', [$this->tenant->slug, $customer->id]), [
            'product_id' => 1,
            'price' => 50,
        ])->assertForbidden();
    }
}
