<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Notifications\DeviceBatteryLow;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceRegistered;
use App\Notifications\DeviceSilent;
use App\Services\Devices\DeviceAlertService;
use App\Services\Devices\DeviceHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Los avisos no se repiten: cada uno tiene una marca en la fila del equipo que
 * se pone al avisar y se limpia cuando la situación se resuelve.
 */
class DeviceAlertsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private DeviceHeartbeatService $heartbeats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Notification::fake();
        $this->heartbeats = app(DeviceHeartbeatService::class);
    }

    private function beat(int $level, bool $charging = false, string $id = 'tab-1'): Device
    {
        return $this->heartbeats->record($this->tenant->id, $this->branch->id, [
            'device_id' => $id, 'kind' => 'scale_android', 'name' => 'Balanza 1',
            'battery' => ['level' => $level, 'charging' => $charging],
        ], 'cloud')->device;
    }

    public function test_new_device_notifies_branch_admin_and_company_admin_but_not_cashier(): void
    {
        $this->beat(80);

        Notification::assertSentTo($this->adminSucursal, DeviceRegistered::class);
        Notification::assertSentTo($this->adminEmpresa, DeviceRegistered::class);
        Notification::assertNotSentTo($this->cajero, DeviceRegistered::class);

        $this->beat(80);
        Notification::assertSentToTimes($this->adminSucursal, DeviceRegistered::class, 1);
    }

    public function test_battery_alerts_once_per_threshold_and_rearm_on_charge(): void
    {
        $this->beat(25);
        Notification::assertNotSentTo($this->adminSucursal, DeviceBatteryLow::class);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        $this->beat(8);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 2);

        $this->beat(9, charging: true);
        $this->assertNull(Device::withoutGlobalScopes()->first()->battery_alert_level);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 3);
    }

    public function test_an_unknown_battery_does_not_rearm_the_alert(): void
    {
        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        // Un reinicio que aún no leyó la batería manda `battery: null`.
        $this->heartbeats->record($this->tenant->id, $this->branch->id, [
            'device_id' => 'tab-1', 'kind' => 'scale_android', 'battery' => null,
        ], 'cloud');
        $this->assertSame('warn', Device::withoutGlobalScopes()->first()->battery_alert_level);

        $this->beat(14);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);
    }

    public function test_battery_arriving_directly_below_ten_gives_a_single_alert(): void
    {
        $this->beat(6);
        $this->beat(6);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);
        $this->assertSame('critical', Device::withoutGlobalScopes()->first()->battery_alert_level);
    }

    public function test_it_alerts_at_the_branch_threshold_not_at_twenty(): void
    {
        $this->branch->update(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        $device = $this->beat(30);

        $this->assertSame('warn', $device->fresh()->battery_alert_level);
        Notification::assertSentTo($this->adminSucursal, DeviceBatteryLow::class);
    }

    public function test_the_two_severities_are_two_alerts(): void
    {
        $this->branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $this->beat(25);
        $this->beat(12);

        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 2);
    }

    public function test_raising_the_threshold_over_the_current_level_does_not_alert_again(): void
    {
        // Un equipo ya avisado al que le suben el umbral sigue estando avisado:
        // la marca no se toca y la campana no vuelve a sonar.
        $this->beat(18);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);

        $this->branch->update(['battery_warn_threshold' => 40]);
        $this->beat(18);

        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);
    }

    public function test_lowering_the_threshold_below_the_level_rearms_without_alerting(): void
    {
        $this->beat(18);
        $this->branch->update(['battery_warn_threshold' => 10, 'battery_critical_threshold' => 5]);

        $device = $this->beat(18);

        $this->assertNull($device->fresh()->battery_alert_level);
        Notification::assertSentToTimes($this->adminSucursal, DeviceBatteryLow::class, 1);
    }

    public function test_the_critical_alert_says_something_else(): void
    {
        $this->beat(6);

        Notification::assertSentTo($this->adminSucursal, DeviceBatteryLow::class, function ($notification) {
            $payload = $notification->toArray($this->adminSucursal);

            return $payload['severity'] === 'critical' && $payload['title'] === 'Se va a apagar';
        });
    }

    public function test_muted_device_updates_marks_but_sends_nothing(): void
    {
        $device = $this->beat(80);
        $device->update(['muted_at' => now()]);

        $this->beat(5);

        Notification::assertNotSentTo($this->adminSucursal, DeviceBatteryLow::class);
        $this->assertSame('critical', Device::withoutGlobalScopes()->first()->battery_alert_level);
    }

    public function test_silence_alerts_once_inside_the_window(): void
    {
        $device = $this->beat(80);
        $service = app(DeviceAlertService::class);

        // Sábado 08:20: lleva 20 min de ventana callado → todavía no.
        $device->update(['last_seen_at' => Carbon::parse('2026-09-11 21:00', config('app.timezone'))]);
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-12 08:20', config('app.timezone'))));

        // 08:35 → avisa.
        $this->assertTrue($service->checkSilence($device->refresh(), Carbon::parse('2026-09-12 08:35', config('app.timezone'))));
        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);

        // Domingo → sigue marcado, no repite.
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-13 09:00', config('app.timezone'))));
        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);

        // Fuera de ventana nunca avisa.
        $device->update(['silent_alerted_at' => null]);
        $this->assertFalse($service->checkSilence($device->refresh(), Carbon::parse('2026-09-13 23:00', config('app.timezone'))));
    }

    public function test_outdated_alerts_once_per_version_after_a_day(): void
    {
        $device = $this->beat(80);
        $device->update(['app_version' => '1.6.1']);
        $service = app(DeviceAlertService::class);

        $fresh = DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now()->subHours(2), 'checked_at' => now()]);
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh, now()));

        $fresh->update(['known_since' => now()->subHours(30)]);
        $this->assertTrue($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
        Notification::assertSentToTimes($this->adminSucursal, DeviceOutdated::class, 1);

        $device->update(['app_version' => '1.6.2']);
        $this->assertFalse($service->checkOutdated($device->refresh(), $fresh->refresh(), now()));
    }

    public function test_alerts_go_only_to_the_devices_branch(): void
    {
        $otherAdmin = $this->makeUser('otra@test.local', 'admin-sucursal', $this->secondBranch->id);
        $this->beat(80);
        Notification::assertNotSentTo($otherAdmin, DeviceRegistered::class);
    }
}
