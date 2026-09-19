<?php

namespace Tests\Feature\Sucursal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_branch_admin_saves_the_battery_thresholds(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 35,
                'battery_critical_threshold' => 15,
            ])
            ->assertRedirect();

        $this->assertSame(35, $this->branch->fresh()->battery_warn_threshold);
        $this->assertSame(15, $this->branch->fresh()->battery_critical_threshold);
    }

    public function test_the_critical_threshold_can_never_reach_the_warning_one(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 20,
                'battery_critical_threshold' => 20,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');
    }

    public function test_it_only_accepts_multiples_of_five_inside_the_range(): void
    {
        $this->actingAs($this->adminSucursal)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 33,
                'battery_critical_threshold' => 10,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');

        $this->actingAs($this->adminSucursal)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 100,
                'battery_critical_threshold' => 10,
            ])
            ->assertSessionHasErrors('battery_warn_threshold');
    }

    public function test_saving_the_thresholds_does_not_touch_the_payment_methods(): void
    {
        $before = $this->branch->fresh()->payment_methods_enabled;

        $this->actingAs($this->adminSucursal)
            ->put("/{$this->tenant->slug}/sucursal/configuracion/bateria", [
                'battery_warn_threshold' => 30,
                'battery_critical_threshold' => 10,
            ]);

        $this->assertSame($before, $this->branch->fresh()->payment_methods_enabled);
    }
}
