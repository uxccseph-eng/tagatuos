<?php

namespace App;

use App\Http\Requests\SaveChartAccountRequest;
use App\Http\Requests\SaveMasterRecordRequest;
use App\Models\BankAccount;
use App\Models\ChartAccount;
use App\Models\Employee;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;

class MasterImport
{
    /** @return list<string> */
    public static function columns(string $registry): array
    {
        return match ($registry) {
            'chart-of-accounts' => ['code', 'name', 'type', 'normal_balance', 'statement_section', 'description', 'is_cash_bank', 'is_advance', 'is_control', 'subsidiary', 'allow_subsidiaries', 'ppe_role', 'ppe_category', 'cash_flow_section', 'cash_flow_subclass', 'relief', 'relief_category', 'percentage_tax', 'ewt', 'ewt_atc_code'],
            'parties' => ['code', 'name', 'entity_type', 'last_name', 'first_name', 'middle_name', 'classification', 'is_petty_cash', 'tin', 'description', 'email', 'phone', 'building', 'street', 'barangay', 'city', 'province', 'zip_code', 'address'],
            'employees' => ['code', 'name', 'classification', 'tin', 'email', 'phone', 'address', 'birth_date', 'hire_date', 'end_date', 'sss_no', 'philhealth_no', 'pagibig_no'],
            'bank-accounts' => ['name', 'bank', 'account_number', 'gl_account', 'currency'],
            'tax-codes' => ['code', 'name', 'kind', 'rate', 'atc'],
            'atc-codes' => ['code', 'name', 'rate'],
            default => abort(404),
        };
    }

    /** @return class-string<Model> */
    public static function model(string $registry): string
    {
        return match ($registry) {
            'chart-of-accounts' => ChartAccount::class, 'parties' => Party::class, 'employees' => Employee::class, 'bank-accounts' => BankAccount::class, default => TaxCode::class,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{records: list<array<string, mixed>>, errors: list<array{row: int, field: string, message: string}>}
     */
    public function preview(Request $request, string $registry, array $rows): array
    {
        $workspaceKey = ChartAccount::workspaceKey($request->session()->get('company_setup'));
        $model = self::model($registry);
        $existing = $model::query()->where('workspace_key', $workspaceKey)->get()->toArray();
        if (in_array($registry, ['chart-of-accounts', 'parties', 'tax-codes'], true)) {
            $codes = array_column($existing, 'code');
            $existing = [...$existing, ...array_filter($model::defaults(), fn (array $record): bool => ! in_array($record['code'], $codes, true))];
        }
        if ($registry === 'atc-codes') {
            $existing = [...$existing, ...json_decode(file_get_contents(database_path('seeders/atc-codes.json')), true, 512, JSON_THROW_ON_ERROR)];
        }
        $seen = [];
        foreach ($existing as $record) {
            foreach ($this->identities($registry, $record) as $field => $identity) {
                $seen[$field][$identity] = 'Masters'.(! empty($record['archived']) ? ' (archived)' : '');
            }
        }
        $reserved = array_map(fn (array $row): string => is_scalar($row['code'] ?? null) ? strtoupper(trim((string) $row['code'])) : '', $rows);
        $records = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            try {
                $unknown = array_diff(array_keys($row), self::columns($registry));
                if ($unknown) {
                    throw ValidationException::withMessages(['columns' => 'Unknown columns: '.implode(', ', $unknown).'. Download this page’s template.']);
                }
                foreach ($row as $field => $value) {
                    if (! is_scalar($value) && $value !== null) {
                        throw ValidationException::withMessages([$field => 'Use a single text or numeric value.']);
                    }
                    $row[$field] = is_string($value) ? trim($value) : $value;
                }
                $row = $this->normalize($registry, $row, $workspaceKey, $reserved);
                if (isset($row['code'])) {
                    $reserved[] = $row['code'];
                }
                $validated = $this->validateRow($request, $registry, $row);
                foreach ($this->identities($registry, $validated) as $field => $identity) {
                    if (isset($seen[$field][$identity])) {
                        throw ValidationException::withMessages([$field => 'Duplicate '.$field.': already in '.$seen[$field][$identity].'. Restore or update the existing record instead.']);
                    }
                }
                foreach ($this->identities($registry, $validated) as $field => $identity) {
                    $seen[$field][$identity] = 'CSV row '.($index + 2);
                }
                $records[] = $validated;
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['row' => $index + 2, 'field' => $field, 'message' => $message];
                    }
                }
            }
        }

