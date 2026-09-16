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

    public function test_unregistered_scales_come_from_sales(): void
    {
        $this->makeCompletedSale(['origin_name' => 'Bascula vieja']);
        $this->makeCompletedSale(['origin_name' => 'Balanza 1']);
        $this->device(['name' => 'Balanza 1']);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.devices.index', $this->tenant->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->has('unregistered', 1)
                ->where('unregistered.0.name', 'Bascula vieja')
                ->where('devices.0.last_sale_at', fn ($v) => $v !== null)
            );
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
}
