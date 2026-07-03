<?php

namespace App\Enums;

enum AiDraftStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Consumed = 'consumed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