        return ['records' => $records, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $reserved
     * @return array<string, mixed>
     */
    private function normalize(string $registry, array $row, string $workspaceKey, array $reserved): array
    {
        if ($registry === 'parties' && array_key_exists('is_petty_cash', $row)) {
            $value = strtolower((string) $row['is_petty_cash']);
            if (! in_array($value, ['', '0', '1', 'true', 'false', 'yes', 'no'], true)) {
                throw ValidationException::withMessages(['is_petty_cash' => 'Use Yes/No, True/False or 1/0.']);
            }
            $row['is_petty_cash'] = in_array($value, ['1', 'true', 'yes'], true);
        }
        foreach (['code', 'atc', 'currency', 'ewt_atc_code'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = strtoupper((string) $row[$field]);
            }
        }
        if (isset($row['name'])) {
            $row['name'] = Str::squish((string) $row['name']);
        }
        if ($registry === 'chart-of-accounts') {
            foreach (['is_cash_bank', 'is_advance', 'is_control', 'allow_subsidiaries', 'relief', 'percentage_tax', 'ewt'] as $field) {
                $value = strtolower((string) ($row[$field] ?? ''));
                if (! in_array($value, ['', '0', '1', 'true', 'false', 'yes', 'no'], true)) {
                    throw ValidationException::withMessages([$field => 'Use Yes/No, True/False or 1/0.']);
                }
                $row[$field] = in_array($value, ['1', 'true', 'yes'], true);
            }
            $type = $row['type'] ?? '';
            $row['normal_balance'] = ($row['normal_balance'] ?? '') ?: (in_array($type, ['Liability', 'Equity', 'Income']) ? 'Credit' : 'Debit');
            $row['statement_section'] = ($row['statement_section'] ?? '') ?: match ($type) {
                'Asset' => 'Current Assets', 'Liability' => 'Current Liabilities', 'Equity' => 'Capital', 'Income' => 'Revenue', default => 'General and Administrative Expenses',
            };
        }
        if (in_array($registry, ['parties', 'employees'], true) && isset($row['tin']) && ($registry === 'employees' || strlen(preg_replace('/\D/', '', (string) $row['tin'])) >= 12)) {
            $row['tin'] = Tin::format((string) $row['tin']);
        }
        if ($registry === 'bank-accounts') {
            $value = (string) ($row['gl_account'] ?? '');
            $accounts = ChartAccount::query()->where('workspace_key', $workspaceKey)->where('archived', false)->where('is_cash_bank', true)->get()->filter(fn (ChartAccount $account): bool => in_array($this->key($value), [$this->key($account->code), $this->key($account->name), $this->key($account->code.' · '.$account->name)], true));
            if ($accounts->count() !== 1) {
                throw ValidationException::withMessages(['gl_account' => 'Select one active cash/bank account from this company. Use its code if names are ambiguous.']);
            }
            $row['chart_account_id'] = $accounts->first()->id;
            $row['currency'] = ($row['currency'] ?? '') ?: 'PHP';
            unset($row['gl_account']);
        } elseif ($registry === 'atc-codes') {
            $row['kind'] = 'Withholding';
            $row['atc'] = $row['code'] ?? '';
            if (! preg_match('/^[A-Z]{2}[0-9]{3}$/', $row['atc'])) {
                throw ValidationException::withMessages(['code' => 'Enter the actual ATC, such as WC999. ATC codes cannot be generated.']);
            }
        } elseif (trim((string) ($row['code'] ?? '')) === '') {
            $row['code'] = $registry === 'chart-of-accounts'
                ? MasterCode::account($workspaceKey, (string) ($row['type'] ?? ''), $reserved)
                : MasterCode::registry($workspaceKey, $registry, (string) ($row['classification'] ?? ''), $reserved);
        }

        return array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, string $registry, array $row): array
    {
        $accounts = $registry === 'chart-of-accounts';
        $form = $accounts ? new SaveChartAccountRequest : new SaveMasterRecordRequest;
        $form = $form::createFrom($request, $form);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $route = new Route('POST', 'masters/{registry}', []);
        $route->name($accounts ? 'masters.accounts.store' : 'masters.registry.store')->bind($request);
        $route->setParameter('registry', $registry === 'atc-codes' ? 'tax-codes' : $registry);
        $form->setRouteResolver(fn (): Route => $route);
        $form->setJson(new InputBag($row));
        $form->request->replace($row);
        $form->validateResolved();

        return $form->validated();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function identities(string $registry, array $record): array
    {
        $identities = [];
        foreach (['code', 'name'] as $field) {
            if (isset($record[$field]) && trim((string) $record[$field]) !== '') {
                $identities[$field] = $this->key((string) $record[$field]);
            }
        }
        if (in_array($registry, ['parties', 'employees'], true)) {
            $tin = preg_replace('/\D/', '', Tin::format((string) ($record['tin'] ?? '')));
            if ($tin !== '' && preg_match('/[1-9]/', $tin)) {
                $identities['tin'] = $tin;
            }
        }
        if ($registry === 'bank-accounts' && ! empty($record['account_number'])) {
            $identities['account_number'] = $this->key((string) ($record['bank'] ?? '')).'|'.strtolower(preg_replace('/[\s-]+/', '', (string) $record['account_number']));
        }
        if ($registry === 'tax-codes' && ($record['kind'] ?? '') === 'Withholding' && ! empty($record['atc'])) {
            $identities['atc'] = strtoupper($record['atc']).'|'.number_format((float) ($record['rate'] ?? 0), 4, '.', '');
        }

        return $identities;
    }

    private function key(string $value): string
    {
        return Str::lower(Str::squish($value));
    }
}
