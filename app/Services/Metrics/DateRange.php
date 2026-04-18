<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DateRange
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_year'];

    public const MAX_DAYS = 365;

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?string $preset = null,
    ) {
        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('start must be <= end');
        }
    }

    public static function preset(string $name, ?string $tz = null): self
    {
        $tz ??= config('app.timezone');
        $now = CarbonImmutable::now($tz);

        return match ($name) {
            'today' => new self($now->startOfDay(), $now->endOfDay(), 'today'),
            'yesterday' => new self($now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'yesterday'),
            'last_7_days' => new self($now->subDays(6)->startOfDay(), $now->endOfDay(), 'last_7_days'),
            'this_month' => new self($now->startOfMonth(), $now->endOfDay(), 'this_month'),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), 'last_month'),
            'this_year' => new self($now->startOfYear(), $now->endOfDay(), 'this_year'),
            default => throw new InvalidArgumentException("Unknown preset: {$name}"),
        };
    }

    public static function custom(string $from, string $to, ?string $tz = null): self
    {
        $tz ??= config('app.timezone');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay();

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $end = $start->addDays(self::MAX_DAYS)->endOfDay();
        }

        return new self($start, $end);
    }

    public static function fromRequest(?string $preset, ?string $from, ?string $to, ?string $tz = null): self
    {
        if ($preset && in_array($preset, self::PRESETS, true)) {
            return self::preset($preset, $tz);
        }
        if ($from && $to) {
            try {
                return self::custom($from, $to, $tz);
            } catch (\Throwable) {
                // fall through to default
            }
        }

        return self::preset('today', $tz);
    }

    public function previousComparable(): self
    {
        if ($this->preset === 'this_month') {
            $tz = $this->start->timezone;
            $now = CarbonImmutable::now($tz);
            $daysElapsed = $this->start->diffInDays($this->end);
            $prevStart = $now->subMonthNoOverflow()->startOfMonth();
            $prevEnd = $prevStart->addDays((int) $daysElapsed)->endOfDay();
            if ($prevEnd->greaterThan($prevStart->endOfMonth())) {
                $prevEnd = $prevStart->endOfMonth();
            }

            return new self($prevStart, $prevEnd);
        }
        if ($this->preset === 'this_year') {
            $tz = $this->start->timezone;
            $now = CarbonImmutable::now($tz);
            $daysElapsed = $this->start->diffInDays($this->end);
            $prevStart = $now->subYearNoOverflow()->startOfYear();
            $prevEnd = $prevStart->addDays((int) $daysElapsed)->endOfDay();

            return new self($prevStart, $prevEnd);
        }

        $lengthSeconds = $this->end->diffInSeconds($this->start, true);
        $prevEnd = $this->start->subSecond();
        $prevStart = $prevEnd->subSeconds((int) $lengthSeconds);

        return new self($prevStart, $prevEnd);
    }

    public function days(): int
    {
        return (int) ceil($this->start->diffInDays($this->end->addSecond()));
    }

    public function hash(): string
    {
        return md5($this->start->toIso8601String().'|'.$this->end->toIso8601String());
    }

    public function label(): string
    {
        return match ($this->preset) {
            'today' => 'Hoy',
            'yesterday' => 'Ayer',
            'last_7_days' => 'Últimos 7 días',
            'this_month' => 'Este mes',
            'last_month' => 'Mes pasado',
            'this_year' => 'Este año',
            default => $this->start->format('Y-m-d').' → '.$this->end->format('Y-m-d'),
        };
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->start->toDateString(),
            'to' => $this->end->toDateString(),
            'from_iso' => $this->start->toIso8601String(),
            'to_iso' => $this->end->toIso8601String(),
            'label' => $this->label(),
            'days' => $this->days(),
        ];
    }
}
