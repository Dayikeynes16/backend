<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function customer(int $branchId, string $name, string $phone = '555', string $status = 'active'): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchId,
            'name' => $name, 'phone' => $phone, 'status' => $status,
        ]);
    }

    public function test_lists_active_customers_of_own_branch(): void
    {
        $this->customer($this->branch->id, 'Ana', '5551');
        $this->customer($this->branch->id, 'Beto', '5552', 'inactive');
        $this->customer($this->secondBranch->id, 'Carla', '5553');

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/customers')
            ->assertOk();

        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Ana'));
        $this->assertFalse($names->contains('Beto')); // inactivo
        $this->assertFalse($names->contains('Carla')); // otra sucursal
    }

    public function test_search_by_name_or_phone(): void
    {
        $this->customer($this->branch->id, 'Ana López', '6610001111');
        $this->customer($this->branch->id, 'Pedro', '6619998888');

        $byName = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/customers?search=lópez')->assertOk();
        $this->assertCount(1, $byName->json('data'));
        $this->assertSame('Ana López', $byName->json('data.0.name'));

        $byPhone = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/customers?search=9998')->assertOk();
        $this->assertSame('Pedro', $byPhone->json('data.0.name'));
    }

    public function test_admin_empresa_forbidden(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/customers')
            ->assertStatus(403);
    }

    private function pendingSale(Customer $c, float $total = 100, float $paid = 0): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $c->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $paid,
            'amount_pending' => $total - $paid,
            'origin' => 'api',
            'status' => SaleStatus::Active,
        ]);
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    /** Las escrituras de clientes son de admin-sucursal (paridad web). */
    private function adminToken(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    public function test_cajero_cannot_manage_customers(): void
    {
        $c = $this->customer($this->branch->id, 'Intocable', '6619990000');

        $this->withToken($this->token())
            ->postJson('/api/v1/hub/customers', ['name' => 'Nuevo'])
            ->assertForbidden();

        $this->withToken($this->token())
            ->patchJson("/api/v1/hub/customers/{$c->id}", ['name' => 'Otro', 'status' => 'active'])
            ->assertForbidden();

        $this->withToken($this->token())
            ->deleteJson("/api/v1/hub/customers/{$c->id}")
            ->assertForbidden();
    }

    public function test_store_creates_customer(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/customers', ['name' => 'Nuevo Cliente', 'phone' => '6612223344'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nuevo Cliente')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('phone', '6612223344')->count());
    }

    public function test_store_rejects_duplicate_phone_in_branch(): void
    {
        $this->customer($this->branch->id, 'Existente', '6610000000');

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/customers', ['name' => 'Otro', 'phone' => '6610000000'])
            ->assertStatus(422);
    }

    public function test_update_customer(): void
    {
        $c = $this->customer($this->branch->id, 'Viejo Nombre', '6610001111');

        $this->withToken($this->adminToken())
            ->patchJson("/api/v1/hub/customers/{$c->id}", ['name' => 'Nuevo Nombre', 'phone' => '6610001111', 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo Nombre')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_destroy_deactivates_when_has_sales(): void
    {
        $c = $this->customer($this->branch->id, 'Con Ventas', '6610002222');
        $this->pendingSale($c);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/hub/customers/{$c->id}")
            ->assertOk()
            ->assertJsonPath('action', 'deactivated');

        $this->assertSame('inactive', $c->refresh()->status);
    }

    public function test_destroy_deletes_when_no_sales(): void
    {
        $c = $this->customer($this->branch->id, 'Sin Ventas', '6610003333');

        $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/hub/customers/{$c->id}")
            ->assertOk()
            ->assertJsonPath('action', 'deleted');

        $this->assertNull(Customer::withoutGlobalScopes()->find($c->id));
    }

    public function test_index_reports_debt_and_portfolio_summary(): void
    {
        $ana = $this->customer($this->branch->id, 'Ana Deudora', '6610004444');
        $this->pendingSale($ana, 100, 30); // debe 70
        $this->customer($this->branch->id, 'Sin Deuda', '6610005555');

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/customers')->assertOk();

        $anaRow = collect($res->json('data'))->firstWhere('name', 'Ana Deudora');
        $this->assertEquals(70, $anaRow['total_owed']);
        $this->assertSame(1, $anaRow['sales_count']);
        $this->assertEquals(70, $res->json('summary.total_debt'));
        $this->assertSame(1, $res->json('summary.customers_with_debt'));
    }

    public function test_with_debt_filter(): void
    {
        $ana = $this->customer($this->branch->id, 'Ana Deudora', '6610006666');
        $this->pendingSale($ana, 100);
        $this->customer($this->branch->id, 'Sin Deuda', '6610007777');

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/customers?with_debt=1')->assertOk();

        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Ana Deudora'));
        $this->assertFalse($names->contains('Sin Deuda'));
    }

    public function test_show_returns_stats(): void
    {
        $c = $this->customer($this->branch->id, 'Cliente Stats', '6610008888');
        $this->pendingSale($c, 100, 30);

        $res = $this->withToken($this->token())
            ->getJson("/api/v1/hub/customers/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Cliente Stats');

        $this->assertSame(1, $res->json('stats.sale_count'));
        $this->assertEquals(100, $res->json('stats.total_spent'));
        $this->assertEquals(70, $res->json('stats.total_owed'));
        $this->assertSame(1, $res->json('stats.pending_sales_count'));
    }

    public function test_history_lists_customer_sales(): void
    {
        $c = $this->customer($this->branch->id, 'Cliente Hist', '6610009999');
        $sale = $this->pendingSale($c);

        $res = $this->withToken($this->token())
            ->getJson("/api/v1/hub/customers/{$c->id}/history")
            ->assertOk();

        $this->assertSame($sale->folio, $res->json('data.0.folio'));
    }

    public function test_cross_branch_customer_returns_404(): void
    {
        $foreign = $this->customer($this->secondBranch->id, 'Ajeno', '6610001234');

        $this->withToken($this->token())
            ->getJson("/api/v1/hub/customers/{$foreign->id}")
            ->assertStatus(404);
    }
}
