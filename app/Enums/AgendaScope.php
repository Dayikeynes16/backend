<?php

namespace App\Enums;

enum AgendaScope: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Personal = 'personal';
}
