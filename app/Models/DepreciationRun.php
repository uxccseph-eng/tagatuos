<?php

namespace App\Models;

use Database\Factories\DepreciationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_key', 'period', 'number', 'snapshot', 'journal', 'total_cents', 'posted_by', 'posted_at'])]
#[Hidden(['workspace_key'])]
class DepreciationRun extends Model
{
    /** @use HasFactory<DepreciationRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'journal' => 'array', 'total_cents' => 'integer', 'posted_at' => 'datetime'];
    }
}
