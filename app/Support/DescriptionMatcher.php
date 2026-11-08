<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Compares the free-text descriptions people type ("Cumparaturi", "Cumpărături", "Coffee break"):
 * case, diacritics and punctuation never matter.
 */
class DescriptionMatcher
{
    /**
     * Lowercase ASCII words separated by single spaces.
     */
    public static function normalize(?string $description): string
    {
        $ascii = Str::lower(Str::ascii((string) $description));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * How alike two already normalised descriptions are, from 0 to 1: the best of the share of
     * matching words (typos in longer words still match), one containing the other, and the
     * character similarity. 1 means identical.
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $characterPercent);

        [$shorter, $longer] = count(explode(' ', $a)) <= count(explode(' ', $b))
            ? [explode(' ', $a), explode(' ', $b)]
            : [explode(' ', $b), explode(' ', $a)];
        $matched = 0;

        foreach ($shorter as $shortWord) {
            foreach ($longer as $longWord) {
                if (self::wordsMatch($shortWord, $longWord)) {
                    $matched++;

                    break;
                }
            }
        }

        $wordScore = $matched / (count($shorter) + count($longer) - $matched);

        // "Coffee" inside "Coffee break": every word of the shorter one is in the longer one.
        $contained = $matched === count($shorter) && strlen(implode('', $shorter)) >= 4 ? 0.8 : 0.0;

        // Only identical descriptions score a full 1.
        return min(0.99, max($wordScore, $contained, $characterPercent / 100));
    }

    private static function wordsMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (min(strlen($a), strlen($b)) < 5) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent >= 85;
    }
}
