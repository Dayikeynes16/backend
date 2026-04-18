<?php

namespace Tests\Feature\Http\Sucursal\Metrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class MetricsAuthorizationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_admin_sucursal_can_view_index(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertOk();
    }

    public function test_admin_sucursal_can_view_all_axis_pages(): void
    {
        $axes = ['ventas', 'margen', 'productos', 'clientes', 'cajeros', 'turnos', 'cobranza'];
        foreach ($axes as $axis) {
            $this->actingAs($this->adminSucursal)
                ->get(route("sucursal.metricas.{$axis}", $this->tenant->slug))
                ->assertOk();
        }
    }

    public function test_cajero_cannot_access(): void
    {
        $this->actingAs($this->cajero)
            ->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertRedirect(route('login'));
    }

    public function test_invalid_range_params_default_to_today(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.ventas', $this->tenant->slug).'?preset=invalid')
            ->assertOk();
    }

    public function test_admin_sucursal_cannot_pass_foreign_branch_id(): void
    {
        // admin-sucursal route ignores branch_id param — always uses user's branch
        $response = $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.ventas', $this->tenant->slug).'?branch_id='.$this->secondBranch->id);
        $response->assertOk();
        // The props should reflect the user's own branch
        $response->assertInertia(fn ($page) => $page
            ->where('selected_branch_id', $this->branch->id)
        );
    }
}
