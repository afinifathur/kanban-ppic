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
}
