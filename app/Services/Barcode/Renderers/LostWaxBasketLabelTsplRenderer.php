<?php

namespace App\Services\Barcode\Renderers;

use App\Models\LostWaxCastingResult;
use App\Models\LostWaxCastingResultTree;
use Illuminate\Support\Collection;

class LostWaxBasketLabelTsplRenderer
{
    public const FONT_MODE_FONT1 = 'font1'; // New standard / experiment (Font 1 for items & qty)
    public const FONT_MODE_FONT2 = 'font2'; // Previous layout (Font 2 for items & qty)

    public const MAX_USABLE_HEIGHT = 308; // Total vertical dots available for table rows (Y = 82..390)

    // Font 1 (8x12 dots) metrics - TSC TE200 bitmap font
    public const FONT_SMALL = '1';
    public const FONT1_WIDTH = 8;
    public const FONT1_HEIGHT = 12;
    public const FONT1_ROW_SINGLE = 28;
    public const FONT1_ROW_DOUBLE = 44;
    public const FONT1_WRAP_LIMIT = 50;

    // Font 2 (12x20 dots) metrics - TSC TE200 bitmap font
    public const FONT_NORMAL = '2';
    public const FONT2_WIDTH = 12;
    public const FONT2_HEIGHT = 20;
    public const FONT2_ROW_SINGLE = 34;
    public const FONT2_ROW_DOUBLE = 55;
    public const FONT2_WRAP_LIMIT = 37;

    // Font 4 (24x32 dots) metrics (Heat Number)
    public const FONT_LARGE = '4';

    // Aliases for default / backward compatibility
    public const ROW_HEIGHT_SINGLE = self::FONT1_ROW_SINGLE; // 28
    public const ROW_HEIGHT_DOUBLE = self::FONT1_ROW_DOUBLE; // 44
    public const CHAR_WIDTH_FONT1 = self::FONT1_WIDTH; // 8
    public const CHAR_WIDTH_FONT2 = self::FONT2_WIDTH; // 12

    // Horizontal layout coordinates (Dots across 720 dots label canvas)
    public const COL1_LEFT = 30;
    public const DIVIDER_1_X = 490;
    public const DIVIDER_2_X = 570;
    public const QTY_COL_MID_X = 530; // Midpoint of QTY column (490 to 570)

    /**
     * Render the full TSPL string for a casting result (can produce multiple labels).
     */
    public function render(LostWaxCastingResult $castingResult, string $fontMode = self::FONT_MODE_FONT1): string
    {
        $trees = $castingResult->trees;
        $chunks = $this->chunkItems($trees, $fontMode);
        $totalPages = max(1, count($chunks));

        $pagesTspl = [];

        if (empty($chunks)) {
            $pagesTspl[] = $this->renderSingleLabel($castingResult, [], 1, 1, $fontMode);
        } else {
            foreach ($chunks as $index => $chunk) {
                $currentPage = $index + 1;
                $pagesTspl[] = $this->renderSingleLabel($castingResult, $chunk, $currentPage, $totalPages, $fontMode);
            }
        }

        return implode("\r\n", $pagesTspl) . "\r\n";
    }

