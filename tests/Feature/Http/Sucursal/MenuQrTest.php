<?php

namespace Tests\Feature\Http\Sucursal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class MenuQrTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_admin_sucursal_can_view_menu_qr(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.menu-online', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sucursal/MenuQr')
                ->where('branch.id', $this->branch->id)
                ->has('menu_url')
                ->has('menu_path')
            );
    }

    public function test_menu_url_includes_tenant_and_branch(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.menu-online', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->where('menu_path', "/menu/{$this->tenant->slug}/s/{$this->branch->id}")
            );
    }

    public function test_cajero_forbidden(): void
    {
        $this->actingAs($this->cajero)
            ->get(route('sucursal.menu-online', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_guest_redirected(): void
    {
        $this->get(route('sucursal.menu-online', $this->tenant->slug))
            ->assertRedirect(route('login'));
    }
}
