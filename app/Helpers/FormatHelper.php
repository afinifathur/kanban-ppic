<?php

namespace App\Helpers;

class FormatHelper
{
    public static function compactNumber($n, $precision = 1)
    {
        if ($n < 1000) {
            // Less than 1k, just show number
            return number_format($n);
        }

        // Users said "dibawah 50.000", they might prefer seeing "16.2k" or full "16,230".
        // The previous request had "16,230".
        // User asked for "compact" but then asked to "add pcs and kgs".
        // Let's stick to full number formatting for now as 10k fits easily.
        // It provides more precision which is often needed in PPIC.
        return number_format($n);
    }

    /**
     * Deduplicate an iterable of string values using exact equality,
     * preserving encounter order, ignoring null/empty/'-' placeholders,
     * and normalizing whitespace safely without mutating characters, digits, or product specs.
     *
     * @param  iterable<mixed>  $items
     * @return array<int, string>
     */
    public static function deduplicateList(iterable $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $trimmed = trim((string) $item);
            if ($trimmed === '' || $trimmed === '-') {
                continue;
            }

            // Normalize internal consecutive whitespace (spaces, tabs, newlines) to a single space
            // Strictly preserves all numbers, DN sizes, inch symbols, material names, standards, etc.
            $normalized = preg_replace('/\s+/', ' ', $trimmed);

            if (! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * Format a deduplicated list of strings joined by a separator, or return fallback.
     *
     * @param  iterable<mixed>  $items
     */
    public static function formatDeduplicatedList(iterable $items, string $separator = ', ', string $fallback = '-'): string
    {
        $deduped = self::deduplicateList($items);

        return ! empty($deduped) ? implode($separator, $deduped) : $fallback;
    }
}
