<?php

namespace App\Enums;

enum PaymentType: string
{
    case Income = 'income';
    case Expense = 'expense';
}
