<?php

namespace App\Domain\Alerts\Models;

use App\Support\Locale\SupportedLocale;
use Database\Factories\AlertAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One affected area of a warning, docs/03-data-contracts.md section 7.
 *
 * Geometry is GeoJSON rather than a PostGIS column; see the migration for why.
 * The `bbox_*` columns are derived from it at import so an extent filter reads
 * the same on PostgreSQL and SQLite.
 *
 * @property int $id
 * @property int $alert_message_id
 * @property string $description_tj
 * @property string $description_ru
 * @property string $description_en
 * @property list<array{name: string, value: string}> $geocodes
 * @property array<string, mixed>|null $geometry
 * @property string|null $bbox_west
 * @property string|null $bbox_south
 * @property string|null $bbox_east
 * @property string|null $bbox_north
 * @property string|null $altitude_m
 * @property string|null $ceiling_m
 * @property-read AlertMessage $message
 */
#[Fillable([
    'alert_message_id',
    'description_tj',
    'description_ru',
    'description_en',
    'geocodes',
    'geometry',
    'bbox_west',
    'bbox_south',
    'bbox_east',
    'bbox_north',
    'altitude_m',
    'ceiling_m',
])]
#[UseFactory(AlertAreaFactory::class)]
class AlertArea extends Model
{
    /** @use HasFactory<AlertAreaFactory> */
    use HasFactory;

    protected $dateFormat = AlertMessage::TIMESTAMP_FORMAT;

    /**
     * Write-once, matching the database triggers in
     * `2026_09_02_120011_add_alert_history_immutability_guards`.
     *
     * An area is the shape a warning was published for. Editing one would
     * silently restate where a past warning applied, and deleting one would
     * leave a message claiming an extent it no longer has. A corrected extent
     * arrives as a new message, with its own areas.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Alert areas are immutable; a corrected extent arrives as a new message.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Alert areas are never deleted; the published extent has to stay readable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geocodes' => 'array',
            'geometry' => 'array',
            // Decimal rather than float so a re-import of an unchanged feed
            // produces no spurious update.
            'bbox_west' => 'decimal:6',
            'bbox_south' => 'decimal:6',
            'bbox_east' => 'decimal:6',
            'bbox_north' => 'decimal:6',
            'altitude_m' => 'decimal:2',
            'ceiling_m' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<AlertMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AlertMessage::class, 'alert_message_id');
    }

    public function localizedDescription(?SupportedLocale $locale = null): string
    {
        $locale ??= SupportedLocale::current();
        $value = $this->getAttribute('description_'.$locale->value);

        return is_string($value) ? $value : '';
    }

    /**
     * Whether this area can be drawn on the map at all.
     *
     * An area identified only by geocode needs Hydromet's administrative
     * boundary dataset before it has a shape
     * (docs/08-hydromet-input-checklist.md, section 3).
     */
    public function isDrawable(): bool
    {
        return $this->geometry !== null;
    }
}
