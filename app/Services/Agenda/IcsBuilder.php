<?php

namespace App\Services\Agenda;

use App\Models\AgendaItem;
use Illuminate\Support\Carbon;

class IcsBuilder
{
    public function forItem(AgendaItem $item, string $tenantSlug): string
    {
        $start = ($item->starts_at ?? now())->copy()->utc();
        $end = ($item->ends_at ?? $start->copy()->addHour())->copy()->utc();
        $stamp = now()->utc();
        $uid = "agenda-{$item->id}@{$tenantSlug}";

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Carniceria SaaS//Agenda//ES',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'DTSTAMP:'.$this->fmt($stamp),
            'DTSTART:'.$this->fmt($start),
            'DTEND:'.$this->fmt($end),
            'SUMMARY:'.$this->escape($item->title),
        ];

        if ($item->body) {
            $lines[] = 'DESCRIPTION:'.$this->escape($item->body);
        }

        if ($item->remind_at) {
            $minutesBefore = max(0, $item->remind_at->diffInMinutes($item->starts_at ?? $item->remind_at, false));
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->escape($item->title);
            $lines[] = "TRIGGER:-PT{$minutesBefore}M";
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function fmt(Carbon $dt): string
    {
        return $dt->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }
}
