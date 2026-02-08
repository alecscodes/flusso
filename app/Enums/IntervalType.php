<?php

namespace App\Enums;

enum IntervalType: string
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case Years = 'years';
}
