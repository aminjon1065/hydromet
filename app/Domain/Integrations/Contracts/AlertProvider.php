<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Alerts\Data\AlertBatch;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Data\SynchronizationWindow;

/**
 * External supplier of official warnings.
 *
 * An implementation reads a provider's own format and returns canonical
 * records; it never touches the `alert_messages` or `alert_areas` tables.
 * Persistence belongs to {@see AlertImporter}
 * (docs/02-architecture.md, sections 4 and 5).
 *
 * The contract is deliberately format-agnostic. Hydromet has not chosen a
 * source type (docs/08-hydromet-input-checklist.md, section 3), and the
 * preference order is CAP Atom/XML, WFS GeoJSON, static CAP files, then a
 * custom JSON feed (docs/04-smartmet-and-alerts.md, section 10). Each of those
 * becomes one implementation of this interface; none of them becomes the
 * portal's database contract.
 *
 * Implementations must not throw for a single unreadable message: they report
 * it in the batch's rejections, so one malformed warning cannot discard a good
 * feed. They may throw only when the whole read failed.
 *
 * A null window requests the provider's complete current feed, which is what a
 * warning source normally offers. A bounded window is accepted for parity with
 * the measurement contract and for feeds that support a sent-time range; a real
 * adapter must apply it upstream rather than downloading everything and
 * filtering locally.
 */
interface AlertProvider
{
    /**
     * Provider key stored as `alert_messages.source`.
     */
    public function sourceKey(): string;

    /**
     * Human-readable origin shown in operator output. Must never contain
     * credentials or full URLs with query strings.
     */
    public function describeOrigin(): string;

    public function fetchAlerts(?SynchronizationWindow $window = null): AlertBatch;
}
