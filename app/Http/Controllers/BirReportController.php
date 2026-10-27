<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateSawtDatRequest;
use App\Models\ChartAccount;
use App\Models\TaxCode;
use App\Services\BirReliefService;
use App\Services\QapDatService;
use App\Services\SawtDatService;
use App\Services\TaxPreflightService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class BirReportController extends Controller
{
    public function generateFromPayload(Request $request, BirReliefService $relief): StreamedResponse
    {
        return $this->generate($request, $relief, 'S');
    }

    public function generatePurchasesFromPayload(Request $request, BirReliefService $relief): StreamedResponse
    {
        return $this->generate($request, $relief, 'P');
    }

    public function export1601EQDat(Request $request, QapDatService $qap): StreamedResponse
    {
        $data = $this->validatedQapPayload($request);
        $company = $request->session()->get('company_setup');
        $prepared = $qap->prepare($company, $data);
        $start = CarbonImmutable::create((int) $data['year'], ((int) $data['quarter'] - 1) * 3 + 1, 1);
        app(TaxPreflightService::class)->recordExport($request, ['schedule' => 'qap-ewt', 'from' => $start->toDateString(), 'to' => $start->addMonths(2)->endOfMonth()->toDateString()], $prepared['filename'], $qap->archiveLines($prepared));

        return response()->streamDownload(function () use ($qap, $prepared): void {
            $output = fopen('php://output', 'wb');
            foreach ($qap->archiveLines($prepared) as $line) {
                fwrite($output, $line);
            }
            fclose($output);
        }, $prepared['filename'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no', 'X-QAP-Detail-Count' => (string) $prepared['count'], 'X-QAP-File-Count' => (string) count($prepared['files'])]);
    }

    public function export1604EDat(Request $request, QapDatService $qap): StreamedResponse
    {
        $data = $this->validatedQapPayload($request, true);
        $prepared = $qap->prepareAnnual($request->session()->get('company_setup'), $data);
        app(TaxPreflightService::class)->recordExport($request, ['schedule' => 'qap-ewt', 'form' => '1604E', 'from' => $data['year'].'-01-01', 'to' => $data['year'].'-12-31'], $prepared['filename'], $qap->lines($prepared));

        return response()->streamDownload(function () use ($qap, $prepared): void {
            $output = fopen('php://output', 'wb');
            foreach ($qap->lines($prepared) as $line) {
                fwrite($output, $line);
            }
            fclose($output);
        }, $prepared['filename'], ['Content-Type' => 'text/plain', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no', 'X-QAP-Detail-Count' => (string) $prepared['count']]);
    }

    /** @return array<string, mixed> */
    private function validatedQapPayload(Request $request, bool $annual = false): array
    {
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $rules = [
            'quarter' => [$annual ? 'nullable' : 'required', 'integer', 'between:1,4'],
            'year' => ['required', 'integer', 'between:1900,2199'],
            'records' => ['required', 'array', 'list', 'min:1', 'max:50000'],
            'records.*' => ['required', 'array'],
            'records.*.payee_id' => ['nullable', 'integer', 'min:1'],
            'records.*.source_id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'records.*.date' => ['required', 'date_format:Y-m-d'],
            'records.*.status' => ['required', 'in:posted'],
            'records.*.tin' => ['required', 'string', 'max:20', 'regex:/^[0-9-]+$/D'],
            'records.*.entity_type' => ['required', 'in:corporation,individual'],
            'records.*.registered_name' => ['nullable', 'string', 'max:50'],
            'records.*.last_name' => ['nullable', 'string', 'max:30'],
            'records.*.first_name' => ['nullable', 'string', 'max:30'],
            'records.*.middle_name' => ['nullable', 'string', 'max:30'],
            'records.*.atc' => ['required', 'string', 'regex:/^W[IC][0-9]{3}$/D'],
            'records.*.tax_rate' => ['required', 'numeric', 'gt:0', 'max:99.99', 'decimal:0,2'],
        ];
        foreach (['income_payment', 'amount_withheld'] as $field) {
            $rules['records.*.'.$field] = ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'];
        }
        $data = $request->validate($rules);
        $company = $request->session()->get('company_setup');
        $catalog = collect(TaxCode::atcCatalog(ChartAccount::workspaceKey($company)))->keyBy('code');
        foreach ($data['records'] as $index => $record) {
            if (! $catalog->has($record['atc'])) {
                throw ValidationException::withMessages(['records.'.$index.'.atc' => 'Select an income withholding ATC from this company’s Masters.']);
            }
        }

        return $data;
    }

    public function generateSawtFromPayload(GenerateSawtDatRequest $request, SawtDatService $sawt): StreamedResponse
    {
        $data = $request->validated();
        $company = $request->session()->get('company_setup');
        if (in_array($data['form_type'], ['1701', '1702'], true)) {
            $end = CarbonImmutable::parse($data['to']);
            $endMonth = ($company['accounting_period'] ?? 'calendar') === 'fiscal' ? (int) ($company['fiscal_end_month'] ?? 12) : 12;
            if ($end->month !== $endMonth || $end->toDateString() !== $end->endOfMonth()->toDateString() || $data['from'] !== $end->subMonthsNoOverflow(11)->startOfMonth()->toDateString()) {
                throw ValidationException::withMessages(['from' => 'Annual SAWT requires the complete twelve-month tax year from company settings.']);
            }
            $expectedEntity = $data['form_type'] === '1701' ? 'individual' : 'corporation';
            if ($data['taxpayer']['entity_type'] !== $expectedEntity) {
                throw ValidationException::withMessages(['taxpayer.entity_type' => 'Use Form 1701 for an individual or Form 1702 for a non-individual taxpayer.']);
            }
        }
        $companyIdentity = $sawt->identity((string) ($company['tin'] ?? ''), 'taxpayer.tin');
        $exportIdentity = $sawt->identity($data['taxpayer']['tin'], 'taxpayer.tin');
        if ($companyIdentity['tin'] !== $exportIdentity['tin']) {
            throw ValidationException::withMessages(['taxpayer.tin' => 'The taxpayer TIN must match the current company. Update company settings before exporting another taxpayer.']);
        }
        $vat = $data['tax_kind'] === 'vat';
        if ($vat !== str_starts_with($data['form_type'], '25')) {
            throw ValidationException::withMessages(['form_type' => 'Select a form matching the Income Tax or VAT schedule.']);
        }
        $catalog = collect(TaxCode::atcCatalog(ChartAccount::workspaceKey($company)))->keyBy('code');
        foreach ($data['records'] as $index => $record) {
            if (! $catalog->has($record['atc']) || $vat !== (bool) preg_match('/^W[VB]/', $record['atc'])) {
                throw ValidationException::withMessages(['records.'.$index.'.atc' => 'Select a known ATC from this company’s Masters matching the selected tax schedule.']);
            }
        }
        $prepared = $sawt->prepare($data);
        app(TaxPreflightService::class)->recordExport($request, ['schedule' => 'sawt', 'from' => $data['from'], 'to' => $data['to'], 'form' => $data['form_type'], 'tax_kind' => $data['tax_kind']], $prepared['filename'], $sawt->lines($prepared));

        return response()->streamDownload(function () use ($sawt, $prepared): void {
            foreach ($sawt->lines($prepared) as $line) {
                echo $line;
            }
        }, $prepared['filename'], ['Content-Type' => 'text/plain; charset=us-ascii', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no', 'X-SAWT-Detail-Count' => (string) $prepared['count']]);
    }

    private function generate(Request $request, BirReliefService $relief, string $kind): StreamedResponse
    {
        $purchases = $kind === 'P';
        $label = $purchases ? 'Purchases' : 'Sales';
        abort_unless($request->session()->get('preview_access') && $request->session()->has('company_setup'), 403);
        $rules = [
            'company_tin' => ['required', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'non_individual' => ['required', 'boolean'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:255'],
            'rdo' => ['required', 'string', 'regex:/^[0-9]{3}$/D'],
            'period_end' => ['required', 'integer', 'between:1,12'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sales_records' => ['required', 'array', 'list', 'min:1', 'max:50000'],
            'sales_records.*' => ['required', 'array'],
            'sales_records.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:to'],
            'sales_records.*.account_code' => ['required', 'string', 'regex:/^[0-9]{4}$/D'],
            'sales_records.*.customer_tin' => ['present', 'nullable', 'string', 'max:32'],
            'sales_records.*.customer_name' => ['present', 'nullable', 'string', 'max:255'],
            'sales_records.*.customer_address' => ['nullable', 'string', 'max:1000'],
            'sales_records.*.customer_street' => ['nullable', 'string', 'max:1000'],
            'sales_records.*.customer_city' => ['nullable', 'string', 'max:255'],
            'sales_records.*.last_name' => ['nullable', 'string', 'max:100'],
            'sales_records.*.first_name' => ['nullable', 'string', 'max:100'],
            'sales_records.*.middle_name' => ['nullable', 'string', 'max:100'],
        ];
        foreach (['gross_amount', 'exempt_amount', 'zero_rated_amount', 'taxable_amount', 'output_vat_amount'] as $column) {
            $rules['sales_records.*.'.$column] = ['required', 'numeric', 'between:-999999999999.99,999999999999.99'];
        }
        if ($purchases) {
            $purchaseRules = [];
            foreach ($rules as $key => $value) {
                $purchaseRules[str_replace(['sales_records', 'customer_', 'output_vat_amount'], ['purchase_records', 'supplier_', 'input_vat_amount'], $key)] = $value;
            }
            $rules = $purchaseRules;
            $rules['purchase_records.*.non_creditable_vat_amount'] = ['required', 'numeric', 'between:-999999999999.99,999999999999.99'];
        }
        $data = $request->validate($rules);
        $recordsKey = $purchases ? 'purchase_records' : 'sales_records';
        ChartAccount::syncSalesRelief($request->session()->get('company_setup'));
        $accounts = ChartAccount::query()->where('workspace_key', ChartAccount::workspaceKey($request->session()->get('company_setup')))->whereIn('code', array_column($data[$recordsKey], 'account_code'))->get()->keyBy('code');
        foreach ($data[$recordsKey] as $index => &$record) {
            $account = $accounts->get($record['account_code']);
            if (! $account || ! in_array($account->type, $purchases ? ['Expense', 'Asset'] : ['Income'], true)) {
                throw ValidationException::withMessages([$recordsKey.".{$index}.account_code" => 'Select a valid '.($purchases ? 'purchase' : 'sales').' account from this company’s Chart of Accounts.']);
            }
            $record['relief_category'] = $account->relief_category;
            if ((float) $record['taxable_amount'] !== 0.0 && ! isset(ChartAccount::RELIEF_CATEGORIES[$record['relief_category']])) {
                throw ValidationException::withMessages([$recordsKey.".{$index}.relief_category" => 'Set the RELIEF category for '.$account->code.' · '.$account->name.' in Masters → Chart of Accounts before generating DAT.']);
            }
        }
        unset($record);
        $files = $relief->files($data, $kind);
        foreach ($files as $filename => $content) {
            app(TaxPreflightService::class)->recordExport($request, ['schedule' => $purchases ? 'relief-purchases' : 'relief-sales', 'from' => $data['from'], 'to' => $data['to']], $filename, [$content]);
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no'];
        if (count($files) === 1) {
            return response()->streamDownload(function () use ($files): void {
                echo reset($files);
            }, array_key_first($files), ['Content-Type' => 'text/plain; charset=windows-1252', ...$headers]);
        }

        $path = tempnam(sys_get_temp_dir(), 'relief-dat-');
        abort_if($path === false, 500, 'Could not prepare the DAT archive.');
        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the DAT archive.');
            }
            $opened = true;
            foreach ($files as $filename => $content) {
                if (! $zip->addFromString($filename, $content)) {
                    throw new RuntimeException('Could not add a monthly DAT file.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the DAT archive.');
            }
            $opened = false;
        } catch (Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return response()->streamDownload(static function () use ($path): void {
            $stream = fopen($path, 'rb');
            try {
                if ($stream === false) {
                    throw new RuntimeException('Could not read the DAT archive.');
                }
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                unlink($path);
            }
        }, 'RELIEF-'.$label.'-'.$data['from'].'-to-'.$data['to'].'.zip', ['Content-Type' => 'application/zip', ...$headers]);
    }
}
