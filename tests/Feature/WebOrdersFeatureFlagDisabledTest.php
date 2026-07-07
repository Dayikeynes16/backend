<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebOrdersFeatureFlagDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FEATURE_WEB_ORDERS=false');
        $_ENV['FEATURE_WEB_ORDERS'] = 'false';
        $_SERVER['FEATURE_WEB_ORDERS'] = 'false';
        parent::setUp(); // bootea la app leyendo el env ya apagado
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('FEATURE_WEB_ORDERS');
        unset($_ENV['FEATURE_WEB_ORDERS'], $_SERVER['FEATURE_WEB_ORDERS']);
    }

    public function test_public_menu_spa_returns_404(): void
    {
        $this->get('/menu/el-toro')->assertNotFound();
    }

    public function test_public_api_returns_404(): void
    {
        $this->getJson('/api/public/el-toro')->assertNotFound();
        $this->postJson('/api/public/el-toro/branches/1/orders', [])->assertNotFound();
    }

    public function test_panel_web_order_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('public.menu'));
        $this->assertFalse(Route::has('api.public.tenant.show'));
        $this->assertFalse(Route::has('api.public.menu'));
        $this->assertFalse(Route::has('api.public.delivery.quote'));
        $this->assertFalse(Route::has('api.public.orders.store'));
    }

    public function test_unrelated_routes_still_exist(): void
    {
        $this->assertTrue(Route::has('sucursal.workbench.update-status'));
        $this->assertTrue(Route::has('caja.update-status'));
        $this->assertTrue(Route::has('caja.whatsapp-link'));
    }
}
