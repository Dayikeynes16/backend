<?php

namespace Tests\Feature\Hub;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/** El hub se reporta a sí mismo y reenvía el latido de sus básculas: via = hub. */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_cashier_hub_registers_itself_with_via_hub(): void
    {
        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/devices/heartbeat', [
            'device_id' => 'hub-centro', 'kind' => 'hub_windows', 'name' => 'Hub Centro', 'app_version' => '1.2.0',
        ])->assertCreated();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('hub', $device->via);
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame($this->branch->id, $device->branch_id);
    }

    public function test_hub_relays_a_scale_heartbeat(): void
    {
        Sanctum::actingAs($this->adminSucursal);

        $this->postJson('/api/v1/hub/devices/heartbeat', [
            'device_id' => 'tablet-1', 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'battery' => ['level' => 30, 'charging' => false], 'connection' => 'hub',
        ])->assertCreated();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('hub', $device->via);
        $this->assertSame('hub', $device->connection);
    }

    public function test_admin_empresa_cannot_use_the_hub_surface(): void
    {
        Sanctum::actingAs($this->adminEmpresa);
        $this->postJson('/api/v1/hub/devices/heartbeat', ['device_id' => 'x', 'kind' => 'hub_windows'])->assertForbidden();
    }

    public function test_the_response_carries_the_branch_thresholds(): void
    {
        $this->branch->update(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/devices/heartbeat', [
            'device_id' => 'tablet-1',
            'kind' => 'scale_android',
            'name' => 'Balanza 1',
        ])->assertSuccessful()
            ->assertJsonPath('data.battery_alert.warn', 35)
            ->assertJsonPath('data.battery_alert.critical', 15);
    }
}
