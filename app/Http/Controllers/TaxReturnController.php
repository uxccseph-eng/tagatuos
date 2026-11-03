<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrepareTaxReturnRequest;
use App\Models\CompanySetting;
use App\Services\Tax\TaxReturnCatalog;
use App\Services\Tax\TaxReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReturnController extends Controller
{
    public function index(Request $request, TaxReturnCatalog $catalog): Response
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
        $cards = [];
        foreach (TaxReturnCatalog::forms() as $form => $definition) {
            if ($definition['frequency'] === $frequency) {
                $cards[$form] = [...$definition, 'title' => $definition['label'], ...$catalog->period($form, $year, $period, $profile)];
            }
        }
        $archive = DB::table('tax_return_preparations')->where('company_id', $company->id)->select(['id', 'form', 'period_from', 'period_to', 'created_at', 'status', 'filing_reference', 'filed_on'])->orderByDesc('id')->paginate(15)->withQueryString();

        return response()->view('tax-returns', ['company' => $request->session()->get('company_setup'), 'schedule' => 'tax-returns', 'cards' => $cards, 'archive' => $archive, 'frequency' => $frequency, 'year' => $year, 'period' => $period])->header('Cache-Control', 'no-store, private');
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
            if ($snapshot['manual']) {
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
            $row(['Review note', $snapshot['review_note']]);
            $row(['Source hash', $snapshot['source_hash']]);
            fclose($stream);
        }, 'tax-worksheet-'.$record->form.'-'.$record->period_to.'-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function filed(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:150'], 'filed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $id, $data): void {
            $record = DB::table('tax_return_preparations')->where('company_id', $request->attributes->get('active_company')->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_if($data['filed_on'] < $record->period_to, 422, 'The filing date must be on or after the reporting period ends.');
            if ($record->status === 'filed') {
                abort_unless($record->filing_reference === $data['reference'] && $record->filed_on === $data['filed_on'], 409, 'An external filing reference is already recorded. It cannot be overwritten.');

                return;
            }
            DB::table('tax_return_preparations')->where('company_id', $record->company_id)->where('id', $record->id)->update(['status' => 'filed', 'filing_reference' => $data['reference'], 'filed_on' => $data['filed_on'], 'filed_by' => $request->user()->id, 'updated_at' => now()]);
        });

        return response()->json(['message' => 'External filing reference recorded. This does not verify BIR acceptance or payment.'])->header('Cache-Control', 'no-store, private');
    }

    private function record(Request $request, int $id): object
    {
        return DB::table('tax_return_preparations')->where('company_id', $request->attributes->get('active_company')->id)->where('id', $id)->firstOrFail();
    }
}
