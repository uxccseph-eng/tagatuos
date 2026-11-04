<?php

namespace App\Services\Tax;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class TaxReturnWorkspace
{
    /** @return array{revision: int, settings: array<string, mixed>} */
    public function yearSettings(Company $company, int $year): array
    {
        $row = DB::table('tax_return_year_settings')->where('company_id', $company->id)->where('year', $year)->first();

        return ['revision' => (int) ($row?->revision ?? 0), 'settings' => $row ? json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR) : ['regime' => 'regular', 'deduction_method' => 'itemized', 'mcit_start_year' => null, 'president' => '', 'treasurer' => '']];
    }

    /** @param array{from: string, to: string} $period */
    public function filing(Company $company, string $form, array $period): object
    {
        $key = ['company_id' => $company->id, 'form' => $form, 'period_from' => $period['from'], 'period_to' => $period['to']];
        $legacy = DB::table('tax_return_preparations')->where($key)->where('status', 'filed')->latest('id')->first(['filing_reference', 'filed_on', 'filed_by']);
        DB::table('tax_return_filing_records')->insertOrIgnore([...$key, 'reference' => $legacy?->filing_reference, 'filed_on' => $legacy?->filed_on, 'filed_by' => $legacy?->filed_by, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('tax_return_filing_records')->where($key)->lockForUpdate()->firstOrFail();
    }
}
