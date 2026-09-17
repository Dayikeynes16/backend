<?php

namespace Tests\Feature\Caja;

use App\Models\Device;
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

    public function test_cashier_sees_the_devices_of_their_branch(): void
    {
        $mine = $this->device();
        $this->device(['branch_id' => $this->secondBranch->id, 'name' => 'Ajena']);
        $this->makeCompletedSale(['origin' => 'api', 'origin_name' => 'Bascula vieja']);

        $this->actingAs($this->cajero)
            ->get(route('caja.devices.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Caja/Equipos/Index')
                ->has('devices', 1)
                ->where('devices.0.id', $mine->id)
                ->where('devices.0.status', 'battery_low')
                ->has('alerts', 1)
                ->has('unregistered', 1)
            );
    }

    public function test_cashier_cannot_change_a_device(): void
    {
        $device = $this->device();
        $slug = $this->tenant->slug;

        $this->actingAs($this->cajero)->patch(route('sucursal.devices.update', [$slug, $device]), ['display_name' => 'x'])->assertForbidden();
        $this->actingAs($this->cajero)->patch(route('sucursal.devices.mute', [$slug, $device]))->assertForbidden();
        $this->actingAs($this->cajero)->delete(route('sucursal.devices.destroy', [$slug, $device]))->assertForbidden();

        $device->refresh();
        $this->assertNull($device->display_name);
        $this->assertNull($device->muted_at);
        $this->assertNull($device->retired_at);
    }

    public function test_branch_admin_uses_their_own_panel_not_the_cashier_one(): void
    {
        $this->actingAs($this->adminSucursal)->get(route('caja.devices.index', $this->tenant->slug))->assertForbidden();
    }
}
