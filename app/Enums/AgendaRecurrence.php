<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum AgendaRecurrence: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** Avanza una fecha a la siguiente ocurrencia según la recurrencia. */
    public function advance(Carbon $date): Carbon
    {
        return match ($this) {
            self::None => $date->copy(),
            self::Daily => $date->copy()->addDay(),
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
        };
    }
}
