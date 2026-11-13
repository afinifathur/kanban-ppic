<?php

namespace App\Services\Barcode\Renderers;

class TsplRenderer
{
    private const FONT_TITLE = '3'; // Font 3 (16x24 dots)

    private const FONT_NORMAL = '2'; // Font 2 (12x20 dots)

    private const FONT_SMALL = '1'; // Font 1 (8x12 dots)

    public function render(\App\Models\LostWaxTree $tree): string
    {
        $productionCode = $tree->lost_wax_print_order_line_id && $tree->printOrderLine?->productionPlan
            ? ($tree->printOrderLine->productionPlan->code ?? '-')
            : ($tree->getSourceCode() ?? '-');
        $productionCode = $this->sanitize($productionCode);

        $itemCode = $this->sanitize($tree->getSourceItemCode() ?? '-');
        $itemName = $this->sanitize($tree->getSourceProduct() ?? '-');
        $size = $this->sanitize($tree->getSourceSize() ?? '-');
        $aisi = $this->sanitize($tree->getSourceAisi() ?? '-');
        $custCode = $this->sanitize($tree->getSourceCustomer() ?? '-');
        $barcode = $this->sanitize($tree->barcode);
        $qty = $this->sanitize((string) $tree->quantity);

        $printDate = $this->sanitize(now()->format('d-m-Y'));
        $printTime = $this->sanitize(now()->format('H:i'));
        $rackNumber = $tree->coatingRack?->rack_number
            ? 'RAK-'.str_pad((string) $tree->coatingRack->rack_number, 2, '0', STR_PAD_LEFT)
            : 'Belum diisi';
        $rackPlaceholder = $this->sanitize($rackNumber);
        $descPlaceholder = $this->sanitize('_____________');

        // Wrap item name: 23 characters per line using Font 2 (word-wrapped to show maximum 2 lines)
        $productNameLines = $this->wrapText($itemName, 23, 2);

        $cmds = [];
        $cmds[] = 'SIZE 90 mm, 50 mm';
        $cmds[] = 'GAP 3 mm, 0';
        $cmds[] = 'DIRECTION 1,0';
        $cmds[] = 'REFERENCE 0,0';
        $cmds[] = 'OFFSET 0 mm';
        $cmds[] = 'CLS';
        $cmds[] = 'SET TEAR ON';

        // Outer Border Box
        $cmds[] = 'BOX 20,10,700,390,3';

        // Title Header (centered at X=360)
        $cmds[] = 'TEXT 360,13,"'.self::FONT_TITLE.'",0,1,1,2,"FORM BARCODE LAPISAN"';

        // Horizontal Dividers
        $cmds[] = 'BAR 20,38,680,3';
        $cmds[] = 'BAR 20,183,680,3';
        $cmds[] = 'BAR 20,360,680,3';

        // Vertical Dividers
        $cmds[] = 'BAR 275,183,3,177';
        $cmds[] = 'BAR 460,183,3,177';
        $cmds[] = 'BAR 360,360,3,30';

        // Production Info Block
        if ($productNameLines[1] !== '') {
            $cmds[] = 'TEXT 30,42,"'.self::FONT_NORMAL.'",0,1,1,"KODE PRODUKSI : '.$productionCode.'"';
            $cmds[] = 'TEXT 30,62,"'.self::FONT_NORMAL.'",0,1,1,"KODE ITEM     : '.$itemCode.'"';
            $cmds[] = 'TEXT 30,82,"'.self::FONT_NORMAL.'",0,1,1,"NAMA ITEM     : '.$productNameLines[0].'"';
            $cmds[] = 'TEXT 30,102,"'.self::FONT_NORMAL.'",0,1,1,"                '.$productNameLines[1].'"';
            $cmds[] = 'TEXT 30,122,"'.self::FONT_NORMAL.'",0,1,1,"UKURAN        : '.$size.'"';
            $cmds[] = 'TEXT 30,142,"'.self::FONT_NORMAL.'",0,1,1,"AISI          : '.$aisi.'"';
            $cmds[] = 'TEXT 30,162,"'.self::FONT_NORMAL.'",0,1,1,"KODE CUST     : '.$custCode.'"';
        } else {
            $cmds[] = 'TEXT 30,45,"'.self::FONT_NORMAL.'",0,1,1,"KODE PRODUKSI : '.$productionCode.'"';
            $cmds[] = 'TEXT 30,67,"'.self::FONT_NORMAL.'",0,1,1,"KODE ITEM     : '.$itemCode.'"';
            $cmds[] = 'TEXT 30,89,"'.self::FONT_NORMAL.'",0,1,1,"NAMA ITEM     : '.$productNameLines[0].'"';
            $cmds[] = 'TEXT 30,111,"'.self::FONT_NORMAL.'",0,1,1,"UKURAN        : '.$size.'"';
            $cmds[] = 'TEXT 30,133,"'.self::FONT_NORMAL.'",0,1,1,"AISI          : '.$aisi.'"';
            $cmds[] = 'TEXT 30,155,"'.self::FONT_NORMAL.'",0,1,1,"KODE CUST     : '.$custCode.'"';
        }

        // Barcode & Isi Rangkaian
        // Left side: Barcode 1D & Human-readable number
        $cmds[] = 'BARCODE 60,205,"128",90,0,0,2,4,"'.$barcode.'"';
        $cmds[] = 'TEXT 150,312,"'.self::FONT_NORMAL.'",0,1,1,2,"'.$barcode.'"';

        // QR Code (Center: Enlarged 1.5x with cell_width = 6, ISO 4-module quiet zone)
        $cmds[] = 'QRCODE 306,210,M,6,A,0,"'.$barcode.'"';

        // Right side: Isi Rangkaian
        $cmds[] = 'TEXT 580,225,"'.self::FONT_NORMAL.'",0,1,1,2,"ISI RANGKAIAN"';
        $cmds[] = 'TEXT 580,265,"4",0,1,1,2,"'.$qty.' PCS"';

        // Bottom Metadata (Font 1)
        $cmds[] = 'TEXT 30,364,"'.self::FONT_SMALL.'",0,1,1,"TGL PRINT     : '.$printDate.'"';
        $cmds[] = 'TEXT 30,376,"'.self::FONT_SMALL.'",0,1,1,"JAM PRINT     : '.$printTime.'"';
        $cmds[] = 'TEXT 380,364,"'.self::FONT_SMALL.'",0,1,1,"KODE RAK : '.$rackPlaceholder.'"';
        $cmds[] = 'TEXT 380,376,"'.self::FONT_SMALL.'",0,1,1,"KETERANGAN: '.$descPlaceholder.'"';

        $cmds[] = 'PRINT 1,1';

        return implode("\r\n", $cmds)."\r\n";
    }

