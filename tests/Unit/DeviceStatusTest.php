<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El estado de un equipo se deriva de cuándo reportó por última vez y de su
 * batería. No toca base de datos: es una función pura sobre la fila. Extiende
 * el TestCase de Laravel (no el de PHPUnit) porque el accessor lee `config()`.
 */
class DeviceStatusTest extends TestCase
{
    private function device(array $attrs): Device
    {
        return new Device(array_merge([
            'battery_level' => 80,
            'battery_charging' => false,
            'retired_at' => null,
        ], $attrs));
    }

    public function test_online_when_seen_less_than_ten_minutes_ago(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(3)]);
        $this->assertSame('online', $d->status);
    }

    public function test_stale_between_ten_and_thirty_minutes(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(15)]);
        $this->assertSame('stale', $d->status);
    }

    public function test_silent_after_thirty_minutes(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now()->subMinutes(45)]);
        $this->assertSame('silent', $d->status);
    }

    public function test_battery_low_only_when_online_and_not_charging(): void
    {
        $low = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 14]);
        $this->assertSame('battery_low', $low->status);

        $charging = $this->device(['last_seen_at' => Carbon::now(), 'battery_level' => 14, 'battery_charging' => true]);
        $this->assertSame('online', $charging->status);

        $silentLow = $this->device(['last_seen_at' => Carbon::now()->subHour(), 'battery_level' => 14]);
        $this->assertSame('silent', $silentLow->status);
    }

    public function test_retired_wins_over_everything(): void
    {
        $d = $this->device(['last_seen_at' => Carbon::now(), 'retired_at' => Carbon::now()]);
        $this->assertSame('retired', $d->status);
    }

    public function test_display_name_prefers_the_alias(): void
    {
        $this->assertSame('Balanza 1', $this->device(['name' => 'Balanza 1'])->displayName());
        $this->assertSame('Caja Norte', $this->device(['name' => 'Balanza 1', 'display_name' => 'Caja Norte'])->displayName());
    }
}
