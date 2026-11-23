<?php

namespace App\Http\Controllers;

use App\Models\CompanyLedger;
use App\Services\ClientDatabase;
use App\Services\CompanyLedgerService;
use App\Services\Tax\TaxReturnWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxReversionController extends Controller
{
    public function filing(Request $request, int $id, TaxReturnWorkspace $workspace): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmed' => ['accepted'], 'revision' => ['required', 'integer', 'min:1']]);
        $company = $request->attributes->get('active_company');
        $workspace->mutate($request, $company, function () use ($request, $company, $id, $input): void {
            $record = DB::table('tax_return_preparations')->where('company_id', $company->id)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->revision === (int) $input['revision'], 409, 'This return changed. Refresh before reverting its filing status.');
            $key = ['company_id' => $company->id, 'form' => $record->form, 'period_from' => $record->period_from, 'period_to' => $record->period_to];
            if (! $record->amended) {
                abort_if(DB::table('tax_return_preparations')->where($key)->where('amended', true)->where('status', 'filed')->whereNull('deleted_at')->exists(), 422, 'Revert filed amendments for this period first.');
            }
            $filing = $record->amended ? null : DB::table('tax_return_filing_records')->where($key)->lockForUpdate()->first();
            abort_unless($record->status === 'filed' || $filing?->filed_on, 409, 'This return is already prepared.');
            $records = $record->amended ? [$record] : DB::table('tax_return_preparations')->where($key)->where('amended', false)->whereNull('deleted_at')->lockForUpdate()->get();
            foreach ($records as $row) {
                $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                $snapshot['filing_reversions'][] = ['reason' => $input['reason'], 'actor_id' => $request->user()->id, 'at' => now()->toIso8601String(), 'reference' => $row->filing_reference ?: $filing?->reference, 'filed_on' => $row->filed_on ?: $filing?->filed_on];
                DB::table('tax_return_preparations')->where('id', $row->id)->where('company_id', $company->id)->update(['status' => 'prepared', 'filing_reference' => null, 'filed_on' => null, 'filed_by' => null, 'revision' => $row->revision + 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            if ($filing) {
                DB::table('tax_return_filing_records')->where('id', $filing->id)->where('company_id', $company->id)->update(['reference' => null, 'filed_on' => null, 'filed_by' => null, 'updated_at' => now()]);
            }
        });

        return back()->with('status', 'Returned to Prepared in this app. No BIR filing or payment was cancelled.');
    }

    public function summary(Request $request, string $tax, int $id, TaxReturnWorkspace $workspace, CompanyLedgerService $ledger): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmed' => ['accepted']]);
        $tables = ['vat' => 'vat_summary_events', 'income' => 'income_tax_summary_events', 'ewt' => 'ewt_summary_events', 'compensation' => 'compensation_summary_events'];
        abort_unless(isset($tables[$tax]), 404);
        $company = $request->attributes->get('active_company');
        $workspace->mutate($request, $company, function () use ($request, $tax, $id, $tables, $company, $input, $ledger): void {
            $table = $tables[$tax];
            $event = DB::table($table)->where('company_id', $company->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($event->action, ['payment', 'close'], true), 409, 'This action has already been reverted or is not reversible here.');
            $data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
            $quarter = $tax === 'compensation' ? (int) ceil($event->month / 3) : (int) $event->quarter;
            $closes = DB::table('vat_summary_events')->where('company_id', $company->id)->where('action', 'close')->get();
            if ($tax === 'vat') {
                abort_if($closes->contains(fn (object $close): bool => $close->id !== $event->id && ($close->year > $event->year || ($close->year === $event->year && $close->quarter >= $quarter))), 422, 'Reopen the latest dependent VAT quarter first, working backwards.');
            }
            $state = CompanyLedger::query()->where('company_id', $company->id)->lockForUpdate()->first();
            $source = $data['linked_source'] ?? '';
            $journal = collect($state?->state['journalEntries'] ?? [])->first(fn (array $row): bool => 'journalEntries:'.$row['id'] === $source);
            $origins = ['vat' => 'vat-', 'income' => 'income-tax-', 'ewt' => 'ewt-', 'compensation' => 'compensation-'];
            $generated = $journal && ($journal['origin'] ?? '') === $origins[$tax].$event->action;
            if ($generated) {
                abort_unless($journal['status'] === 'posted', 409, 'The generated journal changed. Review its current status first.');
                abort_if(app(ClientDatabase::class)->central()->table('monthly_closings')->where('company_id', $company->id)->where('month', substr($journal['date'], 0, 7))->where('status', 'completed')->exists(), 422, 'Reopen the completed posting month before reverting this entry.');
            }
            $data['reversion'] = ['reason' => $input['reason'], 'actor_id' => $request->user()->id, 'at' => now()->toIso8601String(), 'journal_voided' => (bool) $generated];
            DB::table($table)->where('company_id', $company->id)->where('id', $id)->update(['action' => $event->action.'-reverted', 'slot' => null, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]);
            if ($generated) {
                $versions = $ledger->read($company)['record_versions'];
                $journal['status'] = 'void';
                $journal['voidReason'] = $input['reason'];
                $ledger->save($company, $request->user(), ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => $journal['id'], 'expected_version' => $versions['journalEntries'][(string) $journal['id']], 'record' => $journal]]]);
            }
        });

        return back()->with('status', 'Action reverted. History retained; generated journal voided where applicable. Existing manually linked journals were preserved.');
    }
}
