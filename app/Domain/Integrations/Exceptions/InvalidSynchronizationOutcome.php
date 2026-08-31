<?php

namespace App\Domain\Integrations\Exceptions;

use InvalidArgumentException;

/**
 * An import service reported counters that cannot all be true at once.
 *
 * This is a defect in the import service, not bad provider data: a row the
 * provider sent is neither stored nor quarantined, or more rows changed than
 * were accepted. It is raised before the run's counters are written, so the
 * journal never records a total that contradicts itself.
 *
 * The message names only counters, never provider content.
 */
final class InvalidSynchronizationOutcome extends InvalidArgumentException {}
