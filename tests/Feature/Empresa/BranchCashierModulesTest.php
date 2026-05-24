<?php

namespace Tests\Feature\Empresa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class BranchCashierModulesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_modules_are_enabled_by_default(): void
    {
        $branch = $this->branch->fresh();

        $this->assertTrue($branch->cashier_expenses_enabled);
        $this->assertTrue($branch->cashier_purchases_enabled);
    }

    public function test_admin_empresa_can_disable_cashier_modules(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'cashier_expenses_enabled' => false,
                'cashier_purchases_enabled' => false,
            ])
            ->assertRedirect();

        $branch = $this->branch->fresh();
        $this->assertFalse($branch->cashier_expenses_enabled);
        $this->assertFalse($branch->cashier_purchases_enabled);
    }

    public function test_admin_empresa_can_re_enable_a_disabled_module(): void
    {
        $this->branch->update(['cashier_purchases_enabled' => false]);

        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'cashier_expenses_enabled' => true,
                'cashier_purchases_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($this->branch->fresh()->cashier_purchases_enabled);
    }
}
