<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Data\AlertImportResult;
use App\Domain\Alerts\Models\AlertArea;
use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Domain\Alerts\Services\AlertImporter;
use App\Domain\Integrations\Fixtures\FixtureAlertProvider;
use App\Domain\Integrations\Fixtures\FixtureAlertScenario;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Support\Locale\SupportedLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the synthetic warning feed itself, not the importer that reads it.
 *
 * The fixture exists because Hydromet has not chosen a MeteoAlert source type
 * (CLAUDE.md, "External-data development"). That makes it the one place where
 * invented data is allowed to live, and therefore the one place where invented
 * data could quietly start looking official: an event code without its
 * `FIXTURE_` prefix reads as a national vocabulary the portal was never given,
 * and a sender without its synthetic marker reads as Hydromet.
 *
 * It also guards the feed against rotting. The demonstration warnings are in
 * force until 2030 so the map has something to draw; when that date passes the
 * map would simply empty, with nothing failing anywhere. This class fails
 * instead.
 */
class AlertFixtureFeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every invented event code must be recognisable as invented.
     */
    private const EVENT_CODE_PREFIX = 'FIXTURE_';

    /**
     * The single baseline message the feed documents as already expired. It is
     * history on purpose (ALERT-04), so it is the one message excluded from the
     * "still in force" guard below.
     */
    private const EXPIRED_IDENTIFIER = 'fixture-alert-0003';

    /**
     * @return array<string, array{FixtureAlertScenario}>
     */
    public static function fixtureScenarios(): array
    {
        return [
            'baseline feed' => [FixtureAlertScenario::Baseline],
            'lifecycle feed' => [FixtureAlertScenario::Lifecycle],
        ];
    }

    #[Test]
    #[DataProvider('fixtureScenarios')]
    public function every_fixture_states_it_is_synthetic_and_never_for_production(
        FixtureAlertScenario $scenario,
    ): void {
        $notice = $this->stringField($this->document($scenario), '_notice');

        $this->assertStringContainsStringIgnoringCase('synthetic', $notice);
        // Anyone who opens the file, or copies a row out of it, must read that
        // this is not a MeteoAlert sample before they trust a single value.
        $this->assertStringContainsStringIgnoringCase('not Hydromet data', $notice);
        $this->assertStringContainsStringIgnoringCase(
            'never load this file into a production environment',
            $notice,
        );
    }

    #[Test]
    #[DataProvider('fixtureScenarios')]
    public function every_fixture_event_code_carries_the_fixture_prefix(FixtureAlertScenario $scenario): void
    {
        foreach ($this->alertRows($scenario) as $row) {
            $eventCode = $this->stringField($row, 'event_code');

            // Hydromet's warning catalogue is a blocking input
            // (docs/08-hydromet-input-checklist.md, section 3). Until it
            // arrives, the portal must not appear to publish a national event
            // vocabulary it invented for itself.
            $this->assertStringStartsWith(
                self::EVENT_CODE_PREFIX,
                $eventCode,
                'Fixture event code "'.$eventCode.'" in the '.$scenario->value
                    .' feed does not carry the '.self::EVENT_CODE_PREFIX
                    .' prefix, so it reads as an approved Hydromet event code.',
            );
        }
    }

    #[Test]
    #[DataProvider('fixtureScenarios')]
    public function every_fixture_sender_marks_itself_synthetic(FixtureAlertScenario $scenario): void
    {
        foreach ($this->alertRows($scenario) as $row) {
            $sender = $this->stringField($row, 'sender');

            // The sender is shown next to the warning on the public map, so it
            // is the line a reader uses to decide who issued it.
            $this->assertStringContainsStringIgnoringCase('synthetic', $sender);
            $this->assertStringContainsStringIgnoringCase('not Hydromet', $sender);
        }
    }

    #[Test]
    public function the_baseline_feed_is_still_in_force_at_the_current_moment(): void
    {
        $now = Carbon::now('UTC');
        $this->importBaseline();

        $active = (new PublicAlertOverview)->active($now);

        $this->assertNotSame(
            [],
            $active,
            $this->rotMessage('No baseline warning is active at '.$now->toIso8601ZuluString().'.'),
        );

        $stillInForce = AlertMessage::query()
            ->where('identifier', '!=', self::EXPIRED_IDENTIFIER)
            ->get();

        $this->assertNotSame(0, $stillInForce->count());

        foreach ($stillInForce as $message) {
            $this->assertTrue(
                $message->expires_at->greaterThan($now),
                $this->rotMessage('Fixture message "'.$message->identifier.'" has already expired.'),
            );
        }
    }

    #[Test]
    public function the_baseline_feed_names_the_test_that_guards_its_dates(): void
    {
        // The fixture tells the next maintainer which test fails when the dates
        // rot. A renamed class would leave that pointer lying.
        $this->assertStringContainsString(
            class_basename($this),
            $this->stringField($this->document(FixtureAlertScenario::Baseline), '_validity_note'),
        );
    }

    #[Test]
    public function the_expired_fixture_message_really_is_expired_now(): void
    {
        $now = Carbon::now('UTC');
        $this->importBaseline();

        $expired = AlertMessage::query()->where('identifier', self::EXPIRED_IDENTIFIER)->sole();

        $this->assertTrue($expired->isExpiredAt($now));
        $this->assertFalse($expired->isActiveAt($now));

        // Stored as history, never drawn: the fixture is worthless as an
        // ALERT-04 demonstration if its expired message is still publishable.
        $this->assertNotContains(
            self::EXPIRED_IDENTIFIER,
            array_column((new PublicAlertOverview)->active($now), 'identifier'),
        );
    }

    #[Test]
    public function every_stored_fixture_message_carries_all_three_translations(): void
    {
        $this->importBaseline();
        $this->importLifecycle();

        $messages = AlertMessage::query()->get();
        $this->assertNotSame(0, $messages->count());

        foreach ($messages as $message) {
            foreach (SupportedLocale::cases() as $locale) {
                // A warning has no approved fallback language, so a missing
                // translation renders as nothing at all rather than as another
                // language's text.
                $this->assertNotSame(
                    '',
                    $message->localizedHeadline($locale),
                    'Fixture message "'.$message->identifier.'" has no '.$locale->value.' headline.',
                );
                $this->assertNotSame(
                    '',
                    $message->localizedDescription($locale),
                    'Fixture message "'.$message->identifier.'" has no '.$locale->value.' description.',
                );
            }
        }
    }

    #[Test]
    public function every_stored_fixture_area_is_drawable_or_explicitly_geocode_only(): void
    {
        $this->importBaseline();
        $this->importLifecycle();

        $areas = AlertArea::query()->get();
        $this->assertNotSame(0, $areas->count());

        foreach ($areas as $area) {
            $geometry = $area->geometry;

            if ($geometry === null) {
                // CAP allows an area named by geocode alone; it stays
                // undrawable until Hydromet supplies the boundary dataset. It
                // must at least say which area it means.
                $this->assertFalse($area->isDrawable());
                $this->assertNotSame([], $area->geocodes);
                $this->assertNull($area->bbox_west);
                $this->assertNull($area->bbox_north);

                continue;
            }

            $this->assertTrue($area->isDrawable());
            $this->assertContains($geometry['type'] ?? null, ['Polygon', 'MultiPolygon']);

            // A drawable area also needs its derived extent, or the map's
            // bounding-box filter silently loses it.
            $this->assertNotNull($area->bbox_west);
            $this->assertNotNull($area->bbox_south);
            $this->assertNotNull($area->bbox_east);
            $this->assertNotNull($area->bbox_north);
        }
    }

    #[Test]
    public function fixture_extents_and_altitudes_read_back_as_the_polygons_declare_them(): void
    {
        $this->importBaseline();

        $area = AlertMessage::query()
            ->where('identifier', 'fixture-alert-0001')
            ->sole()
            ->areas()
            ->sole();

        // Derived from the polygon in the fixture, not copied from it: editing
        // the coordinates without meaning to shows up here.
        $this->assertSame('68.400000', $area->bbox_west);
        $this->assertSame('38.300000', $area->bbox_south);
        $this->assertSame('69.000000', $area->bbox_east);
        $this->assertSame('38.800000', $area->bbox_north);
        $this->assertNull($area->altitude_m);
        $this->assertNull($area->ceiling_m);

        // Exactly one baseline area declares an altitude band, so the vertical
        // extent is exercised at all.
        $banded = AlertArea::query()->whereNotNull('altitude_m')->sole();

        $this->assertSame('1500.00', $banded->altitude_m);
        $this->assertSame('4000.00', $banded->ceiling_m);
    }

    #[Test]
    public function the_fixture_provider_stamps_its_own_source_key_on_every_message(): void
    {
        $provider = new FixtureAlertProvider;

        // One clearly-mock source shared with the other fixture providers, so a
        // real Hydromet source can never collide with it.
        $this->assertSame(FixtureStationRegistryProvider::SOURCE_KEY, $provider->sourceKey());
        $this->assertSame('fixture', $provider->sourceKey());

        foreach (self::fixtureScenarios() as [$scenario]) {
            foreach ($this->alertRows($scenario) as $row) {
                // The payload must not state a source at all: a feed that could
                // name its own source could name a real one.
                $this->assertArrayNotHasKey('source', $row);
            }
        }

        $this->importBaseline();

        $messages = AlertMessage::query()->get();
        $this->assertNotSame(0, $messages->count());

        foreach ($messages as $message) {
            $this->assertSame($provider->sourceKey(), $message->source);
            $this->assertTrue($message->isMock());
        }
    }

    #[Test]
    public function the_fixture_provider_refuses_a_feed_that_declares_a_different_scenario(): void
    {
        // The wrong file on disk must not be imported under the requested
        // scenario's name; the operator report would then describe a feed that
        // was never loaded.
        $provider = new FixtureAlertProvider(
            FixtureAlertScenario::Lifecycle,
            $this->fixturePath(FixtureAlertScenario::Baseline),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scenario');

        $provider->fetchAlerts();
    }

    #[Test]
    public function a_missing_fixture_file_fails_the_whole_read(): void
    {
        // Losing the file is not one unreadable message, so it is thrown rather
        // than reported as a row rejection and counted as a partial success.
        $provider = new FixtureAlertProvider(
            FixtureAlertScenario::Baseline,
            $this->fixturePath(FixtureAlertScenario::Baseline).'.absent',
        );

        $this->expectException(RuntimeException::class);

        $provider->fetchAlerts();
    }

    #[Test]
    public function the_lifecycle_feed_references_identifiers_that_exist_in_the_baseline_feed(): void
    {
        $baselineIdentifiers = array_map(
            fn (array $row): string => $this->stringField($row, 'identifier'),
            $this->alertRows(FixtureAlertScenario::Baseline),
        );

        foreach ($this->alertRows(FixtureAlertScenario::Lifecycle) as $row) {
            $identifier = $this->stringField($row, 'identifier');
            $references = $row['references'] ?? null;

            if (! is_array($references)) {
                $this->fail('Lifecycle message "'.$identifier.'" has no references list.');
            }

            $this->assertNotSame([], $references, 'Lifecycle message "'.$identifier.'" names nothing to replace.');

            foreach ($references as $reference) {
                $this->assertContains(
                    $reference,
                    $baselineIdentifiers,
                    'Lifecycle message "'.$identifier.'" references a message the baseline feed does not '
                        .'contain, so the Update/Cancel demonstration would supersede nothing.',
                );
            }
        }
    }

    #[Test]
    public function importing_the_lifecycle_feed_supersedes_the_baseline_messages_it_names(): void
    {
        $this->importBaseline();

        $result = $this->importLifecycle();

        // The counter is the evidence the demonstration is not a no-op: both
        // referenced baseline messages were actually withdrawn.
        $this->assertSame(2, $result->superseded);
        $this->assertSame(0, $result->rejected());
        $this->assertSame(2, AlertMessage::query()->whereNotNull('superseded_at')->count());
    }

    private function importBaseline(): void
    {
        (new AlertImporter)->import(new FixtureAlertProvider(FixtureAlertScenario::Baseline));
    }

    private function importLifecycle(): AlertImportResult
    {
        return (new AlertImporter)->import(new FixtureAlertProvider(FixtureAlertScenario::Lifecycle));
    }

    /**
     * Names the failure a maintainer will meet once the fixture's validity
     * dates fall into the past.
     */
    private function rotMessage(string $detail): string
    {
        return $detail.' The demonstration warning feed has rotted: its 2030 validity dates are no longer in '
            .'the future. Re-date '.FixtureAlertScenario::Baseline->fileName().' so the feed stays in force. '
            .'This test fails here on purpose, instead of letting the warning map silently empty with nothing '
            .'reporting why.';
    }

    private function fixturePath(FixtureAlertScenario $scenario): string
    {
        return base_path('app/Domain/Integrations/Fixtures/data/'.$scenario->fileName());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(FixtureAlertScenario $scenario): array
    {
        $path = $this->fixturePath($scenario);
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            $this->fail('The '.$scenario->value.' warning fixture is not readable at '.$path.'.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->fail('The '.$scenario->value.' warning fixture must decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function alertRows(FixtureAlertScenario $scenario): array
    {
        $alerts = $this->document($scenario)['alerts'] ?? null;

        if (! is_array($alerts) || ! array_is_list($alerts) || $alerts === []) {
            $this->fail('The '.$scenario->value.' warning fixture must carry a non-empty "alerts" list.');
        }

        $rows = [];

        foreach ($alerts as $row) {
            if (! is_array($row)) {
                $this->fail('The '.$scenario->value.' warning fixture contains a non-object message.');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $this->fail('A fixture entry is missing a non-empty "'.$key.'" string.');
        }

        return $value;
    }
}
