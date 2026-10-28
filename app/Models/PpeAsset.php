<?php

namespace App\Models;

use Database\Factories\PpeAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_key', 'code', 'name', 'category', 'serial_number', 'acquired_date', 'service_date', 'disposal_date', 'cost_cents', 'residual_cents', 'life_months', 'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'notes', 'capitalization_reference', 'archived'])]
#[Hidden(['workspace_key'])]
class PpeAsset extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PpeAssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['cost_cents' => 'integer', 'residual_cents' => 'integer', 'life_months' => 'integer', 'archived' => 'boolean'];
    }
}
