<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class WebOrdersFeatureFlagEnabledTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_web_order_routes_are_registered_when_flag_is_on(): void
    {
        $this->assertTrue(Route::has('public.menu'));
        $this->assertTrue(Route::has('api.public.orders.store'));
        $this->assertTrue(Route::has('sucursal.menu-online'));
        $this->assertTrue(Route::has('empresa.personalizacion'));
        $this->assertTrue(Route::has('sucursal.workbench.link-order'));
        $this->assertTrue(Route::has('caja.link-order'));
    }

    public function test_features_prop_is_shared_as_true(): void
    {
        // Mismo setup de tenant/branch/admin-sucursal que MenuQrTest.
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.dashboard', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('features.webOrders', true));
    }
}
