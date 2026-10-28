<?php

namespace App\Models;

use Database\Factories\MonthlyClosingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyClosing extends Model
{
    /** @use HasFactory<MonthlyClosingFactory> */
    use HasFactory;

    use UsesCentralDatabase;

    public const STATUSES = ['not_started' => 'Not started', 'in_progress' => 'In progress', 'for_review' => 'For review', 'completed' => 'Completed'];

    public const CHECKLIST = ['documents' => 'Source documents collected', 'bookkeeping' => 'Bookkeeping reviewed', 'reconciliation' => 'Cash and bank reconciliation reviewed', 'withholding' => 'Withholding records reviewed', 'reports' => 'Financial reports reviewed'];

    /** @return array<string, bool> */
    public static function emptyChecklist(): array
    {
        return array_fill_keys(array_keys(self::CHECKLIST), false);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checklist' => 'array', 'version' => 'integer', 'completed_at' => 'datetime'];
    }
}
