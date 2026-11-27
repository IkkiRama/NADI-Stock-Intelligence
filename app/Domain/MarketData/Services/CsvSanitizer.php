<?php

namespace App\Domain\MarketData\Services;

final class CsvSanitizer
{
    /**
     * Sanitize imported free-text strings against spreadsheet formula injection.
     */
    public static function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^[=+\-@\t\r]/', $trimmed) === 1) {
            return "'".$trimmed;
        }

        return $trimmed;
    }

    /**
     * Escape a cell before writing into an exported CSV file.
     * Preserves legitimate negative numbers (e.g., -12.34) while neutralizing formula prefixes.
     */
    public static function escapeCell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;
        $trimmed = ltrim($str);

        if ($trimmed === '') {
            return $str;
        }

        $first = $trimmed[0];
        if (in_array($first, ['=', '+', '@', "\t", "\r"], true)) {
            return "'".$str;
        }

        if ($first === '-' && preg_match('/^-?\d+(\.\d+)?$/', trim($str)) !== 1) {
            return "'".$str;
        }

        return $str;
    }
}
