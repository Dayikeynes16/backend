<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El latido de un equipo por la Scale API. Es un endpoint nuevo: las básculas
 * viejas nunca lo llaman y nada de lo que ellas usan cambia (eso lo vigila
 * ScaleLegacyContractTest). Aquí se prueba que el registro sea idempotente,
 * respete el tenant y no pise lo que no viene.
 */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Branch $otherBranch;

    private string $rawKey;

    private string $otherBranchKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro', 'address' => 'A', 'status' => 'active']);
        $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Norte', 'address' => 'B', 'status' => 'active']);

        $this->rawKey = 'csa_test_'.str_repeat('a', 20);
        ApiKey::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'k', 'key_hash' => hash('sha256', $this->rawKey)]);

        $this->otherBranchKey = 'csa_test_'.str_repeat('b', 20);
        ApiKey::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'name' => 'k2', 'key_hash' => hash('sha256', $this->otherBranchKey)]);
    }

    private function beat(array $payload, ?string $key = null)
    {
        return $this->withHeader('X-Api-Key', $key ?? $this->rawKey)
            ->postJson('/api/v1/devices/heartbeat', $payload);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'device_id' => 'surface-001',
            'kind' => 'scale_windows',
            'name' => 'Mostrador Surface',
            'app_version' => '0.4.1',
            'os' => 'Windows 11',
            'model' => 'Surface Go 3',
            'battery' => ['level' => 55, 'charging' => true],
            'connection' => 'cloud',
            'local_ip' => '192.168.1.31',
        ], $overrides);
    }

    public function test_first_heartbeat_creates_the_device_with_201(): void
    {
        $this->beat($this->payload())
            ->assertCreated()
            ->assertJsonPath('data.device_id', 'surface-001')
            ->assertJsonPath('data.status', 'online');

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame($this->tenant->id, $device->tenant_id);
        $this->assertSame($this->branch->id, $device->branch_id);
        $this->assertSame('cloud', $device->via);
        $this->assertSame(55, $device->battery_level);
        $this->assertTrue($device->battery_charging);
        $this->assertNotNull($device->first_seen_at);
    }

    public function test_second_heartbeat_updates_with_200_and_does_not_duplicate(): void
    {
        $this->beat($this->payload());
        $this->beat($this->payload(['battery' => ['level' => 40, 'charging' => false]]))
            ->assertOk();

        $this->assertSame(1, Device::withoutGlobalScopes()->count());
        $this->assertSame(40, Device::withoutGlobalScopes()->first()->battery_level);
    }

    public function test_fields_not_sent_are_not_overwritten(): void
    {
        $this->beat($this->payload());
        $this->beat(['device_id' => 'surface-001', 'kind' => 'scale_windows'])->assertOk();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertSame('Mostrador Surface', $device->name);
        $this->assertSame('0.4.1', $device->app_version);
        $this->assertSame(55, $device->battery_level);
    }

    public function test_explicit_null_battery_clears_it(): void
    {
        $this->beat($this->payload());
        $this->beat(['device_id' => 'surface-001', 'kind' => 'scale_windows', 'battery' => null])->assertOk();

        $device = Device::withoutGlobalScopes()->first();
        $this->assertNull($device->battery_level);
        $this->assertNull($device->battery_charging);
    }

    public function test_same_device_id_from_another_branch_moves_it(): void
    {
        $this->beat($this->payload());
        $this->beat($this->payload(), $this->otherBranchKey)->assertOk();

        $this->assertSame(1, Device::withoutGlobalScopes()->count());
        $this->assertSame($this->otherBranch->id, Device::withoutGlobalScopes()->first()->branch_id);
    }

    public function test_same_device_id_in_another_tenant_is_another_device(): void
    {
        $other = Tenant::create(['name' => 'O', 'slug' => 'o', 'status' => 'active']);
        $ob = Branch::create(['tenant_id' => $other->id, 'name' => 'X', 'address' => 'C', 'status' => 'active']);
        $key = 'csa_test_'.str_repeat('c', 20);
        ApiKey::create(['tenant_id' => $other->id, 'branch_id' => $ob->id, 'name' => 'k3', 'key_hash' => hash('sha256', $key)]);

        $this->beat($this->payload());
        $this->beat($this->payload(), $key)->assertCreated();

        $this->assertSame(2, Device::withoutGlobalScopes()->count());
    }

    public function test_a_retired_device_that_reports_again_comes_back(): void
    {
        $this->beat($this->payload());
        Device::withoutGlobalScopes()->first()->update(['retired_at' => now()]);

        $this->beat($this->payload())->assertOk();

        $this->assertNull(Device::withoutGlobalScopes()->first()->retired_at);
    }

    public function test_heartbeat_clears_the_silence_mark(): void
    {
        $this->beat($this->payload());
        Device::withoutGlobalScopes()->first()->update(['silent_alerted_at' => now()]);

        $this->beat($this->payload());

        $this->assertNull(Device::withoutGlobalScopes()->first()->silent_alerted_at);
    }

    public function test_validation(): void
    {
        $this->beat(['kind' => 'scale_windows'])->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
        $this->beat(['device_id' => 'x', 'kind' => 'toaster'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->beat(['device_id' => 'malo espacio', 'kind' => 'scale_windows'])->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
        $this->beat($this->payload(['battery' => ['level' => 140, 'charging' => false]]))->assertUnprocessable()->assertJsonValidationErrors(['battery.level']);
        $this->beat($this->payload(['local_ip' => 'no-es-ip']))->assertUnprocessable()->assertJsonValidationErrors(['local_ip']);
        $this->beat($this->payload(['connection' => 'wifi']))->assertUnprocessable()->assertJsonValidationErrors(['connection']);
        $this->assertSame(0, Device::withoutGlobalScopes()->count());
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/v1/devices/heartbeat', $this->payload())->assertUnauthorized();
    }
}
