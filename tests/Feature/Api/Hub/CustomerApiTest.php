<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Customer;
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
}
