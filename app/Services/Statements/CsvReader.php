<?php

namespace App\Services\Statements;

/**
 * Reads bank CSV exports: any text encoding, comma/semicolon/tab/pipe separated.
 */
final class CsvReader
{
    /**
     * The file's text as UTF-8 without a byte order mark and with "\n" line ends.
     */
    public static function decode(string $content): string
    {
        $content = ltrim($content, "\u{FEFF}");

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    public static function detectDelimiter(string $content): string
    {
        $lines = array_slice(array_values(array_filter(explode("\n", $content), fn (string $line) => trim($line) !== '')), 0, 5);
        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $count = 0;

            foreach ($lines as $line) {
                $count += substr_count($line, $delimiter);
            }

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @return list<list<string>> the non-empty rows, cells trimmed
     */
    public static function rows(string $content, ?string $delimiter = null): array
    {
        $content = self::decode($content);
        $delimiter ??= self::detectDelimiter($content);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn (?string $cell) => trim((string) $cell), $row);

            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = $cells;
        }

        fclose($handle);

        return $rows;
    }
}
