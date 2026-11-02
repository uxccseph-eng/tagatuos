<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TransactionNumbering
{
    public const PREFIXES = ['sales' => 'SI', 'purchases' => 'BILL', 'collections' => 'CR', 'payments' => 'PV', 'cwtCertificates' => '2307R', 'wtpCertificates' => '2307I', 'journalEntries' => 'GJ', 'payroll' => 'PYRJ'];

    /** @return array{state: array, payroll: array} */
    public function normalize(array &$state, array &$payroll, array $previousState, array $previousPayroll): array
    {
        $sheets = [...$state, 'payroll' => $payroll];
        $previous = [...$previousState, 'payroll' => $previousPayroll];
        $targets = [];
        foreach (self::PREFIXES as $sheet => $prefix) {
            $old = collect($previous[$sheet])->keyBy(fn (array $record): string => (string) $record['id']);
            $compact = collect($sheets[$sheet])->contains(fn (array $record): bool => $record['status'] === 'archived' && ($old->get((string) $record['id'])['status'] ?? null) === 'void');
            $active = array_values(array_filter($sheets[$sheet], fn (array $record): bool => in_array($record['status'], ['posted', 'void'], true)));
            $baseNumber = fn (array $record): string => ($old->get((string) $record['id'])['status'] ?? 'draft') !== 'draft' ? ($old->get((string) $record['id'])['number'] ?? '') : ($record['number'] ?? '');
            $used = [];
            if ($compact) {
                usort($active, function (array $first, array $second) use ($old, $prefix): int {
                    $number = fn (array $record): int => $this->suffix($old->get((string) $record['id'])['number'] ?? $record['number'] ?? '', $prefix) ?? PHP_INT_MAX;

                    return $number($first) <=> $number($second) ?: strcmp((string) $first['id'], (string) $second['id']);
                });
            }
            foreach ($active as $record) {
                $number = $baseNumber($record);
                if ($number !== '' && (! $compact || $this->suffix($number, $prefix) === null)) {
                    $used[$number] = true;
                }
            }
            $next = $compact ? 1 : max([0, ...array_map(fn (string $number): int => $this->suffix($number, $prefix) ?? 0, array_keys($used))]) + 1;
            foreach ($active as $record) {
                $number = $baseNumber($record);
                if ($number === '' || ($compact && $this->suffix($number, $prefix) !== null)) {
                    do {
                        $number = $prefix.'-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
                    } while (isset($used[$number]));
                }
                $used[$number] = true;
                $targets[$sheet][(string) $record['id']] = $number;
            }
            foreach ($sheets[$sheet] as $record) {
                $before = $old->get((string) $record['id']);
                if ($before && $before['status'] !== 'draft' && ($before['number'] ?? '') !== ($record['number'] ?? '')
                    && ($targets[$sheet][(string) $record['id']] ?? $before['number'] ?? '') !== ($record['number'] ?? '')) {
                    throw ValidationException::withMessages(['ledger' => 'Posted numbers can change only through automatic renumbering after deleting void entries.']);
                }
                if ($record['status'] === 'archived') {
                    $targets[$sheet][(string) $record['id']] = $before['number'] ?? $record['number'] ?? '';
                }
            }
        }
        $normalized = $this->remap($sheets, $targets, $previous);
        $baseline = $this->remap($previous, $targets);
        $payroll = $normalized['payroll'];
        unset($normalized['payroll']);
        $state = $normalized;
        $oldPayroll = $baseline['payroll'];
        unset($baseline['payroll']);

        return ['state' => $baseline, 'payroll' => $oldPayroll];
    }

    private function suffix(string $number, string $prefix): ?int
    {
        return preg_match('/^'.preg_quote($prefix, '/').'-([0-9]+)$/D', $number, $matches) ? (int) $matches[1] : null;
    }

    private function remap(array $sheets, array $targets, ?array $previous = null): array
    {
        $sales = [];
        foreach ($sheets['sales'] as $sale) {
            if ($sale['status'] !== 'archived' && ($sale['number'] ?? '') !== '') {
                $sales[$sale['number']] = (string) $sale['id'];
            }
        }
        $previous ??= $sheets;
        $oldSales = collect($previous['sales'])->where('status', '!=', 'archived')->keyBy('number');
        $saleIds = collect($sheets['sales'])->mapWithKeys(fn (array $sale): array => [(string) $sale['id'] => $sale['id']])->all();
        foreach (self::PREFIXES as $sheet => $_) {
            $oldRecords = collect($previous[$sheet])->keyBy(fn (array $record): string => (string) $record['id']);
            foreach ($sheets[$sheet] as &$record) {
                $number = $record['number'] ?? '';
                $record['number'] = $record['status'] === 'draft' ? '' : ($targets[$sheet][(string) $record['id']] ?? $number);
                if (in_array($sheet, ['cwtCertificates', 'wtpCertificates'], true) && $record['status'] === 'posted' && (($record['certificate'] ?? '') === '' || ($number !== '' && $record['certificate'] === $number))) {
                    $record['certificate'] = $record['number'];
                }
                if ($record['status'] === 'archived') {
                    continue;
                }
                if (in_array($sheet, ['collections', 'cwtCertificates'], true)) {
                    $old = $oldRecords->get((string) $record['id']);
                    $retained = $old && $old['status'] !== 'draft' ? ($old['saleId'] ?? $oldSales->get($old['invoice'] ?? '')['id'] ?? null) : null;
                    if ($retained !== null) {
                        $expected = $targets['sales'][(string) $retained] ?? $old['invoice'] ?? '';
                        if (! in_array($record['invoice'] ?? '', [$old['invoice'] ?? '', $expected], true) || (isset($record['saleId']) && (string) $record['saleId'] !== (string) $retained)) {
                            throw ValidationException::withMessages(['ledger' => 'Void and duplicate the receipt or certificate to change its posted source invoice.']);
                        }
                        $record['saleId'] = $saleIds[(string) $retained] ?? $retained;
                    }
                    $source = isset($record['saleId']) ? (string) $record['saleId'] : ($sales[$record['invoice'] ?? ''] ?? null);
                    if ($source !== null && isset($targets['sales'][$source])) {
                        $record['invoice'] = $targets['sales'][$source];
                        $record['saleId'] = $saleIds[$source] ?? $source;
                    }
                }
                if (in_array($sheet, ['payments', 'wtpCertificates'], true) && isset($record['billId'], $targets['purchases'][(string) $record['billId']])) {
                    $record['bill'] = $targets['purchases'][(string) $record['billId']];
                }
                if ($sheet === 'journalEntries' && ($record['origin'] ?? '') === 'payroll-remittance') {
                    $oldLabels = [];
                    if (isset($record['payrollRunId'], $targets['payroll'][(string) $record['payrollRunId']])) {
                        $oldLabels[$record['payrollNumber'] ?? ''] = $targets['payroll'][(string) $record['payrollRunId']];
                        $record['payrollNumber'] = $targets['payroll'][(string) $record['payrollRunId']];
                    }
                    foreach ($record['payrollAllocations'] ?? [] as $index => $allocation) {
                        if (isset($targets['payroll'][(string) $allocation['runId']])) {
                            $oldLabels[$allocation['runNumber'] ?? ''] = $targets['payroll'][(string) $allocation['runId']];
                            $record['payrollAllocations'][$index]['runNumber'] = $targets['payroll'][(string) $allocation['runId']];
                        }
                    }
                    unset($oldLabels['']);
                    $record['particulars'] = strtr($record['particulars'] ?? '', $oldLabels);
                }
            }
            unset($record);
        }

        return $sheets;
    }
}
