<?php

namespace Tests\Feature\Console;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceSilent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DeviceCommandsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Notification::fake();
    }

    private function device(array $attrs = []): Device
    {
        return Device::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => 'd-'.uniqid(), 'kind' => 'scale_android', 'name' => 'Balanza',
            'app_version' => '1.6.1', 'via' => 'cloud',
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ], $attrs));
    }

    public function test_sync_releases_reads_yaml_and_json_feeds(): void
    {
        Http::fake([
            '*/bascula/win/latest.yml' => Http::response("version: 0.4.1\nfiles:\n  - url: x.exe\n"),
            '*/android/latest.json' => Http::response(['versionName' => '1.6.2', 'versionCode' => 10602]),
            '*/hub/win/latest.yml' => Http::response('', 500),
        ]);

        $this->artisan('devices:sync-releases')->assertSuccessful();

        $this->assertSame('0.4.1', DeviceRelease::find('scale_windows')->version);
        $this->assertSame('1.6.2', DeviceRelease::find('scale_android')->version);
        $this->assertNull(DeviceRelease::find('hub_windows'));
    }

    public function test_sync_releases_keeps_known_since_when_version_is_unchanged(): void
    {
        $old = Carbon::now()->subDays(3);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => $old, 'checked_at' => $old]);
        // Una sola llamada a Http::fake() con una secuencia: un segundo Http::fake()
        // con el mismo patrón de URL NO reemplaza al primero (Laravel encola ambos
        // y el primero registrado gana), por eso la respuesta cambia por secuencia
        // y no por un segundo fake.
        Http::fake([
            '*/android/latest.json' => Http::sequence()
                ->push(['versionName' => '1.6.2'])
                ->push(['versionName' => '1.7.0']),
            '*' => Http::response('', 500),
        ]);

        $this->artisan('devices:sync-releases');

        $release = DeviceRelease::find('scale_android');
        $this->assertTrue($release->known_since->equalTo($old));
        $this->assertTrue($release->checked_at->gt($old));

        $this->artisan('devices:sync-releases');
        $this->assertTrue(DeviceRelease::find('scale_android')->known_since->gt($old));
    }

    public function test_broken_feed_keeps_the_previous_version(): void
    {
        DeviceRelease::create(['kind' => 'scale_windows', 'version' => '0.4.0', 'known_since' => now(), 'checked_at' => now()]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('devices:sync-releases')->assertSuccessful();

        $this->assertSame('0.4.0', DeviceRelease::find('scale_windows')->version);
    }

    public function test_check_alerts_silent_and_outdated_devices_once(): void
    {
        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-09-12 08:35', $tz));

        $silent = $this->device(['last_seen_at' => Carbon::parse('2026-09-11 21:00', $tz)]);
        $outdated = $this->device(['app_version' => '1.6.1']);
        DeviceRelease::create(['kind' => 'scale_android', 'version' => '1.6.2', 'known_since' => now()->subDays(2), 'checked_at' => now()]);

        $this->artisan('devices:check')->assertSuccessful();
        $this->artisan('devices:check')->assertSuccessful();

        Notification::assertSentToTimes($this->adminSucursal, DeviceSilent::class, 1);
        Notification::assertSentToTimes($this->adminSucursal, DeviceOutdated::class, 2); // silent también está atrasado
        $this->assertNotNull($silent->refresh()->silent_alerted_at);
        $this->assertSame('1.6.2', $outdated->refresh()->outdated_alert_version);

        Carbon::setTestNow();
    }

    public function test_check_ignores_retired_devices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00', config('app.timezone')));
        $this->device(['last_seen_at' => now()->subHours(3), 'retired_at' => now()]);

        $this->artisan('devices:check');

        Notification::assertNothingSent();
        Carbon::setTestNow();
    }
}
