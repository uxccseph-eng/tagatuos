<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_key', 'name', 'bank', 'account_number', 'chart_account_id', 'currency', 'archived'])]
#[Hidden(['workspace_key'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    protected $appends = ['gl_account'];

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'chart_account_id' => 'integer'];
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function getGlAccountAttribute(): string
    {
        return $this->chartAccount ? $this->chartAccount->code.' · '.$this->chartAccount->name : '—';
    }
}
