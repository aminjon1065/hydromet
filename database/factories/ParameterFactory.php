<?php

namespace Database\Factories;

use App\Domain\Stations\Enums\ParameterKind;
use App\Domain\Stations\Models\Parameter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only catalogue entries.
 *
 * Units, precision and plausibility bounds here are placeholders. They are safe
 * for tests and unsafe for anything public: the approved catalogue comes from
 * Hydromet (docs/08-hydromet-input-checklist.md, section 1).
 *
 * @extends Factory<Parameter>
 */
class ParameterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST'.Str::upper(Str::random(6)),
            'kind' => ParameterKind::Pollutant,
            'name_tj' => 'Параметри озмоишӣ',
            'name_ru' => 'Тестовый параметр',
            'name_en' => 'Test parameter',
            'canonical_unit' => 'ug/m3',
            'precision' => 1,
            'default_averaging_period' => 'PT1H',
            'plausible_min' => 0,
            'plausible_max' => 1000,
            'active' => true,
        ];
    }

    public function meteorological(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ParameterKind::Meteorological,
            'canonical_unit' => 'degC',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    /**
     * A catalogue entry that declares no bounds and no averaging period, so
     * tests can prove a missing value stays null.
     */
    public function withoutBounds(): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_averaging_period' => null,
            'plausible_min' => null,
            'plausible_max' => null,
        ]);
    }
}
