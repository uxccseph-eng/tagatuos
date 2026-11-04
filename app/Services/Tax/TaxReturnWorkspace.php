<?php

namespace App\Services\Tax;

use App\Models\Company;
use App\TeamPermissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxReturnWorkspace
{
    /** @return array{revision: int, settings: array<string, mixed>} */
    public function yearSettings(Company $company, int $year): array
    {
        $row = DB::table('tax_return_year_settings')->where('company_id', $company->id)->where('year', $year)->first();

        return ['revision' => (int) ($row?->revision ?? 0), 'settings' => $row ? json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR) : ['regime' => 'regular', 'deduction_method' => 'itemized', 'mcit_start_year' => null, 'president' => '', 'treasurer' => '']];
    }

    public function preparations(Company $company): Builder
    {
        return DB::table('tax_return_preparations')->where('company_id', $company->id)->whereNull('deleted_at')->where(fn (Builder $query): Builder => $query->where('amended', true)->orWhere('original_slot', 1));
    }

    /** @param iterable<object> $rows */
    public function decorateArchive(Company $company, iterable $rows): void
    {
        $filings = [];
        foreach ($rows as $row) {
            $filing = null;
            if (! $row->amended) {
                $key = $row->form.'|'.$row->period_from.'|'.$row->period_to;
                if (! array_key_exists($key, $filings)) {
                    $filings[$key] = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', $row->form)->where('period_from', $row->period_from)->where('period_to', $row->period_to)->first(['filed_on', 'reference']);
                }
                $filing = $filings[$key];
            }
            $row->is_filed = $row->status === 'filed' || $filing?->filed_on !== null;
            $row->display_reference = $row->filing_reference ?: $filing?->reference;
        }
    }

    /** @param array{from: string, to: string} $period */
    public function filing(Company $company, string $form, array $period): object
    {
        $key = ['company_id' => $company->id, 'form' => $form, 'period_from' => $period['from'], 'period_to' => $period['to']];
        $legacy = DB::table('tax_return_preparations')->where($key)->where('amended', false)->whereNull('deleted_at')->where('status', 'filed')->latest('id')->first(['filing_reference', 'filed_on', 'filed_by']);
        DB::table('tax_return_filing_records')->insertOrIgnore([...$key, 'reference' => $legacy?->filing_reference, 'filed_on' => $legacy?->filed_on, 'filed_by' => $legacy?->filed_by, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('tax_return_filing_records')->where($key)->lockForUpdate()->firstOrFail();
    }

    public function mutate(Request $request, Company $company, callable $callback): mixed
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($request, $company, $callback): mixed {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            abort_if($locked->deletionPending(), 409, 'This client is being deleted.');
            $member = $locked->users()->where('users.id', $request->user()->id)->where('registration_status', 'approved')->wherePivot('is_suspended', false)->first();
            $allowed = $member ? TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs) : [];
            abort_unless(in_array('tax-reports', $allowed, true) && (! $request->routeIs('tax-returns.year-settings') || in_array('settings', $allowed, true)), 403, 'Your current role does not allow this change.');

            return DB::transaction($callback);
        });
    }
}
