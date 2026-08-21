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
        $itemName = $tree->getSourceProduct() ?? '-';
        $aisi = $this->sanitize($tree->getSourceAisi() ?? '-');
        $custCode = $this->sanitize($tree->getSourceCustomer() ?? '-');
        $barcode = $this->sanitize($tree->barcode);
        $qty = $tree->quantity;

        $printDate = now()->format('d-m-Y');
        $printTime = now()->format('H:i');

        // Wrap item name: 30 characters per line using Font 2
        $productNameLines = $this->wrapText($itemName, 30, 2);

        $cmds = [];
        $cmds[] = 'SIZE 50 mm, 90 mm';
        $cmds[] = 'GAP 3 mm, 0';
        $cmds[] = 'DIRECTION 1,0';
        $cmds[] = 'REFERENCE 0,0';
        $cmds[] = 'OFFSET 0 mm';
        $cmds[] = 'CLS';
        $cmds[] = 'SET TEAR ON';

        // Y=20: Title Header
        $cmds[] = 'TEXT 200,20,"'.self::FONT_TITLE.'",0,1,1,2,"FORM BARCODE LAPISAN"';
        $cmds[] = 'BAR 10,50,380,3';

        // Y=65: Production Info Block
        $cmds[] = 'TEXT 10,65,"'.self::FONT_NORMAL.'",0,1,1,"KODE PRODUKSI : '.$productionCode.'"';
        $cmds[] = 'TEXT 10,90,"'.self::FONT_NORMAL.'",0,1,1,"KODE ITEM     : '.$itemCode.'"';
        $cmds[] = 'TEXT 10,115,"'.self::FONT_NORMAL.'",0,1,1,"NAMA ITEM     :"';
        $cmds[] = 'TEXT 20,135,"'.self::FONT_NORMAL.'",0,1,1,"'.$productNameLines[0].'"';
        if ($productNameLines[1] !== '') {
            $cmds[] = 'TEXT 20,155,"'.self::FONT_NORMAL.'",0,1,1,"'.$productNameLines[1].'"';
        }
        $cmds[] = 'TEXT 10,180,"'.self::FONT_NORMAL.'",0,1,1,"AISI          : '.$aisi.'"';
        $cmds[] = 'TEXT 10,205,"'.self::FONT_NORMAL.'",0,1,1,"KODE CUST     : '.$custCode.'"';
        $cmds[] = 'BAR 10,230,380,2';

        // Y=250: Barcode & Barcode Text (centered)
        $cmds[] = 'BARCODE 60,250,"128",140,0,0,2,4,"'.$barcode.'"';
        $cmds[] = 'TEXT 200,400,"'.self::FONT_TITLE.'",0,1,1,2,"'.$barcode.'"';

        // Y=440: Quantity Box (centered)
        $cmds[] = 'TEXT 200,440,"'.self::FONT_TITLE.'",0,1,1,2,"ISI RANGKAIAN"';
        $cmds[] = 'TEXT 200,475,"4",0,1,1,2,"'.$qty.' PCS"';
        $cmds[] = 'BAR 10,525,380,2';

        // Y=545: Lower metadata & placeholders
        $cmds[] = 'TEXT 10,545,"'.self::FONT_NORMAL.'",0,1,1,"TANGGAL PRINT : '.$printDate.'"';
        $cmds[] = 'TEXT 10,570,"'.self::FONT_NORMAL.'",0,1,1,"JAM PRINT     : '.$printTime.'"';
        $cmds[] = 'TEXT 10,595,"'.self::FONT_NORMAL.'",0,1,1,"KODE RAK      : __________"';
        $cmds[] = 'TEXT 10,620,"'.self::FONT_NORMAL.'",0,1,1,"KETERANGAN    : __________"';

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
        $value = str_replace(['"', "\r", "\n"], '', $value);

        return preg_replace('/[^\x20-\x7E]/', '', $value);
    }
}
