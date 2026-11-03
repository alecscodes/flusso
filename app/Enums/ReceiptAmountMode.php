<?php

namespace App\Enums;

/**
 * How the amount of a transaction proposed from a receipt is written: to the cent or up to the next whole unit.
 */
enum ReceiptAmountMode: string
{
    case Exact = 'exact';
    case RoundUp = 'round_up';

    /**
     * The receipt dialog applies the same rule, so what is proposed is what the owner sees.
     */
    public function apply(float $amount): float
    {
        $amount = round($amount, 2);

        return $this === self::RoundUp ? ceil($amount) : $amount;
    }
}
