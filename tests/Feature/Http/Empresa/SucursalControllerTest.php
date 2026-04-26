<?php

namespace Tests\Feature\Http\Empresa;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SucursalControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sucursal 1',
            'address' => 'Av. Juárez 123',
            'latitude' => 17.9891,
            'longitude' => -92.9475,
            'phone' => '9931234567',
            'status' => 'active',
            'online_ordering_enabled' => false,
            'delivery_enabled' => false,
            'pickup_enabled' => true,
        ], $overrides);
    }

    public function test_admin_empresa_can_view_index(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.sucursales.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Empresa/Sucursales/Index')
                ->has('stats.total')
                ->has('stats.active')
                ->has('stats.inactive')
                ->has('stats.online')
                ->has('stats.no_location'));
    }

    public function test_admin_empresa_can_view_show(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.sucursales.show', [$this->tenant->slug, $this->branch->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Empresa/Sucursales/Show')
                ->where('sucursal.id', $this->branch->id));
    }

    public function test_show_blocks_branch_from_other_tenant(): void
    {
        // El TenantScope filtra Branch por tenant_id antes del binding,
        // así que una sucursal de otro tenant resulta en 404 en lugar de 403.
        // Es la primera línea de defensa; authorizeBranchAccess es respaldo.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-tenant', 'status' => 'active']);
        $foreign = Branch::create(['tenant_id' => $other->id, 'name' => 'X', 'address' => 'a', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.sucursales.show', [$this->tenant->slug, $foreign->id]))
            ->assertNotFound();
    }

    public function test_admin_sucursal_cannot_edit(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('empresa.sucursales.edit', [$this->tenant->slug, $this->branch->id]))
            ->assertForbidden();
    }

    public function test_update_succeeds_with_minimal_valid_payload(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload()
            )
            ->assertRedirect(route('empresa.sucursales.index', $this->tenant->slug));

        $this->branch->refresh();
        $this->assertSame('Sucursal 1', $this->branch->name);
    }

    public function test_update_autogenerates_schedule_from_hours(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'hours' => [
                        'mon' => ['open' => '07:00', 'close' => '20:00'],
                        'tue' => ['open' => '07:00', 'close' => '20:00'],
                        'wed' => ['open' => '07:00', 'close' => '20:00'],
                        'thu' => ['open' => '07:00', 'close' => '20:00'],
                        'fri' => ['open' => '07:00', 'close' => '20:00'],
                        'sat' => ['open' => '07:00', 'close' => '20:00'],
                        'sun' => null,
                    ],
                ])
            )
            ->assertRedirect();

        $this->branch->refresh();
        $this->assertNotEmpty($this->branch->schedule);
        // Lun-Sáb agrupado + Dom cerrado.
        $this->assertStringContainsString('Lun-Sáb 07:00-20:00', $this->branch->schedule);
        $this->assertStringContainsString('Dom cerrado', $this->branch->schedule);
    }

    public function test_update_normalizes_public_phone(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'pickup_enabled' => true,
                    'public_phone' => '993 123 4567',
                ])
            )
            ->assertRedirect();

        $this->branch->refresh();
        // El normalizador deja sólo dígitos (formato internacional sin espacios/guiones).
        $this->assertMatchesRegularExpression('/^\+?\d+$/', $this->branch->public_phone);
    }

    public function test_update_fails_when_online_ordering_without_public_phone(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->from(route('empresa.sucursales.edit', [$this->tenant->slug, $this->branch->id]))
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'pickup_enabled' => true,
                    'public_phone' => '',
                ])
            )
            ->assertSessionHasErrors('public_phone');
    }

    public function test_update_fails_when_online_ordering_without_pickup_or_delivery(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->from(route('empresa.sucursales.edit', [$this->tenant->slug, $this->branch->id]))
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'pickup_enabled' => false,
                    'delivery_enabled' => false,
                    'public_phone' => '9931234567',
                ])
            )
            ->assertSessionHasErrors('delivery_enabled');
    }

    public function test_update_fails_when_delivery_without_location(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->from(route('empresa.sucursales.edit', [$this->tenant->slug, $this->branch->id]))
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'delivery_enabled' => true,
                    'pickup_enabled' => false,
                    'public_phone' => '9931234567',
                    'latitude' => null,
                    'longitude' => null,
                    'delivery_tiers' => [['max_km' => 3, 'fee' => 30]],
                ])
            )
            ->assertSessionHasErrors('latitude');
    }

    public function test_update_fails_when_delivery_without_tiers(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->from(route('empresa.sucursales.edit', [$this->tenant->slug, $this->branch->id]))
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'delivery_enabled' => true,
                    'pickup_enabled' => false,
                    'public_phone' => '9931234567',
                    'delivery_tiers' => [],
                ])
            )
            ->assertSessionHasErrors('delivery_tiers');
    }

    public function test_update_sorts_delivery_tiers_ascending(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(
                route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]),
                $this->validPayload([
                    'online_ordering_enabled' => true,
                    'delivery_enabled' => true,
                    'pickup_enabled' => true,
                    'public_phone' => '9931234567',
                    'delivery_tiers' => [
                        ['max_km' => 5, 'fee' => 60],
                        ['max_km' => 2, 'fee' => 30],
                        ['max_km' => 8, 'fee' => 90],
                    ],
                ])
            )
            ->assertRedirect();

        $this->branch->refresh();
        $kms = array_column($this->branch->delivery_tiers, 'max_km');
        $this->assertSame([2.0, 5.0, 8.0], array_map(fn ($v) => (float) $v, $kms));
    }
}
