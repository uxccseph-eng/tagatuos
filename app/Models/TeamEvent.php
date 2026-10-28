<?php

namespace App\Models;

use Database\Factories\TeamEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'actor_id', 'subject_id', 'action', 'details'])]
class TeamEvent extends Model
{
    /** @use HasFactory<TeamEventFactory> */
    use HasFactory;

    use UsesCentralDatabase;

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
