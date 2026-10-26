<?php

namespace App\Models;

use Database\Factories\TeamLoginTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'user_id', 'issued_by', 'expires_at', 'used_at'])]
class TeamLoginToken extends Model
{
    /** @use HasFactory<TeamLoginTokenFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }
}
