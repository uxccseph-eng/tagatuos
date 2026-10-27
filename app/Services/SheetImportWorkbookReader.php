<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class SheetImportWorkbookReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @return array{worksheets: list<string>, selected: int, rows: list<list<string>>} */
    public function read(string $path, int $selected = 0): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Choose a valid .xlsx workbook. Old .xls files are not supported.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2000) {
                throw new RuntimeException('The workbook has too many internal files.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $total += $stat['size'];
                if ($total > 12 * 1024 * 1024 || $stat['size'] > 4 * 1024 * 1024 || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Use a smaller, unencrypted workbook with up to 500 data rows.');
                }
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($relations->getElementsByTagName('Relationship') as $relationship) {
                if ($relationship->getAttribute('TargetMode') !== 'External' && str_ends_with($relationship->getAttribute('Type'), '/worksheet')) {
                    $target = $relationship->getAttribute('Target');
                    $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                    if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#D', $target)) {
                        $targets[$relationship->getAttribute('Id')] = $target;
                    }
                }
            }
            $sheets = [];
            foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
                $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (isset($targets[$id])) {
                    $sheets[] = ['name' => mb_substr($sheet->getAttribute('name'), 0, 100), 'path' => $targets[$id]];
                }
            }
            if (! isset($sheets[$selected]) || count($sheets) > 50) {
                throw new RuntimeException('Choose an available worksheet in a workbook with at most 50 sheets.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->getElementsByTagNameNS(self::NS, 'si') as $item) {
                    $strings[] = $this->text($item);
                }
            }
            $dateStyles = [];
            if ($zip->locateName('xl/styles.xml') !== false) {
                $styles = $this->xml($zip, 'xl/styles.xml');
                $formats = [];
                foreach ($styles->getElementsByTagNameNS(self::NS, 'numFmt') as $format) {
                    $formats[$format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
                }
                $xpath = new DOMXPath($styles);
                $xpath->registerNamespace('s', self::NS);
                foreach ($xpath->query('//s:cellXfs/s:xf') as $index => $style) {
                    $id = (int) $style->getAttribute('numFmtId');
                    $format = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/u', '', $formats[$id] ?? '');
                    $dateStyles[$index] = ($id >= 14 && $id <= 22) || (bool) preg_match('/[ymd]/i', $format);
                }
            }
            $properties = $workbook->getElementsByTagNameNS(self::NS, 'workbookPr')->item(0);
            $date1904 = $properties && in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
            $matrix = [];
            foreach ($this->xml($zip, $sheets[$selected]['path'])->getElementsByTagNameNS(self::NS, 'row') as $row) {
                $values = [];
                foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $cell) {
                    if ($cell->getElementsByTagNameNS(self::NS, 'f')->length) {
                        throw new RuntimeException('The worksheet contains formulas. Paste values into a copy before importing.');
                    }
                    $type = $cell->getAttribute('t');
                    $value = $type === 'inlineStr' ? $this->text($cell) : ($cell->getElementsByTagNameNS(self::NS, 'v')->item(0)?->textContent ?? '');
                    if ($type === 's') {
                        if (! preg_match('/^\d+$/D', $value) || ! array_key_exists((int) $value, $strings)) {
                            throw new RuntimeException('The workbook contains an invalid shared string.');
                        }
                        $value = $strings[(int) $value];
                    }
                    if ($type === 'e') {
                        throw new RuntimeException('Resolve Excel cell errors before importing.');
                    }
                    if ($value === '') {
                        continue;
                    }
                    if (mb_strlen($value) > 4000) {
                        throw new RuntimeException('A workbook cell exceeds the 4,000 character limit.');
                    }
                    if (in_array($type, ['', 'n'], true) && ($dateStyles[(int) $cell->getAttribute('s')] ?? false)) {
                        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
                            throw new RuntimeException('Invalid Excel date value.');
                        }
                        $serial = (int) explode('.', $value)[0];
                        if ($serial > 2958465 || (! $date1904 && $serial === 60)) {
                            throw new RuntimeException('Invalid Excel date value.');
                        }
                        $base = $date1904 ? '1904-01-01' : ($serial < 60 ? '1899-12-31' : '1899-12-30');
                        $value = CarbonImmutable::parse($base, 'UTC')->addDays($serial)->format('Y-m-d');
                    }
                    if (! preg_match('/^([A-Z]{1,3})([0-9]+)$/D', $cell->getAttribute('r'), $match) || $match[2] !== $row->getAttribute('r')) {
                        throw new RuntimeException('Invalid Excel cell reference.');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 100) {
                        throw new RuntimeException('Use at most 100 columns.');
                    }
                    if (array_key_exists($column - 1, $values)) {
                        throw new RuntimeException('The workbook contains duplicate cell references.');
                    }
                    $values[$column - 1] = $value;
                }
                if ($values === []) {
                    continue;
                }
                $number = (int) $row->getAttribute('r');
                if ($number < 1 || $number > 521) {
                    throw new RuntimeException('Use up to 500 data rows and at most 20 rows before the header.');
                }
                if (array_key_exists($number - 1, $matrix)) {
                    throw new RuntimeException('The workbook contains duplicate row references.');
                }
                $matrix[$number - 1] = array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values);
            }
            if ($matrix === []) {
                throw new RuntimeException('The worksheet is empty.');
            }

            return ['worksheets' => array_column($sheets, 'name'), 'selected' => $selected, 'rows' => array_replace(array_fill(0, max(array_keys($matrix)) + 1, []), $matrix)];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $path): DOMDocument
    {
        $xml = $zip->getFromName($path, 4 * 1024 * 1024 + 1);
        if ($xml === false || strlen($xml) > 4 * 1024 * 1024 || substr_count($xml, '<') > 120000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('The workbook contains unsupported XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('The workbook XML is invalid.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(DOMElement $element): string
    {
        $text = '';
        foreach ($element->getElementsByTagNameNS(self::NS, 't') as $part) {
            $text .= $part->textContent;
        }

        return $text;
    }
}