    private function wrapText(string $text, int $limitPerLine, int $maxLines = 2): array
    {
        $text = trim($text);
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if (empty($word)) {
                continue;
            }

            $space = $currentLine === '' ? '' : ' ';
            if (mb_strlen($currentLine.$space.$word) <= $limitPerLine) {
                $currentLine .= $space.$word;
            } else {
                if (count($lines) < $maxLines - 1) {
                    $lines[] = $currentLine;
                    if (mb_strlen($word) > $limitPerLine) {
                        $currentLine = mb_substr($word, 0, $limitPerLine - 3).'...';
                        $lines[] = $currentLine;
                        $currentLine = '';
                        break;
                    }
                    $currentLine = $word;
                } else {
                    // Append word or ellipsis to the last line
                    if (mb_strlen($currentLine.$space.$word) <= $limitPerLine) {
                        $currentLine .= $space.$word;
                    } else {
                        if (mb_strlen($currentLine) > $limitPerLine - 3) {
                            $currentLine = mb_substr($currentLine, 0, $limitPerLine - 3).'...';
                        } else {
                            $currentLine .= '...';
                        }
                    }
                    break;
                }
            }
        }
        if ($currentLine !== '' && count($lines) < $maxLines) {
            $lines[] = $currentLine;
        }

        while (count($lines) < $maxLines) {
            $lines[] = '';
        }

        return $lines;
    }

    private function sanitize(string $value): string
    {
        // Replace double quotes (representing inches or otherwise) with ' IN'
        $value = str_replace('"', ' IN', $value);

        // Remove CR/LF/newline characters
        $value = str_replace(["\r", "\n"], '', $value);

        // Remove non-printable control characters (keep only printable ASCII 32 to 126)
        return preg_replace('/[^\x20-\x7E]/', '', $value);
    }
}
