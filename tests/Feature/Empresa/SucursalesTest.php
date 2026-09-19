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

    /**
     * Los dos umbrales son un par atómico: este formulario comparte
     * request con el resto de ajustes de la sucursal y valida ambos
     * campos como `sometimes`, así que un PUT que solo mande el urgente
     * no debe colarse dejando roto el invariante urgente < aviso.
     */
    public function test_company_admin_cannot_save_only_the_critical_threshold(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'battery_critical_threshold' => 15,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');

        $this->assertSame(10, $this->branch->fresh()->battery_critical_threshold);
    }

    public function test_company_admin_cannot_invert_the_battery_thresholds(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'battery_warn_threshold' => 20,
                'battery_critical_threshold' => 20,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');

        $this->assertSame(20, $this->branch->fresh()->battery_warn_threshold);
        $this->assertSame(10, $this->branch->fresh()->battery_critical_threshold);
    }
}
