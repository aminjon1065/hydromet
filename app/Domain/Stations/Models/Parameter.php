<?php

namespace App\Domain\Stations\Models;

use App\Domain\Stations\Enums\ParameterKind;
use App\Support\Locale\SupportedLocale;
use Database\Factories\ParameterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catalogue entry from docs/03-data-contracts.md section 4.
 *
 * The catalogue is the single place that states what a parameter is measured
 * in. Nothing may infer a unit, precision or averaging period from the code.
 *
 * @property int $id
 * @property string $code
 * @property ParameterKind $kind
 * @property string $name_tj
 * @property string $name_ru
 * @property string $name_en
 * @property string $canonical_unit
 * @property int $precision
 * @property string|null $default_averaging_period
 * @property string|null $plausible_min
 * @property string|null $plausible_max
 * @property bool $active
 * @property-read Collection<int, Station> $stations
 */
#[Fillable([
    'code',
    'kind',
    'name_tj',
    'name_ru',
    'name_en',
    'canonical_unit',
    'precision',
    'default_averaging_period',
    'plausible_min',
    'plausible_max',
    'active',
])]
#[UseFactory(ParameterFactory::class)]
class Parameter extends Model
{
    /** @use HasFactory<ParameterFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ParameterKind::class,
            'precision' => 'integer',
            // Decimal casts keep quality-control bounds exact and stop an
            // unchanged re-import from looking like a change.
            'plausible_min' => 'decimal:4',
            'plausible_max' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Station, $this>
     */
    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'station_parameter')->withTimestamps();
    }

    /**
     * Catalogue name in the requested application locale.
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
