<?php

namespace App\Enums;

enum PlannedPaymentStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Finished = 'finished';
}
