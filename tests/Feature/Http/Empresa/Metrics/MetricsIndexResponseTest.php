<?php

namespace Tests\Feature\Http\Empresa\Metrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Espejo Empresa de MetricsIndexResponseTest. Además del payload básico,
 * verifica que el selector de sucursal sigue recibiendo branches.
 */
class MetricsIndexResponseTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_response_has_filter_props_branches_and_no_comparison_keys(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Empresa/Metricas/Index')
                ->has('range')
                ->has('presets')
                ->has('statuses')
                ->has('tenant')
                ->has('branches')
                ->has('data') // el index ahora es el Resumen (P&L + KPIs + alertas)
                ->has('data.pnl')
                ->missing('compare')
                ->missing('refresh')
                ->missing('backfill_run_at')
            );
    }
}
