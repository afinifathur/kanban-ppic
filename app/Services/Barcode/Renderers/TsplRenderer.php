<?php

namespace App\Services\Barcode\Renderers;

class TsplRenderer
{
    private const FONT_TITLE = '3'; // Font 3 (16x24 dots)

    private const FONT_NORMAL = '2'; // Font 2 (12x20 dots)

    private const FONT_SMALL = '1'; // Font 1 (8x12 dots)

    public function render(\App\Models\LostWaxTree $tree): string
    {
        $productionDate = $tree->production_date ? $tree->production_date->format('d-m-Y') : '-';
        $wave = $tree->plan?->wave_number ? str_pad((string) $tree->plan->wave_number, 3, '0', STR_PAD_LEFT) : '-';
        $custCode = $this->sanitize($tree->getSourceCode() ?? '-');
        $qty = $tree->quantity;
        $customer = $tree->getSourceCustomer() ?? '-';
        $product = $tree->getSourceProduct() ?? '-';
        $itemCode = $this->sanitize($tree->getSourceItemCode() ?? '-');
        $aisi = $this->sanitize($tree->getSourceAisi() ?? '-');
        $size = $this->sanitize($tree->getSourceSize() ?? '-');
        $stage = $this->sanitize($tree->current_stage_label);
        $treeNumber = str_pad((string) $tree->tree_number, 3, '0', STR_PAD_LEFT);
        $barcode = $this->sanitize($tree->barcode);
        $printOrderNumber = $this->sanitize($tree->getSourcePrintOrderNumber() ?? '-');

        // Customer & Product name wrapping (limit to 30 characters per line using Font 2)
        $customerLines = $this->wrapText($customer, 30, 2);
        $productLines = $this->wrapText($product, 30, 3);

        $cmds = [];
        $cmds[] = 'SIZE 50 mm, 90 mm';
        $cmds[] = 'GAP 3 mm, 0';
        $cmds[] = 'DIRECTION 1,0';
        $cmds[] = 'REFERENCE 0,0';
        $cmds[] = 'OFFSET 0 mm';
        $cmds[] = 'CLS';
        $cmds[] = 'SET TEAR ON';

        // Y=20: Title Header
        $cmds[] = 'TEXT 200,20,"'.self::FONT_TITLE.'",0,1,1,2,"LOST WAX TRAVELER"';
        $cmds[] = 'BAR 10,50,380,3';

        // Y=65: Tgl & Wave
        $cmds[] = 'TEXT 10,65,"'.self::FONT_NORMAL."\",0,1,1,\"TGL: $productionDate\"";
        $cmds[] = 'TEXT 390,65,"'.self::FONT_NORMAL."\",0,1,1,3,\"WAVE: $wave\"";

        // Y=100: Cust Code & Qty
        $cmds[] = 'TEXT 10,100,"'.self::FONT_TITLE."\",0,1,1,\"CUST: $custCode\"";
        $cmds[] = "TEXT 390,100,\"4\",0,1,1,3,\"$qty PCS\"";

        // Y=140: Customer Name (Wrapped)
        $cmds[] = 'TEXT 10,140,"'.self::FONT_NORMAL.'",0,1,1,"C: '.$customerLines[0].'"';
        if ($customerLines[1] !== '') {
            $cmds[] = 'TEXT 10,165,"'.self::FONT_NORMAL.'",0,1,1,"   '.$customerLines[1].'"';
        }

        // Y=200: Product Name (Wrapped)
        $cmds[] = 'TEXT 10,200,"'.self::FONT_NORMAL.'",0,1,1,"P: '.$productLines[0].'"';
        if ($productLines[1] !== '') {
            $cmds[] = 'TEXT 10,225,"'.self::FONT_NORMAL.'",0,1,1,"   '.$productLines[1].'"';
        }
        if ($productLines[2] !== '') {
            $cmds[] = 'TEXT 10,250,"'.self::FONT_NORMAL.'",0,1,1,"   '.$productLines[2].'"';
        }

        // Y=285: Item Code (Unwrapped)
        $cmds[] = 'TEXT 10,285,"'.self::FONT_NORMAL."\",0,1,1,\"ITEM: $itemCode\"";

        // Y=320: AISI & Size
        $cmds[] = 'TEXT 10,320,"'.self::FONT_NORMAL."\",0,1,1,\"AISI: $aisi\"";
        $cmds[] = 'TEXT 390,320,"'.self::FONT_NORMAL."\",0,1,1,3,\"SIZE: $size\"";

        // Y=355: Stage & Tree Number
        $cmds[] = 'TEXT 10,355,"'.self::FONT_NORMAL."\",0,1,1,\"STAGE: $stage\"";
        $cmds[] = 'TEXT 390,355,"'.self::FONT_NORMAL."\",0,1,1,3,\"TREE: $treeNumber\"";

        $cmds[] = 'BAR 10,385,380,3';

        // Y=410: Barcode Code 128 (Narrow bar width = 2, Wide bar width = 4, Height = 160 dots)
        // With 10 char barcode, width is ~286 dots, centered around X=60
        $cmds[] = "BARCODE 60,410,\"128\",160,0,0,2,4,\"$barcode\"";

        // Y=590: Barcode Text under barcode
        $cmds[] = 'TEXT 200,590,"'.self::FONT_TITLE."\",0,1,1,2,\"$barcode\"";

        // Y=630: Work Order/Print Order reference
        $cmds[] = 'TEXT 200,630,"'.self::FONT_NORMAL."\",0,1,1,2,\"ORD: $printOrderNumber\"";

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
