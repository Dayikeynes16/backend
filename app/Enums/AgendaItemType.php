<?php

namespace App\Enums;

enum AgendaItemType: string
{
    case Task = 'task';
    case Event = 'event';
    case Note = 'note';
}
