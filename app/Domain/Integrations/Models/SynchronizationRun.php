<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Services\SynchronizationRunner;
use Database\Factories\SynchronizationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import attempt, docs/03-data-contracts.md section 8.2.
 *
 * Written only by {@see SynchronizationRunner}
 * and never updated afterwards: the journal is the evidence of what an import
 * did, so rewriting it would defeat its purpose.
 *
 * @property int $id
 * @property int $source_id
 * @property SynchronizationKind $kind
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property SynchronizationStatus $status
 * @property Carbon|null $cursor_from
 * @property Carbon|null $cursor_to
 * @property int $received_count
 * @property int $accepted_count
 * @property int $updated_count
 * @property int $rejected_count
 * @property string|null $error_code
 * @property string|null $sanitized_error
 * @property string|null $response_checksum
 * @property-read IntegrationSource $source
 * @property-read Collection<int, SynchronizationRejectedRow> $rejectedRows
 */
#[Fillable([
    'source_id',
    'kind',
    'started_at',
    'finished_at',
    'status',
    'cursor_from',
    'cursor_to',
    'received_count',
    'accepted_count',
    'updated_count',
    'rejected_count',
    'error_code',
    'sanitized_error',
    'response_checksum',
])]
#[UseFactory(SynchronizationRunFactory::class)]
class SynchronizationRun extends Model
{
    /** @use HasFactory<SynchronizationRunFactory> */
    use HasFactory;

    /**
     * Stable code stored when a run stopped on something the portal did not
     * anticipate. The exception itself goes to the log; the journal says only
     * that it happened and where to look.
     */
    public const ERROR_UNEXPECTED = 'unexpected_error';

    /**
     * Timestamps keep the same microsecond precision as the observations a run
     * imports, so a run cannot appear to have finished before it started.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SynchronizationKind::class,
            'status' => SynchronizationStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cursor_from' => 'datetime',
            'cursor_to' => 'datetime',
            'received_count' => 'integer',
            'accepted_count' => 'integer',
            'updated_count' => 'integer',
            'rejected_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IntegrationSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(IntegrationSource::class, 'source_id');
    }

    /**
     * @return HasMany<SynchronizationRejectedRow, $this>
     */
    public function rejectedRows(): HasMany
    {
        return $this->hasMany(SynchronizationRejectedRow::class);
    }

    public function durationInMilliseconds(): ?float
    {
        if ($this->finished_at === null) {
            return null;
        }

        return $this->started_at->diffInMilliseconds($this->finished_at);
    }
}
