<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrepareTaxReturnRequest;
use App\Models\CompanySetting;
use App\Services\Tax\BirExporterFactory;
use App\Services\Tax\TaxReturnCatalog;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\TaxReturnWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReturnController extends Controller
{
    public function index(Request $request, TaxReturnCatalog $catalog, TaxReturnWorkspace $workspace): Response
    {
        $data = $request->validate(['frequency' => ['nullable', Rule::in(['monthly', 'quarterly', 'annual'])], 'year' => ['nullable', 'integer', 'between:2023,2199'], 'period' => ['nullable', 'integer', 'between:1,12']]);
        $frequency = $data['frequency'] ?? 'monthly';
        $today = CarbonImmutable::now('Asia/Manila');
        $year = (int) ($data['year'] ?? $today->year);
        $period = (int) ($data['period'] ?? match ($frequency) {
            'monthly' => $today->month, 'quarterly' => $today->quarter, default => 1
        });
        $company = $request->attributes->get('active_company');
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $annualSettings = $workspace->yearSettings($company, $year);
        $cards = [];
        foreach (TaxReturnCatalog::forms() as $form => $definition) {
            if ($definition['frequency'] === $frequency) {
                $cards[$form] = [...$definition, 'title' => $definition['label'], ...$catalog->period($form, $year, $period, $profile)];
            }
        }
        $filings = DB::table('tax_return_filing_records')->where('company_id', $company->id)->whereBetween('period_to', [($year - 1).'-01-01', $year.'-12-31'])->get()->keyBy(fn (object $row): string => $row->form.'|'.$row->period_from.'|'.$row->period_to);
        $proofs = DB::table('tax_return_proofs')->where('company_id', $company->id)->whereIn('filing_id', $filings->pluck('id'))->get(['id', 'filing_id', 'original_name', 'size', 'created_at'])->groupBy('filing_id');
        foreach ($cards as $form => &$card) {
            $card['filing'] = $filings->get($form.'|'.$card['from'].'|'.$card['to']);
            $card['versions'] = DB::table('tax_return_preparations')->where('company_id', $company->id)->where('form', $form)->where('period_from', $card['from'])->where('period_to', $card['to'])->latest('id')->limit(5)->get(['id', 'status', 'created_at', 'filing_reference', 'filed_on', 'settings_year', 'year_settings_revision']);
            $card['proofs'] = $proofs->get($card['filing']?->id, collect());
            if ($card['filing']?->filed_on === null && ($legacy = $card['versions']->firstWhere('status', 'filed'))) {
                $card['filing'] = (object) ['filed_on' => $legacy->filed_on, 'reference' => $legacy->filing_reference];
            }
        }
        unset($card);
        $archive = DB::table('tax_return_preparations')->where('company_id', $company->id)->select(['id', 'form', 'period_from', 'period_to', 'created_at', 'status', 'filing_reference', 'filed_on', 'settings_year', 'year_settings_revision'])->orderByDesc('id')->paginate(15)->withQueryString();

        $archive->getCollection()->each(function (object $row) use ($company, $workspace): void {
            $row->settings_drifted = $row->settings_year !== null && (int) $row->year_settings_revision !== $workspace->yearSettings($company, (int) $row->settings_year)['revision'];
        });

        return response()->view('tax-returns', ['company' => $request->session()->get('company_setup'), 'schedule' => 'tax-returns', 'cards' => $cards, 'archive' => $archive, 'frequency' => $frequency, 'year' => $year, 'period' => $period, 'annualSettings' => $annualSettings, 'canEditTaxSettings' => in_array('settings', $request->attributes->get('company_allowed_tabs', []), true)])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, TaxReturnService $service): JsonResponse
    {
        $input = $request->validate(['form' => ['required', Rule::in(array_keys(TaxReturnCatalog::forms()))], 'year' => ['required', 'integer', 'between:2023,2199'], 'period' => ['required', 'integer', 'between:1,12']]);

        return response()->json($service->preview($request->attributes->get('active_company'), $input))->header('Cache-Control', 'no-store, private');
    }

    public function prepare(PrepareTaxReturnRequest $request, TaxReturnService $service): JsonResponse
    {
        $saved = $service->prepare($request->attributes->get('active_company'), $request->user()->id, $request->validated());

        return response()->json(['message' => 'Preparation saved. This return has not been submitted to BIR.', ...$saved], $saved['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, int $id): Response
    {
        $record = $this->record($request, $id);

        return response()->view('tax-return-print', ['record' => $record, 'snapshot' => json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR)])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $record = $this->record($request, $id);
        $snapshot = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);

        return response()->streamDownload(function () use ($snapshot, $record): void {
            $stream = fopen('php://output', 'wb');
            $row = function (array $values) use ($stream): void {
                $safe = array_map(fn (mixed $value): string => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'".$value : (string) $value, $values);
                fputcsv($stream, $safe, ',', '"', '', "\r\n");
            };
            $row(['Tax preparation worksheet', $record->form, 'Not an eBIRForms import file']);
            $row(['Company', $snapshot['taxpayer']['company_name'] ?? '']);
            $row(['TIN', ($snapshot['taxpayer']['tin'] ?? '').' / '.($snapshot['taxpayer']['branch_code'] ?? '')]);
            $row(['Period', $record->period_from, $record->period_to]);
            $row(['Filing status', $record->status === 'filed' ? 'External filing recorded' : 'Prepared only']);
            $row(['Source records', $snapshot['source_count']]);
            if ($snapshot['manual'] || $snapshot['definition']['source'] === 'manual') {
                $row(['Source computation', 'Separately reviewed input; no automatic source totals inferred.']);
            } else {
                foreach (['gross', 'withheld'] as $key) {
                    $row(['Source '.$key, number_format($snapshot['source_totals'][$key] / 100, 2, '.', '')]);
                }
            }
            foreach ($snapshot['source_totals']['details'] as $key => $values) {
                $row(is_array($values) ? [$key, number_format($values['base'] / 100, 2, '.', ''), number_format($values['withheld'] / 100, 2, '.', '')] : [$key, number_format($values / 100, 2, '.', '')]);
            }
            foreach ($snapshot['calculation'] as $key => $value) {
                $row([str_replace('_', ' ', $key), number_format($value / 100, 2, '.', '')]);
            }
            $row(['Return basis', ! empty($snapshot['amended']) ? 'Amended worksheet' : 'Original worksheet']);
            foreach ($snapshot['annual_settings']['settings'] ?? [] as $key => $value) {
                $row(['Annual setting: '.str_replace('_', ' ', $key), $value ?? 'Not specified']);
            }
            $row(['Review note', $snapshot['review_note']]);
            $row(['Source hash', $snapshot['source_hash']]);
            fclose($stream);
        }, 'tax-worksheet-'.$record->form.'-'.$record->period_to.'-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function xml(Request $request, int $id, BirExporterFactory $factory): StreamedResponse
    {
        $record = $this->record($request, $id);
        $snapshot = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(isset($snapshot['bir_xml']), 422, 'Prepare a new version with eBIRForms XML enabled and complete its review fields first.');
        $exporter = $factory->make($record->form);
        $prepared = $snapshot['bir_xml'];
        $exporter->assertPrepared($prepared);

        return response()->streamDownload(function () use ($exporter, $prepared): void {
            foreach ($exporter->stream($prepared) as $chunk) {
                echo $chunk;
            }
        }, $prepared['filename'], ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-BIR-Validation' => 'not-verified']);
    }

    public function filed(Request $request, int $id, TaxReturnWorkspace $workspace): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:150'], 'filed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $id, $data, $workspace): void {
            $record = DB::table('tax_return_preparations')->where('company_id', $request->attributes->get('active_company')->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_if($data['filed_on'] < $record->period_to, 422, 'The filing date must be on or after the reporting period ends.');
            if ($record->status === 'filed') {
                abort_unless($record->filing_reference === $data['reference'] && $record->filed_on === $data['filed_on'], 409, 'An external filing reference is already recorded. It cannot be overwritten.');

                return;
            }
            $periodFiling = $workspace->filing($request->attributes->get('active_company'), $record->form, ['from' => $record->period_from, 'to' => $record->period_to]);
            abort_if($periodFiling->filed_on !== null && ($periodFiling->filed_on !== $data['filed_on'] || $periodFiling->reference !== $data['reference']), 409, 'A different filing is already recorded for this form and period.');
            DB::table('tax_return_filing_records')->where('company_id', $record->company_id)->where('id', $periodFiling->id)->update(['reference' => $data['reference'], 'filed_on' => $data['filed_on'], 'filed_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('tax_return_preparations')->where('company_id', $record->company_id)->where('id', $record->id)->update(['status' => 'filed', 'filing_reference' => $data['reference'], 'filed_on' => $data['filed_on'], 'filed_by' => $request->user()->id, 'updated_at' => now()]);
        });

        return response()->json(['message' => 'External filing reference recorded. This does not verify BIR acceptance or payment.'])->header('Cache-Control', 'no-store, private');
    }

    private function record(Request $request, int $id): object
    {
        return DB::table('tax_return_preparations')->where('company_id', $request->attributes->get('active_company')->id)->where('id', $id)->firstOrFail();
    }
}
