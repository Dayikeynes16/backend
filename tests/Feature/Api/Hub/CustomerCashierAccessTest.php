<?php

namespace Tests\Feature\Api\Hub;

use App\Models\CashRegisterShift;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El cajero gestiona clientes desde el hub cuando su sucursal lo habilita.
 *
 * Paridad con la web (`routes/web.php`, grupo `branch.feature:cashier_customers_enabled`):
 * el cajero puede listar, crear, editar y cobrar fiado en FIFO, pero **no** tocar
 * precios preferenciales, dar de baja clientes ni cancelar cobros. Esas tres
 * exclusiones son deliberadas allí y se replican aquí.
 */
class CustomerCashierAccessTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function enableForCashier(bool $enabled = true): void
    {
        $this->branch->forceFill(['cashier_customers_enabled' => $enabled])->save();
    }

    private function cajeroToken(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function customer(string $name = 'Ana'): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'phone' => '5551234567',
            'status' => 'active',
        ]);
    }

    public function test_cashier_can_create_a_customer_when_enabled(): void
    {
        $this->enableForCashier();

        $this->withToken($this->cajeroToken())
            ->postJson('/api/v1/hub/customers', ['name' => 'Nuevo cliente', 'phone' => '6611112222'])
            ->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'Nuevo cliente']);
    }

    public function test_cashier_can_edit_a_customer_when_enabled(): void
    {
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->patchJson("/api/v1/hub/customers/{$customer->id}", ['name' => 'Ana Corregida', 'phone' => '5551234567'])
            ->assertOk();

        $this->assertSame('Ana Corregida', $customer->fresh()->name);
    }

    public function test_cashier_cannot_manage_customers_when_the_branch_has_it_off(): void
    {
        $this->enableForCashier(false);

        $this->withToken($this->cajeroToken())
            ->postJson('/api/v1/hub/customers', ['name' => 'No debe entrar', 'phone' => '6613334444'])
            ->assertForbidden();
    }

    public function test_cashier_cannot_deactivate_a_customer_through_the_edit_form(): void
    {
        // La otra puerta a la misma prohibición: si `update` aceptara el status,
        // el cajero daría de baja clientes sin pasar por el destroy.
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->patchJson("/api/v1/hub/customers/{$customer->id}", [
                'name' => 'Ana',
                'phone' => '5551234567',
                'status' => 'inactive',
            ])
            ->assertOk();

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_admin_can_deactivate_through_the_edit_form(): void
    {
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->adminSucursal->createToken('hub')->plainTextToken)
            ->patchJson("/api/v1/hub/customers/{$customer->id}", [
                'name' => 'Ana',
                'phone' => '5551234567',
                'status' => 'inactive',
            ])
            ->assertOk();

        $this->assertSame('inactive', $customer->fresh()->status);
    }

    public function test_cashier_never_deactivates_a_customer(): void
    {
        // Exclusión deliberada de la web: dar de baja es del admin-sucursal.
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->deleteJson("/api/v1/hub/customers/{$customer->id}")
            ->assertForbidden();
    }

    public function test_cashier_never_sets_preferential_prices(): void
    {
        // Los descuentos por cliente son decisión del admin-sucursal.
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->postJson("/api/v1/hub/customers/{$customer->id}/prices", [
                'product_id' => 1,
                'price' => 10,
            ])
            ->assertForbidden();
    }

    public function test_cashier_can_register_a_fifo_collection_when_enabled(): void
    {
        // Es la parte de dinero: el cajero cobra fiado desde el hub, igual que
        // ya lo hace desde la web. Exige turno abierto, como allí.
        $this->enableForCashier();
        $customer = $this->customer();

        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);

        $res = $this->withToken($this->cajeroToken())
            ->postJson("/api/v1/hub/customers/{$customer->id}/payments", [
                'amount_received' => 50,
                'method' => 'cash',
            ]);

        // Este test cubre el PERMISO, no la mecánica FIFO —que ya tiene los suyos
        // en CustomerPaymentApiTest—. Un cliente sin deuda devuelve 422 desde el
        // servicio, y eso ya demuestra que el cajero atravesó el guard: lo que no
        // debe volver nunca es un 403.
        $this->assertNotSame(403, $res->status());
    }

    public function test_cashier_cannot_register_a_collection_when_disabled(): void
    {
        $this->enableForCashier(false);
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->postJson("/api/v1/hub/customers/{$customer->id}/payments", [
                'amount' => 50,
                'method' => 'cash',
            ])
            ->assertForbidden();
    }

    public function test_cashier_never_cancels_a_collection(): void
    {
        // Exclusión deliberada de la web: cancelar un cobro es del admin-sucursal.
        $this->enableForCashier();
        $customer = $this->customer();

        $this->withToken($this->cajeroToken())
            ->deleteJson("/api/v1/hub/customers/{$customer->id}/payments/1")
            ->assertForbidden();
    }

    public function test_admin_keeps_full_access_regardless_of_the_flag(): void
    {
        // El flag gatea al cajero; el admin-sucursal pasa siempre.
        $this->enableForCashier(false);

        $this->withToken($this->adminSucursal->createToken('hub')->plainTextToken)
            ->postJson('/api/v1/hub/customers', ['name' => 'Del admin', 'phone' => '6615556666'])
            ->assertCreated();
    }
}
