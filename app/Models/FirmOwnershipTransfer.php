<?php

namespace App\Models;

use Database\Factories\FirmOwnershipTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['source_password_fingerprint'])]
class FirmOwnershipTransfer extends Model
{
    /** @use HasFactory<FirmOwnershipTransferFactory> */
    use HasFactory;

    use UsesCentralDatabase;

    protected function casts(): array
    {
        return ['scope_summary' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
