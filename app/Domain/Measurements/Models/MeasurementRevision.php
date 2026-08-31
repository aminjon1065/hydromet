<?php

namespace App\Domain\Measurements\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\RevisionOrigin;
use Database\Factories\MeasurementRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One applied change to a measurement, docs/03-data-contracts.md section 5.3.
 *
 * Written only by the Measurements import service. Nothing updates or deletes a
 * revision row: the history is the evidence that a published value changed, so
 * rewriting it would defeat its purpose.
 *
 * @property int $id
 * @property int $measurement_id
 * @property int $revision
 * @property string|null $previous_value
 * @property MeasurementQuality $previous_quality
 * @property string|null $corrected_value
 * @property MeasurementQuality $corrected_quality
 * @property string $reason_code
 * @property string|null $reason_text
 * @property RevisionOrigin $change_origin
 * @property int|null $changed_by
 * @property Carbon|null $source_updated_at
 * @property-read Measurement $measurement
 * @property-read User|null $changedBy
 */
#[Fillable([
    'measurement_id',
    'revision',
    'previous_value',
    'previous_quality',
    'corrected_value',
    'corrected_quality',
    'reason_code',
    'reason_text',
    'change_origin',
    'changed_by',
    'source_updated_at',
])]
#[UseFactory(MeasurementRevisionFactory::class)]
class MeasurementRevision extends Model
{
    /** @use HasFactory<MeasurementRevisionFactory> */
    use HasFactory;

    /**
     * Reason code recorded when a provider supplied a newer revision. The
     * provider gives no free-text reason, so the portal does not invent one.
     */
    public const REASON_SOURCE_REVISION = 'source_revision';

    /**
     * History keeps the same timestamp precision as the measurements it
     * describes, so a revision cannot appear to have been supplied at a
     * different instant than the observation it corrects.
     */
    protected $dateFormat = Measurement::TIMESTAMP_FORMAT;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'previous_value' => 'decimal:6',
            'corrected_value' => 'decimal:6',
            'previous_quality' => MeasurementQuality::class,
            'corrected_quality' => MeasurementQuality::class,
            'change_origin' => RevisionOrigin::class,
            'source_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Measurement, $this>
     */
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
