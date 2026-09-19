<?php

namespace Tests\Feature\Empresa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SucursalesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_company_admin_saves_the_battery_thresholds_of_a_branch(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'battery_warn_threshold' => 40,
                'battery_critical_threshold' => 20,
            ])
            ->assertRedirect();

        $this->assertSame(40, $this->branch->fresh()->battery_warn_threshold);
    }
}
