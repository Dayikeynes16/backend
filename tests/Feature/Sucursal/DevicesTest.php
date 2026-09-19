<?php

namespace Tests\Feature\Sucursal;

use App\Models\Device;
use App\Models\DeviceRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function device(array $attrs = []): Device
    {
        return Device::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => 'd-'.uniqid(), 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'app_version' => '1.6.1', 'via' => 'cloud', 'battery_level' => 14, 'battery_charging' => false,
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ], $attrs));
    }

    public function test_branch_admin_sees_only_own_devices_with_status_and_release(): void
    {
        $mine = $this->device();
        $this->device(['branch_id' => $this->secondBranch->id, 'name' => 'Ajena']);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now(), 'checked_at' => now()]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sucursal/Equipos/Index')
                ->has('devices', 1)
                ->where('devices.0.id', $mine->id)
                ->where('devices.0.status', 'battery_low')
                ->where('devices.0.outdated', true)
                ->where('devices.0.release_version', '1.6.2')
                ->has('alerts', 1)
            );
    }

    public function test_unregistered_scales_come_from_scale_api_sales_only(): void
    {
        $this->makeCompletedSale(['origin' => 'api', 'origin_name' => 'Bascula vieja']);
        $this->makeCompletedSale(['origin' => 'api', 'origin_name' => 'Balanza 1']);
        $this->makeCompletedSale(['origin' => 'api', 'origin_name' => 'Retirada']);
        // El mostrador y el hub firman como «Administrador»: no son básculas.
        $this->makeCompletedSale(['origin' => 'admin', 'origin_name' => 'Administrador']);
        $this->device(['name' => 'Balanza 1']);
        $this->device(['name' => 'Retirada', 'retired_at' => now()]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->has('devices', 1)
                ->has('unregistered', 1)
                ->where('unregistered.0.name', 'Bascula vieja')
                ->where('unregistered.0.last_sale_at', fn ($v) => is_string($v) && str_contains($v, 'T'))
                ->where('devices.0.last_sale_at', fn ($v) => is_string($v) && str_contains($v, 'T'))
            );
    }

    public function test_a_numeric_device_name_still_matches_its_sales(): void
    {
        $this->makeCompletedSale(['origin' => 'api', 'origin_name' => '2']);
        $this->device(['name' => '2']);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->has('devices', 1)
                ->has('unregistered', 0)
                ->where('devices.0.last_sale_at', fn ($v) => $v !== null)
            );
    }

    public function test_alias_zero_is_kept(): void
    {
        $device = $this->device();

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$this->tenant->slug, $device]), ['display_name' => '0'])
            ->assertRedirect();

        $this->assertSame('0', $device->refresh()->display_name);
    }

    public function test_a_retired_device_cannot_be_touched(): void
    {
        $device = $this->device(['retired_at' => now()]);
        $slug = $this->tenant->slug;

        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => 'x'])->assertNotFound();
        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertNotFound();
        $this->actingAs($this->adminSucursal)->delete(route('sucursal.devices.destroy', [$slug, $device]))->assertNotFound();
        $this->assertNull($device->refresh()->muted_at);
    }

    public function test_rename_mute_and_retire(): void
    {
        $device = $this->device();
        $slug = $this->tenant->slug;

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => 'Caja Norte'])
            ->assertRedirect();
        $this->assertSame('Caja Norte', $device->refresh()->display_name);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => ''])
            ->assertRedirect();
        $this->assertNull($device->refresh()->display_name);

        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertRedirect();
        $this->assertNotNull($device->refresh()->muted_at);
        $this->actingAs($this->adminSucursal)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertRedirect();
        $this->assertNull($device->refresh()->muted_at);

        $this->actingAs($this->adminSucursal)->delete(route('sucursal.devices.destroy', [$slug, $device]))->assertRedirect();
        $this->assertNotNull($device->refresh()->retired_at);
    }

    public function test_another_branch_device_is_not_found(): void
    {
        $foreign = $this->device(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal)
            ->patch(route('sucursal.devices.update', [$this->tenant->slug, $foreign]), ['display_name' => 'x'])
            ->assertNotFound();
    }

    public function test_cashier_cannot_open_the_panel(): void
    {
        $this->actingAs($this->cajero)->get(route('sucursal.devices.index', $this->tenant->slug))->assertForbidden();
    }

    public function test_a_critical_device_is_presented_and_listed_in_the_alerts(): void
    {
        $this->branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $device = $this->device(['battery_level' => 9, 'battery_charging' => false, 'last_seen_at' => now()]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('devices.0.status', 'battery_critical')
                ->where('devices.0.severity', 'critical')
                ->where('alerts.0.device_id', $device->device_id)
            );
    }
}
