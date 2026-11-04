<?php

namespace App\Services\Receipts;

/**
 * Model output is untrusted: this turns whatever it wrote for an amount into a
 * positive number with at most two decimals, or into nothing.
 */
class ReceiptAmounts
{
    private const MAX = 9_999_999.99;

    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value)) {
            $number = self::parseText($value);
        } else {
            return null;
        }

        if ($number === null || ! is_finite($number) || $number <= 0 || $number > self::MAX) {
            return null;
        }

        $rounded = round($number, 2);

        return $rounded > 0 ? $rounded : null;
    }

    /**
     * Reads "13.70", "13,70", "1.234,56", "1,234.56" and "EUR 13,70"; a negative text is not an amount.
     */
    private static function parseText(string $text): ?float
    {
        if (preg_match('/^\s*[-\x{2212}(]/u', $text) === 1) {
            return null;
        }

        $clean = preg_replace('/[^\d.,]/', '', $text) ?? '';

        if ($clean === '' || preg_match('/\d/', $clean) !== 1) {
            return null;
        }

        $lastDot = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } else {
            $separator = $lastDot !== false ? '.' : ($lastComma !== false ? ',' : null);
            $decimal = $separator !== null && self::isDecimalSeparator($clean, $separator) ? $separator : null;
        }

        if ($decimal !== null) {
            $position = (int) strrpos($clean, $decimal);
            $integerPart = str_replace(['.', ','], '', substr($clean, 0, $position));
            $clean = $integerPart.'.'.str_replace(['.', ','], '', substr($clean, $position + 1));
        } else {
            $clean = str_replace(['.', ','], '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * A lone separator followed by exactly three digits is a thousands mark ("1.234"); several of them always are.
     */
    private static function isDecimalSeparator(string $clean, string $separator): bool
    {
        if (substr_count($clean, $separator) !== 1) {
            return false;
        }

        $position = (int) strrpos($clean, $separator);
        $before = substr($clean, 0, $position);
        $after = substr($clean, $position + 1);

        return ! (strlen($after) === 3 && strlen($before) >= 1 && strlen($before) <= 3 && $before !== '0');
    }
}
