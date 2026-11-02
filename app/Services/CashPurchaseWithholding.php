<?php

namespace App\Services;

use App\Models\Company;

class CashPurchaseWithholding
{
    public const ORIGIN = 'cash-purchase-withholding';

    public function synchronize(Company $company, array &$state, array $previousState): void
    {
        $indexes = [];
        $manualSources = [];
        foreach ($state['wtpCertificates'] as $key => $certificate) {
            if (($certificate['origin'] ?? '') === self::ORIGIN && (string) $certificate['id'] === 'cash-wtp:'.($certificate['billId'] ?? '')) {
                $indexes[(string) $certificate['id']] = $key;
            } elseif ($certificate['status'] === 'posted' && isset($certificate['billId'])) {
                $manualSources[(string) $certificate['billId']] = true;
            }
        }
        $previous = collect($previousState['purchases'])->keyBy(fn (array $bill): string => (string) $bill['id']);
        foreach ($state['purchases'] as $bill) {
            $id = 'cash-wtp:'.$bill['id'];
            $index = $indexes[$id] ?? null;
            $manual = isset($manualSources[(string) $bill['id']]);
            $eligible = $bill['status'] === 'posted' && ($bill['terms'] ?? '') === 'Cash' && (float) ($bill['ewt'] ?? 0) > 0;
            $existing = $index !== null ? $state['wtpCertificates'][$index] : null;
            if (! $eligible) {
                if ($existing && $existing['status'] === 'posted') {
                    $state['wtpCertificates'][$index] = [...$existing, 'status' => 'void', 'sourceVoided' => true, 'voidReason' => 'Source purchase is no longer a posted cash purchase with EWT.'];
                }

                continue;
            }
            $previousBill = $previous->get((string) $bill['id']);
            if (! $existing && $previousBill == $bill) {
                continue;
            }
            if ($manual || ($existing && $existing['status'] !== 'posted' && empty($existing['sourceVoided']))) {
                continue;
            }
            $record = [...($existing ?? []), 'id' => $id, 'number' => $existing['number'] ?? '', 'certificate' => $existing['certificate'] ?? '', 'origin' => self::ORIGIN, 'billId' => $bill['id'], 'bill' => $bill['number'] ?? '', 'date' => $bill['date'], 'supplier' => $bill['supplier'] ?? '', 'tin' => $bill['tin'] ?? $bill['supplierTin'] ?? '', 'taxBase' => app(LedgerPostingService::class)->purchaseTaxBase($company, $bill), 'ewt' => $bill['ewt'], 'wvat' => $bill['wvat'] ?? 0, 'ewtCode' => $bill['ewtCode'] ?? '', 'wvatCode' => $bill['wvatCode'] ?? '', 'offset' => "2010 \u{00B7} Accounts Payable - Trade", 'status' => 'posted', 'sourceVoided' => false];
            if ($index === null) {
                $state['wtpCertificates'][] = $record;
            } else {
                $state['wtpCertificates'][$index] = $record;
            }
        }
    }
}
