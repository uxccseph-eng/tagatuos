<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'company_ledger_id', 'company_name', 'tin', 'reporting_year', 'ledger_revision', 'balance_sheet', 'income_statement', 'equity', 'cash_flows'])]
class FinancialStatement extends Model
{
    use BelongsToCompany;

    protected $casts = [
        'reporting_year' => 'integer',
        'ledger_revision' => 'integer',
        'balance_sheet' => 'array',
        'income_statement' => 'array',
        'equity' => 'array',
        'cash_flows' => 'array',
    ];
}
