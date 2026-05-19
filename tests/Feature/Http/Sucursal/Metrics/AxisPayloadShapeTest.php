<?php

namespace Tests\Feature\Http\Sucursal\Metrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Verifica que los payloads Inertia de los ejes ya no transportan las claves
 * eliminadas durante la simplificación de mayo 2026:
 *
 *   - Sales:         no previous_daily_series, no summary.previous
 *   - Margin:        no previous_daily_gross_profit, no summary.previous
 *   - Cancellations: no previous_daily, no summary.previous
 *
 * Sin estos asserts, una regresión que vuelva a inyectar previous_* en los
 * controladores pasaría inadvertida hasta el smoke manual.
 */
class AxisPayloadShapeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_sales_payload_has_no_previous_keys(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.ventas', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Metricas/Ventas')
                ->has('data.summary.current')
                ->missing('data.summary.previous')
                ->has('data.daily_series')
                ->missing('data.previous_daily_series')
                ->missing('compare')
                ->missing('refresh')
            );
    }

    public function test_margin_payload_has_no_previous_keys(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.margen', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Metricas/Margen')
                ->has('data.summary.current')
                ->missing('data.summary.previous')
                ->has('data.daily_gross_profit')
                ->missing('data.previous_daily_gross_profit')
                ->missing('compare')
                ->missing('refresh')
            );
    }

    public function test_cancellations_payload_has_no_previous_keys(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.metricas.cancelaciones', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/Metricas/Cancelaciones')
                ->has('data.summary.current')
                ->missing('data.summary.previous')
                ->has('data.daily')
                ->missing('data.previous_daily')
                ->missing('compare')
                ->missing('refresh')
            );
    }
}
