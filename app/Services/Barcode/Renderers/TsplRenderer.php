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

        // Y=18: Title Header (centered at X=360)
        $cmds[] = 'TEXT 360,18,"'.self::FONT_TITLE.'",0,1,1,2,"FORM BARCODE LAPISAN"';

        // Horizontal Dividers
        $cmds[] = 'BAR 20,45,680,3';
        $cmds[] = 'BAR 20,190,680,3';
        $cmds[] = 'BAR 20,320,680,3';

        // Vertical Dividers
        $cmds[] = 'BAR 460,190,3,130';
        $cmds[] = 'BAR 360,320,3,70';

        // Y=55: Production Info Block
        $cmds[] = 'TEXT 30,55,"'.self::FONT_NORMAL.'",0,1,1,"KODE PRODUKSI : '.$productionCode.'"';
        $cmds[] = 'TEXT 30,77,"'.self::FONT_NORMAL.'",0,1,1,"KODE ITEM     : '.$itemCode.'"';
        $cmds[] = 'TEXT 30,99,"'.self::FONT_NORMAL.'",0,1,1,"NAMA ITEM     : '.$productNameLines[0].'"';
        if ($productNameLines[1] !== '') {
            $cmds[] = 'TEXT 30,121,"'.self::FONT_NORMAL.'",0,1,1,"                '.$productNameLines[1].'"';
        }
        $cmds[] = 'TEXT 30,143,"'.self::FONT_NORMAL.'",0,1,1,"AISI          : '.$aisi.'"';
        $cmds[] = 'TEXT 30,165,"'.self::FONT_NORMAL.'",0,1,1,"KODE CUST     : '.$custCode.'"';

        // Barcode & Isi Rangkaian
        // Left side: Barcode & Human-readable number
        $cmds[] = 'BARCODE 80,195,"128",100,0,0,2,4,"'.$barcode.'"';
        $cmds[] = 'TEXT 240,298,"'.self::FONT_NORMAL.'",0,1,1,2,"'.$barcode.'"';

        // Right side: Isi Rangkaian
        $cmds[] = 'TEXT 580,205,"'.self::FONT_NORMAL.'",0,1,1,2,"ISI RANGKAIAN"';
        $cmds[] = 'TEXT 580,240,"4",0,1,1,2,"'.$qty.' PCS"';

        // Bottom Metadata
        $cmds[] = 'TEXT 30,332,"'.self::FONT_NORMAL.'",0,1,1,"TGL PRINT     : '.$printDate.'"';
        $cmds[] = 'TEXT 30,362,"'.self::FONT_NORMAL.'",0,1,1,"JAM PRINT     : '.$printTime.'"';
        $cmds[] = 'TEXT 380,332,"'.self::FONT_NORMAL.'",0,1,1,"KODE RAK : '.$rackPlaceholder.'"';
        $cmds[] = 'TEXT 380,362,"'.self::FONT_NORMAL.'",0,1,1,"KETERANGAN: '.$descPlaceholder.'"';

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
