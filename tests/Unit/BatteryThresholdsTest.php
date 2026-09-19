<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Services\Devices\BatteryThresholds;
use Tests\TestCase;

/**
 * La única comparación entre un nivel de batería y los umbrales de su sucursal.
 * No toca base de datos: es una función pura sobre dos números.
 */
class BatteryThresholdsTest extends TestCase
{
    public function test_defaults_are_twenty_and_ten(): void
    {
        $t = new BatteryThresholds;

        $this->assertSame(20, $t->warn);
        $this->assertSame(10, $t->critical);
    }

    public function test_a_null_branch_falls_back_to_the_defaults(): void
    {
        $t = BatteryThresholds::fromBranch(null);

        $this->assertSame(20, $t->warn);
        $this->assertSame(10, $t->critical);
    }

    public function test_it_reads_the_thresholds_of_the_branch(): void
    {
        $branch = new Branch(['battery_warn_threshold' => 35, 'battery_critical_threshold' => 15]);

        $t = BatteryThresholds::fromBranch($branch);

        $this->assertSame(35, $t->warn);
        $this->assertSame(15, $t->critical);
    }

    public function test_the_comparison_includes_the_threshold_itself(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertSame('warn', $t->severityFor(20, false));
        $this->assertSame('critical', $t->severityFor(10, false));
        $this->assertNull($t->severityFor(21, false));
    }

    public function test_critical_wins_over_warn(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertSame('critical', $t->severityFor(3, false));
    }

    public function test_charging_or_unknown_is_never_an_alert(): void
    {
        $t = new BatteryThresholds(20, 10);

        $this->assertNull($t->severityFor(5, true));
        $this->assertNull($t->severityFor(null, false));
    }
}
