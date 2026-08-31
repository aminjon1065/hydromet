<?php

namespace App\Domain\Integrations\Models;

use App\Support\Canonical\RejectedRow;
use App\Support\Canonical\RejectionReason;
use Database\Factories\SynchronizationRejectedRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quarantined row, stored exactly as the import service reported it.
 *
 * The three fields come straight from a {@see RejectedRow},
 * which sanitizes them at construction. Nothing of the offending row itself is
 * kept: an operator learns which row failed and why, and goes to the provider's
 * own export for the rest.
 *
 * `reason_code` is stored as a string rather than a database enumeration so a
 * new rejection reason does not need a migration; the cast still resolves it to
 * {@see RejectionReason} for code that reads it.
 *
 * @property int $id
 * @property int $synchronization_run_id
 * @property string $reference
 * @property RejectionReason $reason_code
 * @property string $safe_detail
 * @property-read SynchronizationRun $run
 */
#[Fillable([
    'synchronization_run_id',
    'reference',
    'reason_code',
    'safe_detail',
])]
#[UseFactory(SynchronizationRejectedRowFactory::class)]
class SynchronizationRejectedRow extends Model
{
    /** @use HasFactory<SynchronizationRejectedRowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_code' => RejectionReason::class,
        ];
    }

    /**
     * @return BelongsTo<SynchronizationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SynchronizationRun::class, 'synchronization_run_id');
    }
}
