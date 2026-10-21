<?php

namespace App;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;
use ZipArchive;

class ReliefSalesWorkbook
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NUMERIC_COLUMNS = [3, 4, 5, 6, 8, 9, 10];

    /**
     * @param  array<string, mixed>  $company
     * @param  list<list<string|int|float>>  $rows
     */
    public function create(array $company, string $from, string $to, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'relief-sales-');
        $zip = new ZipArchive;
        $opened = false;

        try {
            if ($path === false || ! copy(resource_path('templates/relief-sales.xlsx'), $path) || $zip->open($path) !== true) {
                throw new RuntimeException('The Sales Excel template could not be opened.');
            }
            $opened = true;
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', self::XML_NAMESPACE);
            $sheetData = $xpath->query('//s:sheetData')->item(0);
            $stripedRow = $xpath->query('//s:row[@r="13"]')->item(0)->cloneNode(true);
            $plainRow = $xpath->query('//s:row[@r="14"]')->item(0)->cloneNode(true);
            $totalRow = $xpath->query('//s:row[@r="16"]')->item(0)->cloneNode(true);

            foreach ($this->headings($company, $from, $to) as $index => $heading) {
                $this->setCell($document, $xpath->query('//s:c[@r="A'.($index + 1).'"]')->item(0), $heading);
            }
            foreach (iterator_to_array($sheetData->childNodes) as $row) {
                if ($row instanceof DOMElement && (int) $row->getAttribute('r') >= 13) {
                    $sheetData->removeChild($row);
                }
            }
            $totals = array_fill(0, 11, '');
            $totals[0] = 'Total';
            foreach (self::NUMERIC_COLUMNS as $column) {
                $totals[$column] = 0;
            }
            foreach ($rows as $index => $values) {
                foreach (self::NUMERIC_COLUMNS as $column) {
                    $cents = (int) round((float) $values[$column] * 100);
                    $values[$column] = $cents / 100;
                    $totals[$column] += $cents;
                }
                $sheetData->appendChild($this->fillRow($document, $index % 2 === 0 ? $stripedRow : $plainRow, $index + 13, $values));
            }
            foreach (self::NUMERIC_COLUMNS as $column) {
                $totals[$column] /= 100;
            }
            $lastRow = count($rows) + 13;
            $sheetData->appendChild($this->fillRow($document, $totalRow, $lastRow, $totals));
            $xpath->query('//s:dimension')->item(0)->setAttribute('ref', 'A1:K'.$lastRow);
            $zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML());

            $workbook = new DOMDocument;
            $workbook->loadXML($zip->getFromName('xl/workbook.xml'), LIBXML_NONET);
            $workbookXpath = new DOMXPath($workbook);
            $workbookXpath->registerNamespace('s', self::XML_NAMESPACE);
            $workbookXpath->query('//s:definedName[@name="_xlnm.Print_Area"]')->item(0)->nodeValue = "'RELIEF Sales'!\$A\$1:\$K\$".$lastRow;
            $zip->addFromString('xl/workbook.xml', $workbook->saveXML());
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

    /**
     * @param  array<string, mixed>  $company
     * @return list<string>
     */
    private function headings(array $company, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $kind = $start->addYear()->subDay()->isSameDay($end) ? 'year' : ($start->addMonthsNoOverflow(3)->subDay()->isSameDay($end) ? 'quarter' : ($start->addMonthNoOverflow()->subDay()->isSameDay($end) ? 'month' : 'period'));
        $address = implode(', ', array_filter(array_map(fn (string $key): string => trim((string) ($company[$key] ?? '')), ['building', 'street', 'barangay', 'city', 'province', 'zip_code'])));

        return [
            $company['registered_name'] ?? $company['company_name'],
            'Trade name: '.($company['trade_name'] ?? $company['company_name']),
            (($company['vat_type'] ?? '') === 'vat' ? 'VAT REG TIN ' : 'NON-VAT REG TIN ').Tin::format($company['tin'] ?? '000000000', $company['branch_code'] ?? '00000'),
            $address ?: 'Address not provided',
            'RELIEF Sales',
            'For the '.$kind.' ended '.$end->format('F j, Y').' ('.$start->format('F j, Y').' – '.$end->format('F j, Y').')',
            'Branch: '.($company['branch_name'] ?? str_pad($company['branch_code'] ?? '00000', 5, '0', STR_PAD_LEFT)),
            'Local review schedule · Not BIR validated',
            'Software: kontadurya.io',
            'Generated by '.($company['full_name'] ?? 'Workspace user').' on '.now('Asia/Manila')->format('M j, Y, g:i A').' (PHT)',
        ];
    }

    /** @param list<string|int|float> $values */
    private function fillRow(DOMDocument $document, DOMElement $template, int $number, array $values): DOMElement
    {
        $row = $template->cloneNode(true);
        $row->setAttribute('r', (string) $number);
        foreach ($row->getElementsByTagNameNS(self::XML_NAMESPACE, 'c') as $index => $cell) {
            $cell->setAttribute('r', chr(65 + $index).$number);
            $this->setCell($document, $cell, $values[$index], in_array($index, self::NUMERIC_COLUMNS, true));
        }

        return $row;
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
