<?php

namespace Tests\Unit\Services\Metrics;

use App\Services\Metrics\DateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class DateRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-04-17 14:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preset_today(): void
    {
        $r = DateRange::preset('today', 'UTC');
        $this->assertSame('2026-04-17 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-17 23:59:59', $r->end->toDateTimeString());
        $this->assertSame('today', $r->preset);
    }

    public function test_preset_yesterday(): void
    {
        $r = DateRange::preset('yesterday', 'UTC');
        $this->assertSame('2026-04-16 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-16 23:59:59', $r->end->toDateTimeString());
    }

    public function test_preset_last_7_days(): void
    {
        $r = DateRange::preset('last_7_days', 'UTC');
        $this->assertSame('2026-04-11 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-17 23:59:59', $r->end->toDateTimeString());
    }

    public function test_preset_this_month(): void
    {
        $r = DateRange::preset('this_month', 'UTC');
        $this->assertSame('2026-04-01 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-17 23:59:59', $r->end->toDateTimeString());
    }

    public function test_preset_last_month(): void
    {
        $r = DateRange::preset('last_month', 'UTC');
        $this->assertSame('2026-03-01 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', $r->end->toDateTimeString());
    }

    public function test_preset_this_year(): void
    {
        $r = DateRange::preset('this_year', 'UTC');
        $this->assertSame('2026-01-01 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-17 23:59:59', $r->end->toDateTimeString());
    }

    public function test_unknown_preset_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateRange::preset('nope', 'UTC');
    }

    public function test_custom_range(): void
    {
        $r = DateRange::custom('2026-04-01', '2026-04-10', 'UTC');
        $this->assertSame('2026-04-01 00:00:00', $r->start->toDateTimeString());
        $this->assertSame('2026-04-10 23:59:59', $r->end->toDateTimeString());
    }

    public function test_custom_range_capped_at_365_days(): void
    {
        $r = DateRange::custom('2025-01-01', '2026-06-01', 'UTC');
        $this->assertSame('2025-01-01 00:00:00', $r->start->toDateTimeString());
        // 2025-01-01 + 365 days = 2026-01-01
        $this->assertSame('2026-01-01 23:59:59', $r->end->toDateTimeString());
    }

    public function test_from_request_defaults_to_today_when_invalid(): void
    {
        $r = DateRange::fromRequest(null, null, null, 'UTC');
        $this->assertSame('today', $r->preset);
    }

    public function test_from_request_uses_preset_if_valid(): void
    {
        $r = DateRange::fromRequest('this_month', null, null, 'UTC');
        $this->assertSame('this_month', $r->preset);
    }

    public function test_from_request_uses_custom_if_no_preset(): void
    {
        $r = DateRange::fromRequest(null, '2026-04-01', '2026-04-10', 'UTC');
        $this->assertNull($r->preset);
        $this->assertSame('2026-04-01 00:00:00', $r->start->toDateTimeString());
    }

    public function test_previous_comparable_for_today(): void
    {
        $r = DateRange::preset('today', 'UTC');
        $prev = $r->previousComparable();
        $this->assertSame('2026-04-16', $prev->start->toDateString());
        $this->assertSame('2026-04-16', $prev->end->toDateString());
    }

    public function test_previous_comparable_for_this_month_tracks_days_elapsed(): void
    {
        $r = DateRange::preset('this_month', 'UTC');
        $prev = $r->previousComparable();
        $this->assertSame('2026-03-01', $prev->start->toDateString());
        $this->assertSame('2026-03-17', $prev->end->toDateString());
    }

    public function test_hash_is_stable_for_same_range(): void
    {
        $a = DateRange::preset('today', 'UTC');
        $b = DateRange::preset('today', 'UTC');
        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_hash_differs_between_ranges(): void
    {
        $a = DateRange::preset('today', 'UTC');
        $b = DateRange::preset('yesterday', 'UTC');
        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function test_label(): void
    {
        $this->assertSame('Hoy', DateRange::preset('today', 'UTC')->label());
        $this->assertSame('Este mes', DateRange::preset('this_month', 'UTC')->label());
        $this->assertSame('2026-04-01 → 2026-04-10', DateRange::custom('2026-04-01', '2026-04-10', 'UTC')->label());
    }

    public function test_start_after_end_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DateRange(
            CarbonImmutable::parse('2026-04-10'),
            CarbonImmutable::parse('2026-04-01'),
        );
    }
}
