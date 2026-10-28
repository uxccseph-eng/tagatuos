<?php

namespace App\Models;

use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_key', 'lookup_key', 'profile', 'preferences'])]
class CompanySetting extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['profile' => 'array', 'preferences' => 'array'];
    }
}
