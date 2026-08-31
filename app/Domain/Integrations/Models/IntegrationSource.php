<?php

namespace App\Domain\Integrations\Models;

use App\Support\Casts\JsonObjectMap;
use Database\Factories\IntegrationSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configuration of one external source, docs/03-data-contracts.md section 8.1.
 *
 * The row describes how to reach a provider and how to interpret it. It never
 * holds the credential: `authentication_type` names the mechanism, and the key
 * or password lives in server-side secrets only
 * (docs/02-architecture.md, section 9).
 *
 * `enabled` is stored for the scheduler phase. Nothing polls a source yet, and
 * the fixture commands are explicit operator actions, so the flag is not a gate
 * on manual runs today.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property string|null $base_url
 * @property string $authentication_type
 * @property string|null $producer
 * @property string $timezone
 * @property bool $enabled
 * @property int|null $polling_interval_seconds
 * @property int $timeout_seconds
 * @property string $cursor_strategy
 * @property int $overlap_seconds
 * @property array<string, string> $parameter_mapping
 * @property array<string, string> $unit_mapping
 * @property-read Collection<int, SynchronizationRun> $synchronizationRuns
 */
#[Fillable([
    'code',
    'type',
    'base_url',
    'authentication_type',
    'producer',
    'timezone',
    'enabled',
    'polling_interval_seconds',
    'timeout_seconds',
    'cursor_strategy',
    'overlap_seconds',
    'parameter_mapping',
    'unit_mapping',
])]
#[UseFactory(IntegrationSourceFactory::class)]
class IntegrationSource extends Model
{
    /** @use HasFactory<IntegrationSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'polling_interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'overlap_seconds' => 'integer',
            // Dictionaries, not lists: an empty mapping must encode as `{}` so
            // the column keeps its JSON object shape.
            'parameter_mapping' => JsonObjectMap::class,
            'unit_mapping' => JsonObjectMap::class,
        ];
    }

    /**
     * @return HasMany<SynchronizationRun, $this>
     */
    public function synchronizationRuns(): HasMany
    {
        return $this->hasMany(SynchronizationRun::class, 'source_id');
    }

    /**
     * @return HasMany<SynchronizationRun, $this>
     */
    public function latestSynchronizationRuns(): HasMany
    {
        return $this->synchronizationRuns()->latest('started_at');
    }
}
