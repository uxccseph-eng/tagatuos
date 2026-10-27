<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Validation\ValidationException;

class LedgerPostingService
{
    private array $accounts = [];

    private array $atcs = [];

    private array $parties = [];

    private array $employees = [];

    private string $workspace;

    /** @return list<array<string, mixed>> */
    public function prepare(Company $company, array $state, array $payroll, array $existing): array
    {
        $this->workspace = $company->workspaceKey();
        $this->accounts = ChartAccount::query()->where('workspace_key', $this->workspace)->get()->keyBy('code')->all();
        $this->atcs = collect(TaxCode::atcCatalog($this->workspace))->keyBy('code')->all();
        $this->parties = Party::query()->where('workspace_key', $this->workspace)->where('archived', false)->get(['id', 'name', 'classification'])->keyBy('name')->all();
        $this->employees = Employee::query()->where('workspace_key', $this->workspace)->where('archived', false)->pluck('id', 'name')->all();
        $vouchers = [];
        $posted = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['status'] === 'posted'));
        $line = fn (string $account, int $debit, int $credit, string $party = '', string $remarks = ''): array => compact('account', 'debit', 'credit', 'party', 'remarks');
        foreach ($posted($state['journalEntries']) as $record) {
            if (($record['origin'] ?? '') === 'payroll-remittance') {
                $this->validateRemittance($record, $state['journalEntries'], $payroll);
            }
        }
        foreach ($state as $sheet => $rows) {
            if (! in_array($sheet, CompanyLedgerService::SHEETS, true)) {
                continue;
            }
            foreach ($posted($rows) as $record) {
                $source = $sheet.':'.$record['id'];
                if (isset($existing[$source])) {
                    continue;
                }
                $party = (string) ($record['customer'] ?? $record['supplier'] ?? '');
                if ($party !== '') {
                    $kind = isset($record['customer']) ? 'Customer' : 'Supplier';
                    $selectedParty = $this->parties[$party] ?? null;
                    if (! $selectedParty || ! in_array($selectedParty->classification, [$kind, 'Both'], true)) {
                        $this->fail('Select '.$party.' from this company’s active '.$kind.' Masters before posting.');
                    }
                    if (! empty($record['partyId']) && (string) $record['partyId'] !== (string) $selectedParty->id) {
                        $this->fail('The selected party identity does not belong to this company.');
                    }
                }
                $lines = [];
                if ($sheet === 'sales' || $sheet === 'purchases') {
                    $gross = $this->amount($record['gross'] ?? null);
                    if ($gross === 0) {
                        $this->fail('Gross amount must be positive.');
                    }
                    $net = $this->taxNet($gross, (string) ($record['tax'] ?? ''));
                    if ($sheet === 'sales') {
                        $this->account((string) ($record['account'] ?? ''), ['Income']);
                        $this->withholding($record['cwt'] ?? '', null, false);
                        $this->withholding($record['vatCwt'] ?? '', null, true);
                        $lines = [$line('1105', $gross, 0, $party), $line('2210', 0, $gross - $net), $line($record['account'], 0, $net)];
                    } else {
                        $this->account((string) ($record['account'] ?? ''), ['Asset', 'Expense']);
                        $ewt = $this->withholding($record['ewtCode'] ?? '', $record['ewt'] ?? 0, false);
                        $vat = $this->withholding($record['wvatCode'] ?? '', $record['wvat'] ?? 0, true);
                        $ewtAmount = $this->percent($net, $ewt);
                        $vatAmount = $this->percent($net, $vat);
                        $payable = $gross - $ewtAmount - $vatAmount;
                        if ($payable < 0) {
                            $this->fail('Withholding exceeds the purchase amount.');
                        }
                        $lines = [$line($record['account'], $net, 0, $party), $line('2110', $gross - $net, 0, $party), $line('2010', 0, $payable, $party), $line('2140', 0, $ewtAmount, $party), $line('2150', 0, $vatAmount, $party)];
                    }
                } elseif ($sheet === 'collections' || $sheet === 'payments') {
                    $amount = $this->amount($record['amount'] ?? null);
                    $bank = (string) ($record['bank'] ?? '');
                    $this->account($bank, ['Asset'], true);
                    if ($amount <= 0) {
                        $this->fail('Collection and payment amounts must be positive.');
                    }
                    if ($sheet === 'collections') {
                        if (! in_array($record['type'] ?? '', ['Settlement', 'Advance', 'Invoice Payment', 'Invoice Settlement', 'Collection', 'Payment'], true)) {
                            $this->fail('Select a valid collection type.');
                        }
                        $lines = [$line($bank, $amount, 0), $line(($record['type'] ?? '') === 'Advance' ? '2200' : '1105', 0, $amount, $party)];
                    } else {
                        if (! in_array($record['type'] ?? '', ['AP Settlement', 'Supplier Advance', 'Direct Expense'], true)) {
                            $this->fail('Select a valid payment type.');
                        }
                        $account = match ($record['type']) {
                            'AP Settlement' => '2010', 'Supplier Advance' => '1170', default => (string) ($record['account'] ?? '')
                        };
                        if ($record['type'] === 'Direct Expense') {
                            $this->account($account, ['Asset', 'Expense']);
                        }
                        $lines = [$line($account, $amount, 0, $party), $line($bank, 0, $amount)];
                    }
                } elseif ($sheet === 'cwtCertificates') {
                    $amount = $this->amount($record['amount'] ?? null);
                    $base = $this->amount($record['taxBase'] ?? null);
                    $code = $record['atc'] ?? $record['atcCode'] ?? '';
                    $rate = $this->withholding($code, $record['rate'] ?? null, str_starts_with((string) $code, 'WV') || str_starts_with((string) $code, 'WB'));
                    if ($amount <= 0 || $amount > $base || abs($this->percent($base, $rate) - $amount) > 1) {
                        $this->fail('CWT base, ATC rate and withheld amount must reconcile.');
                    }
                    $lines = [$line($record['offset'] ?? '1150', $amount, 0, $party), $line('1105', 0, $amount, $party)];
                } elseif ($sheet === 'wtpCertificates') {
                    $base = $this->amount($record['taxBase'] ?? null);
                    $ewt = $this->withholding($record['ewtCode'] ?? '', $record['ewt'] ?? 0, false);
                    $vat = $this->withholding($record['wvatCode'] ?? '', $record['wvat'] ?? 0, true);
                    if ($base <= 0 || ($ewt === 0 && $vat === 0)) {
                        $this->fail('An issued certificate needs a tax base and withholding ATC.');
                    }
                    if (! empty($record['billId'])) {
                        $bill = $this->find($state['purchases'], 'id', $record['billId']);
                        if (! $bill || $bill['status'] !== 'posted' || $bill['supplier'] !== $party || $this->taxNet($bill['gross'], $bill['tax']) !== $base || ($bill['ewtCode'] ?? '') !== ($record['ewtCode'] ?? '') || ($bill['wvatCode'] ?? '') !== ($record['wvatCode'] ?? '')) {
                            $this->fail('The issued certificate must match its posted purchase and ATCs.');
                        }

                        continue;
                    }
                    $ewtAmount = $this->percent($base, $ewt);
                    $vatAmount = $this->percent($base, $vat);
                    $lines = [$line($record['offset'] ?? '', $ewtAmount + $vatAmount, 0, $party), $line('2140', 0, $ewtAmount, $party), $line('2150', 0, $vatAmount, $party)];
                } elseif ($sheet === 'journalEntries') {
                    $lines = $record['lines'] ?? [];
                    if (($record['origin'] ?? '') === 'payroll-remittance') {
                        $this->validateRemittance($record, $state['journalEntries'], $payroll);
                    }
                }
                $vouchers[] = $this->voucher($record, $sheet, $lines);
            }
        }
        foreach ($posted($payroll) as $run) {
            if (isset($existing['payroll:'.$run['id']])) {
                continue;
            }
            if (! empty($run['clientPayrollImport'])) {
                app(PayrollWithholdingReview::class)->validate($run);
                foreach ($payroll as $other) {
                    if ((string) $other['id'] !== (string) $run['id'] && ! in_array($other['status'], ['void', 'archived'], true) && $other['date'] === $run['date'] && $other['periodStart'] === $run['periodStart'] && $other['periodEnd'] === $run['periodEnd']) {
                        $this->fail('A payroll run already exists for this pay date and period.');
                    }
                }
            }
            $sums = array_fill_keys(['basic', 'taxableAllowance', 'deMinimis', 'benefits', 'sssEe', 'phicEe', 'hdmfEe', 'withholding', 'sssEr', 'phicEr', 'hdmfEr'], 0);
            $names = [];
            if (empty($run['employees']) || count($run['employees']) > 1000) {
                $this->fail('A payroll run needs between 1 and 1000 employees.');
            }
            foreach ($run['employees'] as $employee) {
                $name = trim((string) ($employee['name'] ?? ''));
                if (isset($names[mb_strtolower($name)]) || ! isset($this->employees[$name])) {
                    $this->fail('Select distinct active employees from this company’s Masters.');
                }
                $names[mb_strtolower($name)] = true;
                if (! empty($run['clientPayrollImport']) && ($employee['employeeId'] ?? null) !== $this->employees[$name]) {
                    $this->fail('Imported employee identity must match this company’s active Masters record.');
                }
                $values = [];
                foreach ($sums as $field => $_) {
                    $values[$field] = $this->decimal($employee[$field] ?? '', 2);
                    $sums[$field] += $values[$field];
                }
                $gross = $values['basic'] + $values['taxableAllowance'] + $values['deMinimis'] + $values['benefits'];
                if ($gross <= 0 || $gross < $values['sssEe'] + $values['phicEe'] + $values['hdmfEe'] + $values['withholding']) {
                    $this->fail('Employee earnings must be positive and cover deductions.');
                }
            }
            $gross = $sums['basic'] + $sums['taxableAllowance'] + $sums['deMinimis'] + $sums['benefits'];
            $net = $gross - $sums['sssEe'] - $sums['phicEe'] - $sums['hdmfEe'] - $sums['withholding'];
            $lines = [$line('7210', $sums['basic'] + $sums['taxableAllowance'], 0), $line('7220', $sums['benefits'], 0), $line('7230', $sums['deMinimis'], 0), $line('7240', $sums['sssEr'], 0), $line('7250', $sums['phicEr'], 0), $line('7260', $sums['hdmfEr'], 0), $line('2310', 0, $sums['sssEe'] + $sums['sssEr']), $line('2320', 0, $sums['phicEe'] + $sums['phicEr']), $line('2330', 0, $sums['hdmfEe'] + $sums['hdmfEr']), $line('2350', 0, $sums['withholding']), $line('2340', 0, $net)];
            $vouchers[] = $this->voucher($run, 'payroll', $lines);
        }
        $this->validateSettlements($state);

        return $vouchers;
    }

    private function voucher(array $record, string $sheet, array $lines): array
    {
        if (count($lines) > 100) {
            $this->fail('A journal may have at most 100 lines.');
        }
        $result = [];
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $d = $this->amount($line['debit'] ?? null);
            $c = $this->amount($line['credit'] ?? null);
            if ($d === 0 && $c === 0) {
                continue;
            }
            if ($d > 0 && $c > 0) {
                $this->fail('Use debit or credit on each journal line, not both.');
            }
            $account = $this->account((string) ($line['account'] ?? ''));
            $debit += $d;
            $credit += $c;
            $result[] = ['chart_account_id' => $account->id, 'account_name' => $account->code.' · '.$account->name, 'debit' => $this->currency($d), 'credit' => $this->currency($c), 'party' => mb_substr((string) ($line['party'] ?? ''), 0, 255), 'remarks' => mb_substr((string) (($line['remarks'] ?? '') ?: ($record['particulars'] ?? '') ?: ($record['memo'] ?? '') ?: ($record['description'] ?? '') ?: ($record['notes'] ?? '')), 0, 1000)];
        }
        if ($debit === 0 || $debit !== $credit || count($result) < 2) {
            $this->fail('The server rejected an unbalanced journal. Total debits must equal total credits.');
        }

        return ['source_key' => $sheet.':'.$record['id'], 'sheet' => $sheet, 'number' => $record['number'], 'transaction_date' => $record['date'], 'reference' => mb_substr((string) ($record['reference'] ?? $record['certificate'] ?? ''), 0, 255), 'lines' => $result];
    }

    private function account(string $value, array $types = [], bool $bank = false): ChartAccount
    {
        $code = explode(' · ', $value)[0];
        $account = $this->accounts[$code] ?? null;
        if (! $account || $account->archived || ($types !== [] && ! in_array($account->type, $types, true)) || ($bank && ! $account->is_cash_bank)) {
            $this->fail('Choose an active account from this company’s Masters: '.$code.'.');
        }

        return $account;
    }

    private function taxNet(int $gross, string $tax): int
    {
        if (in_array($tax, ['12% VAT', 'VAT (12%)'], true)) {
            return intdiv($gross * 100 + 56, 112);
        }
        if (in_array($tax, ['Non-VAT', 'VAT Exempt', 'Exempt', 'Zero-Rated (0%)', 'Zero-Rated'], true)) {
            return $gross;
        }
        $parts = explode('|', $tax);
        $saved = count($parts) === 4 ? TaxCode::query()->where('workspace_key', $this->workspace)->where('code', $parts[1])->where('archived', false)->first() : null;
        if (! $saved || $saved->kind !== $parts[2] || $this->decimal($saved->rate, 4) !== $this->decimal($parts[3], 4)) {
            $this->fail('Select a tax code and rate from this company’s Masters.');
        }
        $denominator = 1000000 + $this->decimal($saved->rate, 4);

        return $saved->kind === 'VAT' ? intdiv($gross * 1000000 + intdiv($denominator, 2), $denominator) : $gross;
    }

    private function withholding(mixed $value, mixed $rate, bool $vat): int
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^(?:None(?: \(0%\))?|0%?)$/i', $value)) {
            if ($rate !== null && $this->decimal($rate, 4) !== 0) {
                $this->fail('A withholding rate needs its Masters ATC code.');
            }

            return 0;
        }
        preg_match('/^W[ICVB][0-9]{3}/i', $value, $matches);
        $atc = $this->atcs[strtoupper($matches[0] ?? '')] ?? null;
        if (! $atc || ($atc['category'] === 'VAT Withholding') !== $vat) {
            $this->fail('Select the correct income or VAT withholding ATC from Masters.');
        }
        $expected = $this->decimal($atc['rate'], 4);
        if ($rate === null && preg_match('/([0-9]+(?:\.[0-9]+)?)%/', $value, $labelRate)) {
            $rate = $labelRate[1];
        }
        if ($rate !== null && $this->decimal($rate, 4) !== $expected) {
            $this->fail('The withholding rate does not match the Masters ATC.');
        }

        return $expected;
    }

    private function validateSettlements(array $state): void
    {
        $salesByNumber = collect($state['sales'])->keyBy('number')->all();
        $billsById = collect($state['purchases'])->keyBy('id')->all();
        $receiptsByInvoice = collect($state['collections'])->where('status', 'posted')->groupBy('invoice')->all();
        $cwtByInvoice = collect($state['cwtCertificates'])->where('status', 'posted')->groupBy('invoice')->all();
        $paymentsByBill = collect($state['payments'])->where('status', 'posted')->groupBy('billId')->all();
        $certificates = [];
        $sources = [];
        foreach (['cwtCertificates', 'wtpCertificates'] as $sheet) {
            foreach ($state[$sheet] as $record) {
                if ($record['status'] !== 'posted') {
                    continue;
                }
                $certificate = trim((string) ($record['certificate'] ?? ''));
                if ($certificate !== '') {
                    $key = $sheet.':'.strtolower($certificate);
                    if (isset($certificates[$key])) {
                        $this->fail('A certificate number is already recorded in this company.');
                    }
                    $certificates[$key] = true;
                }
                $source = $sheet === 'cwtCertificates' ? ($record['invoice'] ?? '') : ($record['billId'] ?? '');
                if ($source !== '') {
                    $key = $sheet.':'.$source;
                    if (isset($sources[$key])) {
                        $this->fail('This source transaction already has a posted withholding certificate.');
                    }
                    $sources[$key] = true;
                }
                if ($sheet === 'cwtCertificates' && ! isset($salesByNumber[$record['invoice'] ?? ''])) {
                    $this->fail('Select a posted source invoice from this company.');
                }
                if ($sheet === 'wtpCertificates' && ! empty($record['billId'])) {
                    $bill = $billsById[$record['billId']] ?? null;
                    if (! $bill || $bill['status'] !== 'posted') {
                        $this->fail('Void the issued certificate before voiding its source purchase.');
                    }
                }
            }
        }
        foreach ($state['sales'] as $sale) {
            $settled = 0;
            foreach ($receiptsByInvoice[$sale['number']] ?? [] as $receipt) {
                if ($receipt['status'] === 'posted' && ($receipt['type'] ?? '') !== 'Advance' && ($receipt['invoice'] ?? '') === $sale['number']) {
                    if ($sale['status'] !== 'posted' || $receipt['customer'] !== $sale['customer']) {
                        $this->fail('A receipt must match its posted source sale and customer.');
                    }
                    $settled += $this->amount($receipt['amount']);
                }
            }
            foreach ($cwtByInvoice[$sale['number']] ?? [] as $certificate) {
                if ($certificate['status'] === 'posted' && ($certificate['invoice'] ?? '') === $sale['number']) {
                    if ($sale['status'] !== 'posted' || $certificate['customer'] !== $sale['customer']) {
                        $this->fail('CWT must match its posted source sale and customer.');
                    }
                    if (($certificate['taxBase'] ?? 0) !== $this->historicalTaxNet($sale['gross'], $sale['tax'])) {
                        $this->fail('The CWT tax base must match its source sale.');
                    }
                    preg_match('/^W[ICVB][0-9]{3}/i', $certificate['atc'] ?? '', $certificateAtc);
                    preg_match('/^W[ICVB][0-9]{3}/i', $sale['cwt'] ?? '', $sourceAtc);
                    if (strtoupper($certificateAtc[0] ?? '') !== strtoupper($sourceAtc[0] ?? '')) {
                        $this->fail('The CWT ATC must match its source sale.');
                    }
                    $settled += $this->amount($certificate['amount']);
                }
            }
            if ($settled > ($sale['gross'] ?? 0)) {
                $this->fail('Receipts plus CWT exceed the sale balance.');
            }
        }
        foreach ($state['collections'] as $receipt) {
            if ($receipt['status'] === 'posted' && ($receipt['type'] ?? '') !== 'Advance' && ! isset($salesByNumber[$receipt['invoice'] ?? ''])) {
                $this->fail('The receipt source invoice was not found in this company.');
            }
        }
        foreach ($state['purchases'] as $bill) {
            $paid = 0;
            foreach ($paymentsByBill[$bill['id']] ?? [] as $payment) {
                if ($payment['status'] === 'posted' && ($payment['type'] ?? '') === 'AP Settlement' && (string) ($payment['billId'] ?? '') === (string) $bill['id']) {
                    if ($bill['status'] !== 'posted' || $payment['supplier'] !== $bill['supplier']) {
                        $this->fail('A payment must match its posted source purchase and supplier.');
                    }
                    $paid += $this->amount($payment['amount']);
                }
            }
            $net = $this->historicalTaxNet($bill['gross'] ?? 0, $bill['tax'] ?? 'Non-VAT');
            $payable = ($bill['gross'] ?? 0) - $this->percent($net, $this->decimal($bill['ewt'] ?? 0, 4)) - $this->percent($net, $this->decimal($bill['wvat'] ?? 0, 4));
            if ($paid > $payable) {
                $this->fail('Payments exceed the purchase balance after withholding.');
            }
        }
        foreach ($state['payments'] as $payment) {
            if ($payment['status'] === 'posted' && ($payment['type'] ?? '') === 'AP Settlement' && ! isset($billsById[$payment['billId'] ?? ''])) {
                $this->fail('The payment source purchase was not found in this company.');
            }
        }
    }

    private function validateRemittance(array $record, array $journals, array $payroll): void
    {
        $expected = [];
        $codes = ['salary' => '2340', 'sss' => '2310', 'phic' => '2320', 'hdmf' => '2330', 'withholding' => '2350'];
        $seen = [];
        foreach ($record['payrollAllocations'] ?? [] as $allocation) {
            $run = $this->find($payroll, 'id', $allocation['runId'] ?? '');
            if (! $run || $run['status'] !== 'posted' || $record['date'] < $run['date']) {
                $this->fail('Remittance must link a posted payroll run and a valid payment date.');
            }
            $liabilities = array_fill_keys(array_keys($codes), 0);
            foreach ($run['employees'] as $employee) {
                $v = fn (string $field): int => $this->decimal($employee[$field] ?? '', 2);
                $liabilities['sss'] += $v('sssEe') + $v('sssEr');
                $liabilities['phic'] += $v('phicEe') + $v('phicEr');
                $liabilities['hdmf'] += $v('hdmfEe') + $v('hdmfEr');
                $liabilities['withholding'] += $v('withholding');
                $liabilities['salary'] += $v('basic') + $v('taxableAllowance') + $v('benefits') + $v('deMinimis') - $v('sssEe') - $v('phicEe') - $v('hdmfEe') - $v('withholding');
            }
            foreach ($allocation['amounts'] ?? [] as $key => $amount) {
                $pair = $run['id'].':'.$key;
                if (! isset($codes[$key]) || isset($seen[$pair]) || $this->amount($amount) <= 0 || $amount !== $liabilities[$key]) {
                    $this->fail('Remittance amounts must match the selected payroll liabilities.');
                }
                $seen[$pair] = true;
                foreach ($journals as $other) {
                    if ($other['id'] === $record['id'] || $other['status'] !== 'posted') {
                        continue;
                    }
                    foreach ($other['payrollAllocations'] ?? [] as $paid) {
                        if ($paid['runId'] === $run['id'] && array_key_exists($key, $paid['amounts'])) {
                            $this->fail('This payroll liability has already been remitted.');
                        }
                    }
                }
                $expected[$codes[$key]] = ($expected[$codes[$key]] ?? 0) + $amount;
            }
        }
        if ($expected === []) {
            $this->fail('Select payroll liabilities to remit.');
        }
        $seenLines = [];
        $credit = 0;
        foreach ($record['lines'] ?? [] as $line) {
            $code = explode(' · ', $line['account'] ?? '')[0];
            if (($line['credit'] ?? 0) > 0) {
                $this->account($line['account'], ['Asset'], true);
                $credit += $line['credit'];
            } else {
                if (isset($seenLines[$code]) || ! isset($expected[$code]) || $line['debit'] !== $expected[$code]) {
                    $this->fail('Remittance debit accounts must match the payroll liability accounts.');
                }
                $seenLines[$code] = true;
            }
        }
        if (count($seenLines) !== count($expected) || $credit !== array_sum($expected)) {
            $this->fail('Remittance lines do not reconcile to payroll.');
        }
    }

    private function find(array $records, string $field, mixed $value): ?array
    {
        foreach ($records as $record) {
            if ((string) ($record[$field] ?? '') === (string) $value) {
                return $record;
            }
        }

        return null;
    }

    private function historicalTaxNet(int $gross, string $tax): int
    {
        if (in_array($tax, ['12% VAT', 'VAT (12%)'], true)) {
            return intdiv($gross * 100 + 56, 112);
        }
        $parts = explode('|', $tax);
        if (count($parts) === 4 && $parts[2] === 'VAT') {
            $denominator = 1000000 + $this->decimal($parts[3], 4);

            return intdiv($gross * 1000000 + intdiv($denominator, 2), $denominator);
        }

        return $gross;
    }

    public function amount(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 99999999999) {
            $this->fail('Amounts must be non-negative integer centavos within the supported range.');
        }

        return $value;
    }

    public function decimal(mixed $value, int $scale): int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0;
        }
        if (! preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,'.$scale.'}))?$/D', $text, $parts)) {
            $this->fail('Use a non-negative decimal with at most '.$scale.' decimal places.');
        }

        return (int) $parts[1] * (10 ** $scale) + (int) str_pad($parts[2] ?? '', $scale, '0');
    }

    private function percent(int $base, int $rate): int
    {
        if ($rate > 1000000) {
            $this->fail('A withholding rate cannot exceed 100%.');
        }

        return intdiv($base * $rate + 500000, 1000000);
    }

    public function currency(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'00';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ledger' => $message]);
    }
}
