<?php

namespace App\Models;

use Database\Factories\CompanyLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'revision', 'state', 'payroll'])]
class CompanyLedger extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CompanyLedgerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['state' => 'array', 'payroll' => 'array', 'revision' => 'integer'];
    }
}
