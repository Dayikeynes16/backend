<?php

namespace Tests\Feature\Http\Sucursal\Metrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Resumen como hub: la respuesta Inertia ya no transporta KPIs ni rama
 * de comparativa. Solo lleva los filtros (range/presets/statuses), tenant
 * y selected_branch_id que vienen de commonProps().
 */
class MetricsIndexResponseTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_response_has_only_filter_props_no_comparison_keys(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Metricas/Index')
                ->has('range')
                ->has('presets')
                ->has('statuses')
                ->has('tenant')
                ->where('selected_branch_id', $this->branch->id)
                ->missing('compare')
                ->missing('refresh')
                ->missing('data')
                ->missing('backfill_run_at')
            );
    }

    public function test_presets_are_short_list(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->where('presets', ['today', 'yesterday', 'last_7_days'])
            );
    }

    public function test_range_does_not_expose_previous(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->has('range.preset')
                ->has('range.from')
                ->has('range.to')
                ->missing('range.previous')
            );
    }
}
