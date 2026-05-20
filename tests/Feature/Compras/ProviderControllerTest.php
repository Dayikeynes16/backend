<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProviderControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    // ─── Empresa ──────────────────────────────────────────────────────────

    public function test_admin_empresa_can_list_providers(): void
    {
        Provider::create(['name' => 'P1', 'type' => 'ganadero']);
        Provider::create(['name' => 'P2', 'type' => 'mayorista_carne']);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.proveedores.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Empresa/Proveedores/Index')
            ->has('providers', 2)
        );
    }

    public function test_admin_empresa_can_create_provider(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.proveedores.store', $this->tenant->slug), [
            'name' => 'Carnes Don Pedro',
            'type' => 'mayorista_carne',
            'phone' => '5551234567',
            'rfc' => 'XAXX010101000',
            'payment_terms_days' => 15,
        ])->assertRedirect();

        $this->assertDatabaseHas('providers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Carnes Don Pedro',
            'type' => 'mayorista_carne',
            'payment_terms_days' => 15,
            'created_by' => $this->adminEmpresa->id,
        ]);
    }

    public function test_admin_empresa_cannot_create_duplicate_name(): void
    {
        Provider::create(['name' => 'Duplicado', 'type' => 'otro']);

        $this->actingAs($this->adminEmpresa);
        $response = $this->from(route('empresa.proveedores.index', $this->tenant->slug))
            ->post(route('empresa.proveedores.store', $this->tenant->slug), [
                'name' => 'Duplicado',
                'type' => 'otro',
            ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Provider::count());
    }

    public function test_admin_empresa_can_update_provider(): void
    {
        $p = Provider::create(['name' => 'Original', 'type' => 'otro']);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.proveedores.update', ['tenant' => $this->tenant->slug, 'provider' => $p->id]), [
            'name' => 'Renombrado',
            'type' => 'ganadero',
            'status' => 'inactive',
        ])->assertRedirect();

        $fresh = $p->fresh();
        $this->assertSame('Renombrado', $fresh->name);
        $this->assertSame('ganadero', $fresh->type->value);
        $this->assertSame('inactive', $fresh->status);
    }

    public function test_admin_empresa_can_delete_provider_without_purchases(): void
    {
        $p = Provider::create(['name' => 'A borrar', 'type' => 'otro']);

        $this->actingAs($this->adminEmpresa);
        $this->delete(route('empresa.proveedores.destroy', ['tenant' => $this->tenant->slug, 'provider' => $p->id]))
            ->assertRedirect();

        $this->assertSoftDeleted('providers', ['id' => $p->id]);
    }

    public function test_admin_empresa_cannot_delete_provider_with_live_purchases(): void
    {
        $p = Provider::create(['name' => 'Con compras', 'type' => 'otro']);
        Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $p->id,
            'folio' => 'CMP-2026-99999',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->from(route('empresa.proveedores.index', $this->tenant->slug))
            ->delete(route('empresa.proveedores.destroy', ['tenant' => $this->tenant->slug, 'provider' => $p->id]));

        $response->assertSessionHasErrors('provider');
        $this->assertDatabaseHas('providers', ['id' => $p->id, 'deleted_at' => null]);
    }

    public function test_admin_empresa_cannot_touch_provider_from_other_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Otro', 'slug' => 'otro', 'status' => 'active']);
        app()->instance('tenant', $otherTenant);
        $foreign = Provider::create(['name' => 'Ajeno', 'type' => 'otro']);

        // Volver al tenant original.
        app()->instance('tenant', $this->tenant);

        $this->actingAs($this->adminEmpresa);
        // El route model binding (sin scope explícito) trae el provider; el
        // controller debe rechazar con 403/404 porque tenant_id != current.
        $response = $this->put(route('empresa.proveedores.update', [
            'tenant' => $this->tenant->slug,
            'provider' => $foreign->id,
        ]), [
            'name' => 'Hackeado',
            'type' => 'otro',
            'status' => 'active',
        ]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('Ajeno', $foreign->fresh()->name);
    }

    public function test_admin_sucursal_cannot_access_empresa_crud(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->get(route('empresa.proveedores.index', $this->tenant->slug))->assertForbidden();
        $this->post(route('empresa.proveedores.store', $this->tenant->slug), [
            'name' => 'X', 'type' => 'otro',
        ])->assertForbidden();
    }

    public function test_cajero_cannot_access_empresa_crud(): void
    {
        $this->actingAs($this->cajero);
        $this->get(route('empresa.proveedores.index', $this->tenant->slug))->assertForbidden();
    }

    // ─── Sucursal (read-only) ────────────────────────────────────────────

    public function test_admin_sucursal_can_list_providers_readonly(): void
    {
        Provider::create(['name' => 'Activo', 'type' => 'mayorista_carne', 'status' => 'active']);
        Provider::create(['name' => 'Inactivo', 'type' => 'otro', 'status' => 'inactive']);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.proveedores.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Sucursal/Proveedores/Index')
            // Solo activos en la vista de sucursal.
            ->has('providers', 1)
            ->where('providers.0.name', 'Activo')
        );
    }

    public function test_cajero_cannot_access_sucursal_providers(): void
    {
        $this->actingAs($this->cajero);
        $this->get(route('sucursal.proveedores.index', $this->tenant->slug))->assertForbidden();
    }

    public function test_admin_empresa_cannot_access_sucursal_providers(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->get(route('sucursal.proveedores.index', $this->tenant->slug))->assertForbidden();
    }
}
