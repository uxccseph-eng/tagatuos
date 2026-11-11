<?php

namespace App\Services;

use App\Models\Company;

class CashSaleWithholding
{
    public const ORIGIN = 'cash-sale-withholding';

    public function synchronize(Company $company, array &$state, array $previousState): void
    {
        $indexes = [];
        $manualSaleIds = [];
        $manualInvoices = [];
        foreach ($state['cwtCertificates'] as $key => $certificate) {
            if (($certificate['origin'] ?? '') === self::ORIGIN && (string) $certificate['id'] === 'cash-cwt:'.($certificate['saleId'] ?? '')) {
                $indexes[(string) $certificate['id']] = $key;
            } elseif ($certificate['status'] === 'posted') {
                if (isset($certificate['saleId'])) {
                    $manualSaleIds[(string) $certificate['saleId']] = true;
                }
                if (! empty($certificate['invoice'])) {
                    $manualInvoices[$certificate['invoice']] = true;
                }
            }
        }
        $previousSales = collect($previousState['sales'])->keyBy(fn (array $sale): string => (string) $sale['id']);
        foreach ($state['sales'] as $sale) {
            $id = 'cash-cwt:'.$sale['id'];
            $index = $indexes[$id] ?? null;
            $manual = isset($manualSaleIds[(string) $sale['id']]) || isset($manualInvoices[$sale['number'] ?? '']);
            $existing = $index !== null ? $state['cwtCertificates'][$index] : null;
            $eligible = $sale['status'] === 'posted' && ($sale['terms'] ?? '') === 'Cash' && preg_match('/^W[IC][0-9]{3}/i', (string) ($sale['cwt'] ?? ''));
            if (! $existing && $previousSales->get((string) $sale['id']) == $sale) {
                continue;
            }
            $amounts = $eligible ? app(LedgerPostingService::class)->saleWithholding($company, $sale) : null;
            if (! $amounts || $amounts['amount'] <= 0 || $manual) {
                if ($existing && $existing['status'] === 'posted') {
                    $state['cwtCertificates'][$index] = [...$existing, 'status' => 'void', 'sourceVoided' => true, 'voidReason' => 'Source sale is no longer a posted cash sale with CWT, or a manual certificate replaces it.'];
                }

                continue;
            }
            if ($existing && $existing['status'] !== 'posted' && empty($existing['sourceVoided'])) {
                continue;
            }
            $record = [...($existing ?? []), ...$amounts,
                'id' => $id, 'number' => $existing['number'] ?? '', 'certificate' => $existing['certificate'] ?? '',
                'origin' => self::ORIGIN, 'saleId' => $sale['id'], 'invoice' => $sale['number'] ?? '',
                'date' => $sale['date'], 'customer' => $sale['customer'] ?? '',
                'tin' => $sale['customerTin'] ?? $sale['tin'] ?? '', 'atc' => $sale['cwt'],
                'offset' => '1150 · Prepaid CWT', 'status' => 'posted', 'sourceVoided' => false,
            ];
            if ($index === null) {
                $state['cwtCertificates'][] = $record;
            } else {
                $state['cwtCertificates'][$index] = $record;
            }
        }
    }
}
