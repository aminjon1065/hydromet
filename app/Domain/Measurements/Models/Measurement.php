<?php

namespace App\Domain\Measurements\Models;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Database\Factories\MeasurementFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One observation, docs/03-data-contracts.md section 5.
 *
 * `original_value` and `original_quality` are what the source first supplied and
 * are written once. `value` and `quality` carry the effective source revision.
 * Public reads use the effective pair; the difference between them is what
 * makes a correction visible (docs/03-data-contracts.md, section 5.3).
 *
 * `sensor_key` is a database-generated column backing the natural key and is
 * deliberately absent from `$fillable`: writing it is the database's job.
 *
 * @property int $id
 * @property string $source
 * @property string|null $source_measurement_id
 * @property int $station_id
 * @property int $parameter_id
 * @property string|null $sensor_no
 * @property Carbon $observed_at
 * @property Carbon|null $received_at
 * @property string|null $original_value
 * @property MeasurementQuality $original_quality
 * @property string|null $value
 * @property string $unit
 * @property string|null $averaging_period
 * @property MeasurementQuality $quality
 * @property list<string> $quality_flags
 * @property int $revision
 * @property bool $is_manual
 * @property Carbon|null $source_updated_at
 * @property-read Station $station
 * @property-read Parameter $parameter
 * @property-read Collection<int, MeasurementRevision> $revisions
 */
#[Fillable([
    'source',
    'source_measurement_id',
    'station_id',
    'parameter_id',
    'sensor_no',
    'observed_at',
    'received_at',
    'original_value',
    'original_quality',
    'value',
    'unit',
    'averaging_period',
    'quality',
    'quality_flags',
    'revision',
    'is_manual',
    'source_updated_at',
])]
#[UseFactory(MeasurementFactory::class)]
class Measurement extends Model
{
    /** @use HasFactory<MeasurementFactory> */
    use HasFactory;

    /**
     * Storage format for every timestamp on this model.
     *
     * Eloquent's default drops fractional seconds, which would collapse two
     * observations taken within the same second into one natural key. The
     * columns are `timestamp(6)`, so the written form carries six digits too.
     *
     * Query bindings do NOT use this format — `Connection::prepareBindings()`
     * asks the query grammar instead — so a lookup by `observed_at` has to
     * format the value itself. See {@see formatTimestamp()}.
     */
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    protected $dateFormat = self::TIMESTAMP_FORMAT;

    /**
     * Render an instant the way this model stores it, for use as a query
     * binding. Passing a DateTimeInterface straight to `where()` would be
     * formatted by the grammar at whole-second precision and match nothing.
     */
    public static function formatTimestamp(DateTimeInterface $moment): string
    {
        return $moment->format(self::TIMESTAMP_FORMAT);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'received_at' => 'datetime',
            'source_updated_at' => 'datetime',
            // Decimal rather than float: an unchanged re-import must not look
            // like a change, and 0.1 must round-trip exactly.
            'original_value' => 'decimal:6',
            'value' => 'decimal:6',
            'original_quality' => MeasurementQuality::class,
            'quality' => MeasurementQuality::class,
            'quality_flags' => 'array',
            'revision' => 'integer',
            'is_manual' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @return BelongsTo<Parameter, $this>
     */
    public function parameter(): BelongsTo
    {
        return $this->belongsTo(Parameter::class);
    }

    /**
     * @return HasMany<MeasurementRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(MeasurementRevision::class);
    }

    /**
     * Whether the effective value differs from what the source first supplied.
     *
     * This is the `corrected` flag public queries expose
     * (docs/03-data-contracts.md, section 5.3).
     */
    public function isCorrected(): bool
    {
        return $this->revision > 1
            && ($this->value !== $this->original_value || $this->quality !== $this->original_quality);
    }
}
