<?php

namespace App\Domain\Stations\Models;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Support\Locale\SupportedLocale;
use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Station registry record from docs/03-data-contracts.md section 3.
 *
 * `source` + `external_id` is the import identity. `code` is a display code and
 * is unique within a source, but a provider may reissue it, so it is never used
 * to match an incoming row to a stored station.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property string $code
 * @property string $name_tj
 * @property string $name_ru
 * @property string $name_en
 * @property string $latitude
 * @property string $longitude
 * @property string|null $elevation_m
 * @property string $region_code
 * @property string|null $district_code
 * @property string $timezone
 * @property StationStatus $status
 * @property StationType $station_type
 * @property string|null $owner
 * @property Carbon|null $installed_at
 * @property Carbon $source_updated_at
 * @property int|null $parameters_count
 * @property-read Collection<int, Parameter> $parameters
 */
#[Fillable([
    'source',
    'external_id',
    'code',
    'name_tj',
    'name_ru',
    'name_en',
    'latitude',
    'longitude',
    'elevation_m',
    'region_code',
    'district_code',
    'timezone',
    'status',
    'station_type',
    'owner',
    'installed_at',
    'source_updated_at',
])]
#[UseFactory(StationFactory::class)]
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Six decimal places, matching the column. Decimal rather than
            // float so an unchanged registry re-import stays a no-op.
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'elevation_m' => 'decimal:2',
            'status' => StationStatus::class,
            'station_type' => StationType::class,
            'installed_at' => 'date',
            'source_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Parameter, $this>
     */
    public function parameters(): BelongsToMany
    {
        return $this->belongsToMany(Parameter::class, 'station_parameter')->withTimestamps();
    }

    /**
     * Station name in the requested application locale.
     */
    public function localizedName(?SupportedLocale $locale = null): string
    {
        $locale ??= SupportedLocale::current();

        return match ($locale) {
            SupportedLocale::Tajik => $this->name_tj,
            SupportedLocale::Russian => $this->name_ru,
            SupportedLocale::English => $this->name_en,
        };
    }
}