    /**
     * Dynamically partition items into label chunks based on actual row heights (wrapping).
     *
     * @param Collection<int, LostWaxCastingResultTree> $trees
     * @return array<int, array<int, array{tree: LostWaxCastingResultTree, lines: array<string>, height: int}>>
     */
    public function chunkItems(Collection $trees, string $fontMode = self::FONT_MODE_FONT1): array
    {
        $isFont1 = $fontMode === self::FONT_MODE_FONT1;
        $wrapLimit = $isFont1 ? self::FONT1_WRAP_LIMIT : self::FONT2_WRAP_LIMIT;
        $singleHeight = $isFont1 ? self::FONT1_ROW_SINGLE : self::FONT2_ROW_SINGLE;
        $doubleHeight = $isFont1 ? self::FONT1_ROW_DOUBLE : self::FONT2_ROW_DOUBLE;

        $chunks = [];
        $currentChunk = [];
        $currentHeight = 0;

        foreach ($trees as $tree) {
            $rawName = $tree->item_name ?? $tree->production_code ?? '-';
            $lines = $this->wrapText($rawName, $wrapLimit, 2);
            $height = count($lines) > 1 ? $doubleHeight : $singleHeight;

            // If adding this item exceeds the printable height and chunk is not empty, start a new label
            if (!empty($currentChunk) && ($currentHeight + $height > self::MAX_USABLE_HEIGHT)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentHeight = 0;
            }

            $currentChunk[] = [
                'tree' => $tree,
                'lines' => $lines,
                'height' => $height,
            ];
            $currentHeight += $height;
        }

        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Render a single TSPL label (90 x 50 mm) with dynamic row heights.
     *
     * @param array<int, array{tree: LostWaxCastingResultTree, lines: array<string>, height: int}> $chunkItems
     */
    public function renderSingleLabel(
        LostWaxCastingResult $castingResult,
        array $chunkItems,
        int $currentPage,
        int $totalPages,
        string $fontMode = self::FONT_MODE_FONT1
    ): string {
        $isFont1 = $fontMode === self::FONT_MODE_FONT1;
        $itemFont = $isFont1 ? self::FONT_SMALL : self::FONT_NORMAL;
        $charWidth = $isFont1 ? self::FONT1_WIDTH : self::FONT2_WIDTH;
        $fontHeight = $isFont1 ? self::FONT1_HEIGHT : self::FONT2_HEIGHT;
        $singleHeight = $isFont1 ? self::FONT1_ROW_SINGLE : self::FONT2_ROW_SINGLE;

        $heatNumber = $this->sanitize($castingResult->heat_number);
        $castDate = $castingResult->cast_date ? $castingResult->cast_date->format('d/m/Y') : date('d/m/Y');

        // Header meta on single horizontal line: HAL 1/1 | TGL: 13/11/2026 (Always in Font 2)
        $metaText = "HAL {$currentPage}/{$totalPages} | TGL: {$castDate}";
        $metaLength = mb_strlen($metaText);
        $metaWidth = $metaLength * self::FONT2_WIDTH;
        $metaX = 690 - $metaWidth; // Right-aligned with 10 dots margin from right border (700)

        $cmds = [];

        // 1. Label setup (TSC TE200 203 DPI, 90 x 50 mm)
        $cmds[] = 'SIZE 90 mm, 50 mm';
        $cmds[] = 'GAP 3 mm, 0';
        $cmds[] = 'DIRECTION 1,0';
        $cmds[] = 'REFERENCE 0,0';
        $cmds[] = 'OFFSET 0 mm';
        $cmds[] = 'CLS';
        $cmds[] = 'SET TEAR ON';

        // 2. Outer boundary box (X: 20..700, Y: 10..390)
        $cmds[] = 'BOX 20,10,700,390,3';

        // 3. Compact Header Section (Font 4 Heat Number + Horizontal Meta in Font 2)
        $cmds[] = 'TEXT 30,16,"' . self::FONT_LARGE . '",0,1,1,"' . $heatNumber . '"';
        $cmds[] = 'TEXT ' . $metaX . ',22,"' . self::FONT_NORMAL . '",0,1,1,"' . $metaText . '"';

        // 4. Header divider (Y = 52)
        $cmds[] = 'BAR 20,52,680,3';

        // 5. Table Header Section in Font 2 (Y = 55..80)
        // Kolom Nama Barang
        $cmds[] = 'TEXT 30,58,"' . self::FONT_NORMAL . '",0,1,1,"NAMA BARANG"';
        // Kolom QTY (Centered at midpoint 530: "QTY" is 3 chars in Font 2 = 36 dots, 530 - 18 = 512)
        $cmds[] = 'TEXT 512,58,"' . self::FONT_NORMAL . '",0,1,1,"QTY"';
        // Kolom RUSAK (Centered at midpoint 635: "RUSAK" is 5 chars in Font 2 = 60 dots, 635 - 30 = 605)
        $cmds[] = 'TEXT 605,58,"' . self::FONT_NORMAL . '",0,1,1,"RUSAK"';

        // 6. Table Header divider (Y = 80)
        $cmds[] = 'BAR 20,80,680,2';

        // 7. Continuous Vertical Column dividers (Running from Y = 52 down to Y = 390)
        $cmds[] = 'BAR ' . self::DIVIDER_1_X . ',52,2,338';
        $cmds[] = 'BAR ' . self::DIVIDER_2_X . ',52,2,338';

        // 8. Dynamic Rows
        $yCurrent = 82;

        foreach ($chunkItems as $itemData) {
            $tree = $itemData['tree'];
            $lines = $itemData['lines'];
            $height = $itemData['height'];

            $qtyStr = (string) ((int) $tree->quantity_pcs);
            $qtyLen = mb_strlen($qtyStr);
            $qtyWidth = $qtyLen * $charWidth;

            // Mathematically centered horizontally under header QTY (midpoint 530)
            // X_start = X_mid - (chars * charWidth / 2)
            $qtyX = (int) round(self::QTY_COL_MID_X - ($qtyWidth / 2));

            // Mathematically centered vertically in the row height: rowStart + (rowHeight - fontHeight) / 2
            $qtyY = (int) floor($yCurrent + ($height - $fontHeight) / 2);

            if (count($lines) === 1) {
                // Single-line row:
                // Font 1 (H=28): text at yCurrent + 8, qty at yCurrent + 8 (spans +8..+20, 8 dots margin top & bottom)
                // Font 2 (H=34): text at yCurrent + 7, qty at yCurrent + 7 (spans +7..+27, 7 dots margin top & bottom)
                $yText = $isFont1 ? $yCurrent + 8 : $yCurrent + 7;
                $cmds[] = 'TEXT ' . self::COL1_LEFT . ',' . $yText . ',"' . $itemFont . '",0,1,1,"' . $lines[0] . '"';
                $cmds[] = 'TEXT ' . $qtyX . ',' . $qtyY . ',"' . $itemFont . '",0,1,1,"' . $qtyStr . '"';
            } else {
                // Double-line row:
                // Font 1 (H=44): line 1 at yCurrent + 8, line 2 at yCurrent + 24, qty at yCurrent + 16
                // Font 2 (H=55): line 1 at yCurrent + 6, line 2 at yCurrent + 28, qty at yCurrent + 17
                $yLine1 = $isFont1 ? $yCurrent + 8 : $yCurrent + 6;
                $yLine2 = $isFont1 ? $yCurrent + 24 : $yCurrent + 28;

                $cmds[] = 'TEXT ' . self::COL1_LEFT . ',' . $yLine1 . ',"' . $itemFont . '",0,1,1,"' . $lines[0] . '"';
                $cmds[] = 'TEXT ' . self::COL1_LEFT . ',' . $yLine2 . ',"' . $itemFont . '",0,1,1,"' . $lines[1] . '"';
                $cmds[] = 'TEXT ' . $qtyX . ',' . $qtyY . ',"' . $itemFont . '",0,1,1,"' . $qtyStr . '"';
            }

            // Draw horizontal row separator
            $yBottom = $yCurrent + $height;
            if ($yBottom < 390) {
                $cmds[] = 'BAR 20,' . $yBottom . ',680,1';
            }

            $yCurrent = $yBottom + 1;
        }

        // Fill remaining vertical space with empty grid lines for consistent operator handwriting boxes
        while ($yCurrent + $singleHeight <= 390) {
            $yBottom = $yCurrent + $singleHeight;
            if ($yBottom < 390) {
                $cmds[] = 'BAR 20,' . $yBottom . ',680,1';
            }
            $yCurrent = $yBottom + 1;
        }

        // 9. Print command for this label
        $cmds[] = 'PRINT 1,1';

        return implode("\r\n", $cmds);
    }

    /**
     * Wrap text into at most $maxLines lines based on character limit per line.
     *
     * @return array<string>
     */
    public function wrapText(string $text, int $limitPerLine = self::FONT1_WRAP_LIMIT, int $maxLines = 2): array
    {
        $sanitized = $this->sanitize($text);
        if ($sanitized === '') {
            return ['-'];
        }

        if (mb_strlen($sanitized) <= $limitPerLine) {
            return [$sanitized];
        }

        $words = preg_split('/\s+/', $sanitized, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $currentLine = '';

        foreach ($words as $i => $word) {
            $space = $currentLine === '' ? '' : ' ';
            if (mb_strlen($currentLine . $space . $word) <= $limitPerLine) {
                $currentLine .= $space . $word;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                } else {
                    // Single word longer than line limit: break it cleanly
                    $lines[] = mb_substr($word, 0, $limitPerLine);
                    $word = mb_substr($word, $limitPerLine);
                }

                if (count($lines) >= $maxLines - 1) {
                    $remainingWords = array_slice($words, $i);
                    $currentLine = implode(' ', $remainingWords);
                    break;
                } else {
                    $currentLine = $word;
                }
            }
        }

        if ($currentLine !== '' && count($lines) < $maxLines) {
            if (mb_strlen($currentLine) > $limitPerLine) {
                $currentLine = mb_substr($currentLine, 0, $limitPerLine - 2) . '..';
            }
            $lines[] = $currentLine;
        }

        return !empty($lines) ? $lines : [$sanitized];
    }

    /**
     * Sanitize string values for TSPL safe printing.
     */
    public function sanitize(string $value): string
    {
        // 1. Replace double quotes (inch symbol or otherwise) with ' IN'
        $value = str_replace('"', ' IN', $value);

        // 2. Replace backslashes with forward slashes
        $value = str_replace('\\', '/', $value);

        // 3. Remove CR/LF
        $value = str_replace(["\r", "\n"], '', $value);

        // 4. Keep only printable ASCII (space to tilde)
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);

        return trim((string) $value);
    }
}
