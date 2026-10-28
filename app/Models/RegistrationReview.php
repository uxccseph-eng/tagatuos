<?php

namespace App\Models;

use Database\Factories\RegistrationReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['actor_id', 'applicant_id', 'action', 'previous_status', 'username', 'reason'])]
class RegistrationReview extends Model
{
    use CentralConnection;

    /** @use HasFactory<RegistrationReviewFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }
}
