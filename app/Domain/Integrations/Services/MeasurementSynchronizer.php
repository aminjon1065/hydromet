<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Contracts\MeasurementProvider;
use App\Domain\Integrations\Data\SynchronizationOutcome;
use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Integrations\Models\SynchronizationRun;
use App\Domain\Measurements\Services\MeasurementImporter;
use UnexpectedValueException;

/**
 * Runs one bounded measurement synchronization through the canonical importer
 * and journals its exact cursor interval.
 */
final readonly class MeasurementSynchronizer
{
    public function __construct(
        private SynchronizationRunner $runner,
        private MeasurementImporter $importer,
    ) {}

    public function synchronize(
        IntegrationSource $source,
        MeasurementProvider $provider,
        SynchronizationWindow $window,
    ): SynchronizationRun {
        return $this->runner->run(
            $source,
            SynchronizationKind::Measurements,
            function () use ($source, $provider, $window): SynchronizationOutcome {
                if ($provider->sourceKey() !== $source->code) {
                    throw new UnexpectedValueException('The measurement provider does not match the integration source.');
                }

                $result = $this->importer->import($provider, $window);

                return SynchronizationOutcome::fromMeasurements($result);
            },
            $window,
        );
    }
}
