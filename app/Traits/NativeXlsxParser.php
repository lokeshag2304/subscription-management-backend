<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use ZipArchive;

trait NativeXlsxParser
{
    /**
     * Natively converts an XLSX file to a CSV string without using heavy libraries.
     * Robust against namespaces, formatted shared strings, and various sheet names.
     */
    protected function parseXlsxToCsvString($filePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            Log::error("XLSX Parser: Could not open zip file at $filePath");
            return "";
        }

        // 1. Load Shared Strings (Excel's text database)
        $sharedStrings = [];
        $stringsRaw = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsRaw) {
            // Nuke all namespaces and prefixes to make it readable
            $stringsRaw = preg_replace('/ xmlns(:\w+)?="[^"]*"/i', '', $stringsRaw);
            $stringsRaw = preg_replace('/<(\/?)[a-z0-9]+:([a-z0-9]+)/i', '<$1$2', $stringsRaw);
            
            $xmlS = @simplexml_load_string($stringsRaw);
            if ($xmlS && isset($xmlS->si)) {
                foreach ($xmlS->si as $si) {
                    $val = "";
                    if (isset($si->t) && (string)$si->t !== "") {
                        $val = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            if (isset($r->t)) $val .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $val;
                }
            }
        }

        // 2. Load Sheet1 (or fallback)
        $sheetWhole = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetWhole) {
            $sheetWhole = $zip->getFromName('xl/worksheets/sheet.xml');
        }
        
        if (!$sheetWhole) {
            Log::error("XLSX Parser: worksheet XML not found in ZIP");
            $zip->close(); 
            return ""; 
        }
        
        // Nuke all namespaces and prefixes for the sheet data
        $sheetData = preg_replace('/ xmlns(:\w+)?="[^"]*"/i', '', $sheetWhole);
        $sheetData = preg_replace('/<(\/?)[a-z0-9]+:([a-z0-9]+)/i', '<$1$2', $sheetData);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($sheetData);
        if (!$xml) { 
            $xmlErrors = libxml_get_errors();
            Log::error("XLSX Parser: XML Parse Failure", ['errors' => $xmlErrors]);
            libxml_clear_errors();
            $zip->close(); 
            return ""; 
        }

        $output = fopen('php://temp', 'r+');
        $rows = $xml->xpath('//row');
        if (!$rows) $rows = $xml->sheetData->row ?? [];

        foreach ($rows as $row) {
            $cells = [];
            $cellNodes = $row->xpath('c');
            if (!$cellNodes) $cellNodes = $row->c ?? [];

            foreach ($cellNodes as $cell) {
                $ref = (string)$cell['r'];
                $colLetter = preg_replace('/[0-9]/', '', $ref);
                if (!$colLetter) continue;

                $targetIndex = 0;
                $len = strlen($colLetter);
                for ($i = 0; $i < $len; $i++) {
                    $targetIndex = $targetIndex * 26 + (ord($colLetter[$i]) - 64);
                }
                $targetIndex -= 1;

                // Value extraction
                $value = "";
                if (isset($cell->v)) {
                    $value = (string)$cell->v;
                } elseif (isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                } elseif (isset($cell->t)) {
                    $value = (string)$cell->t;
                }

                // Shared string lookup
                if ((string)$cell['t'] === 's' && $value !== "") {
                    $value = $sharedStrings[(int)$value] ?? $value;
                }
                $cells[$targetIndex] = $value;
            }

            // Continuous array for CSV
            $maxIdx = count($cells) > 0 ? max(array_keys($cells)) : 0;
            $rowData = [];
            for ($i = 0; $i <= $maxIdx; $i++) {
                $rowData[$i] = $cells[$i] ?? "";
            }
            fputcsv($output, $rowData);
        }

        rewind($output);
        $csvString = stream_get_contents($output);
        fclose($output);
        $zip->close();

        return $csvString;
    }
}
