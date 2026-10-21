<?php

namespace App;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;
use ZipArchive;

class ReliefPurchasesWorkbook
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @param array<string, mixed> $company
     * @param  list<list<string|int|float>>  $rows
     */
    public function create(array $company, string $from, string $to, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'relief-purchases-');
        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($path === false || ! copy(resource_path('templates/relief-purchases.xlsx'), $path) || $zip->open($path) !== true) {
                throw new RuntimeException('The Purchases Excel template could not be opened.');
            }
            $opened = true;
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', self::XML_NAMESPACE);
            $strings = new DOMDocument;
            $strings->loadXML($zip->getFromName('xl/sharedStrings.xml'), LIBXML_NONET);
            $shared = [];
            foreach ($strings->getElementsByTagNameNS(self::XML_NAMESPACE, 'si') as $item) {
                $shared[] = $item->textContent;
            }
            foreach ($xpath->query('//s:c[@t="s"]') as $cell) {
                $this->setCell($document, $cell, $shared[(int) $cell->textContent] ?? '');
            }
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);
            $period = $start->isStartOfMonth() && $end->isSameDay($start->endOfMonth())
                ? 'FOR THE MONTH OF : '.mb_strtoupper($start->format('F Y'))
                : 'FOR THE PERIOD : '.mb_strtoupper($start->format('F j, Y').' TO '.$end->format('F j, Y'));
            $address = implode(', ', array_filter(array_map(fn (string $key): string => trim((string) ($company[$key] ?? '')), ['building', 'street', 'barangay', 'city', 'province', 'zip_code'])));
            $fullTin = Tin::format($company['tin'] ?? '000000000', $company['branch_code'] ?? null);
            $headings = ['A3' => $period, 'B5' => implode('-', array_slice(explode('-', $fullTin), 0, 3)), 'B6' => $company['registered_name'] ?? $company['company_name'], 'B7' => $company['trade_name'] ?? $company['company_name'], 'B8' => $address ?: ($company['address'] ?? '')];
            foreach ($headings as $reference => $value) {
                $this->setCell($document, $xpath->query('//s:c[@r="'.$reference.'"]')->item(0), $value);
            }
            $sheetData = $xpath->query('//s:sheetData')->item(0);
            $detail = $xpath->query('//s:row[@r="14"]')->item(0)->cloneNode(true);
            $grand = $xpath->query('//s:row[@r="15"]')->item(0)->cloneNode(true);
            $footers = [];
            foreach ([17, 19, 20] as $number) {
                $footers[] = $xpath->query('//s:row[@r="'.$number.'"]')->item(0)->cloneNode(true);
            }
            foreach (iterator_to_array($sheetData->childNodes) as $row) {
                if ($row instanceof DOMElement && (int) $row->getAttribute('r') >= 14) {
                    $sheetData->removeChild($row);
                }
            }
            $totals = array_fill(0, 14, 0);
            foreach ($rows as $index => $values) {
                $row = $detail->cloneNode(false);
                $row->setAttribute('r', (string) ($index + 14));
                foreach ($values as $column => $value) {
                    $letter = chr(65 + $column);
                    $cell = $document->createElementNS(self::XML_NAMESPACE, 'c');
                    $cell->setAttribute('r', $letter.($index + 14));
                    $cell->setAttribute('s', $column === 0 ? '4' : ($column >= 5 ? '6' : '5'));
                    if ($column === 0) {
                        $value = (int) CarbonImmutable::parse($value.'-01')->endOfMonth()->startOfDay()->diffInDays(CarbonImmutable::parse('1899-12-30'), true);
                    } elseif ($column >= 5) {
                        $cents = (int) round((float) $value * 100);
                        $totals[$column] += $cents;
                        $value = $cents / 100;
                    }
                    $this->setCell($document, $cell, $value, $column === 0 || $column >= 5);
                    $row->appendChild($cell);
                }
                $sheetData->appendChild($row);
            }
            $grandNumber = count($rows) + 14;
            $grand->setAttribute('r', (string) $grandNumber);
            foreach ($grand->getElementsByTagNameNS(self::XML_NAMESPACE, 'c') as $cell) {
                $letter = preg_replace('/\d+/', '', $cell->getAttribute('r'));
                $column = ord($letter) - 65;
                $cell->setAttribute('r', $letter.$grandNumber);
                if ($column >= 5) {
                    $this->setCell($document, $cell, $totals[$column] / 100, true);
                    $formula = $document->createElementNS(self::XML_NAMESPACE, 'f', $rows === [] ? 'SUM(0)' : 'SUM('.$letter.'14:'.$letter.($grandNumber - 1).')');
                    $cell->insertBefore($formula, $cell->firstChild);
                }
            }
            $sheetData->appendChild($grand);
            $footerValues = ['END OF REPORT', (($company['vat_type'] ?? '') === 'vat' ? 'VAT REG TIN ' : 'NON-VAT REG TIN ').$fullTin, 'Software: kontadurya.io · Generated by '.($company['full_name'] ?? 'Workspace user').' on '.now('Asia/Manila')->format('M j, Y, g:i A').' (PHT)'];
            $unclassified = count(array_filter($rows, fn (array $row): bool => (int) round((float) $row[8] * 100) !== (int) round(((float) $row[9] + (float) $row[10] + (float) $row[11]) * 100)));
            if ($unclassified > 0) {
                $footerValues[2] .= ' · REVIEW: '.$unclassified.' row(s) have unclassified VAT purchase amounts. Set the category in Masters.';
            }
            foreach ([2, 4, 5] as $index => $offset) {
                $row = $footers[$index];
                $row->setAttribute('r', (string) ($grandNumber + $offset));
                $cell = $row->getElementsByTagNameNS(self::XML_NAMESPACE, 'c')->item(0);
                $cell->setAttribute('r', 'A'.($grandNumber + $offset));
                $this->setCell($document, $cell, $footerValues[$index]);
                $sheetData->appendChild($row);
            }
            $xpath->query('//s:dimension')->item(0)->setAttribute('ref', 'A1:N'.($grandNumber + 5));
            $zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML());
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="'.self::XML_NAMESPACE.'" count="0" uniqueCount="0"/>');
            $zip->close();
            $opened = false;

            return $path;
        } catch (Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }

    private function setCell(DOMDocument $document, DOMElement $cell, string|int|float $value, bool $numeric = false): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->setAttribute('t', $numeric ? 'n' : 'inlineStr');
        if ($numeric) {
            $cell->appendChild($document->createElementNS(self::XML_NAMESPACE, 'v', number_format((float) $value, 2, '.', '')));
        } else {
            $inline = $document->createElementNS(self::XML_NAMESPACE, 'is');
            $text = $document->createElementNS(self::XML_NAMESPACE, 't');
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $text->appendChild($document->createTextNode(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value)));
            $inline->appendChild($text);
            $cell->appendChild($inline);
        }
    }
}
