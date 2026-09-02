<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Support\Locale\SupportedLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The message history a detail request returns.
 *
 * A warning is rarely two messages. `Alert → Update → Update → Cancel` is an
 * ordinary shape, and a client asking about any one of them needs all four:
 * looking a single step in each direction returns a window, not a history, and
 * loses the rest without saying so.
 *
 * The walk is iterative and bounded, because the data it walks is derived from
 * an external feed: a reference cycle in corrupted data must terminate, not
 * hang a public endpoint.
 */
class AlertMessageChainTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'hydromet-meteoalert';

    /**
     * The instant two links of the chain share, so `sent_at DESC` cannot
     * decide between them on its own.
     */
    private const TIED_INSTANT = '2026-01-11T05:00:00Z';

    private PublicAlertOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overview = new PublicAlertOverview;
        Carbon::setTestNow(Carbon::parse('2026-06-01T00:00:00Z'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyLinkOfTheChain(): array
    {
        return [
            'the original alert' => ['TJ-1'],
            'the first update' => ['TJ-2'],
            'the second update' => ['TJ-3'],
            'the cancellation' => ['TJ-4'],
        ];
    }

    #[Test]
    #[DataProvider('everyLinkOfTheChain')]
    public function the_whole_chain_is_returned_from_any_of_its_links(string $identifier): void
    {
        $this->buildChain();

        $detail = $this->overview->detail(self::SOURCE, $identifier, SupportedLocale::English);

        $this->assertNotNull($detail);
        $this->assertSame(
            // Newest first.
            ['TJ-4', 'TJ-3', 'TJ-2', 'TJ-1'],
            array_column($detail['history'], 'identifier'),
        );
    }

    #[Test]
    public function the_api_returns_the_whole_chain_from_the_first_message(): void
    {
        $this->buildChain();

        $this->getJson('/api/v1/alerts/'.self::SOURCE.'/TJ-1')
            ->assertOk()
            ->assertJsonCount(4, 'data.history')
            ->assertJsonPath('data.history.0.identifier', 'TJ-4')
            ->assertJsonPath('data.history.0.message_type', 'Cancel')
            ->assertJsonPath('data.history.3.identifier', 'TJ-1')
            ->assertJsonPath('data.is_active', false);
    }

    /**
     * Two messages of one chain can be sent in the same microsecond, and then
     * `sent_at DESC` alone decides nothing — the driver is free to return them
     * either way round. The tie is broken by `identifier`, then by the storage
     * key.
     *
     * The shared instant is set when the rows are created, not patched in
     * afterwards: `sent_at` is immutable, so a test that tried to change it
     * would be refused by the guards rather than proving anything.
     */
    #[Test]
    public function two_messages_sent_at_the_same_instant_are_ordered_by_identifier(): void
    {
        $this->buildChain(['TJ-2' => self::TIED_INSTANT, 'TJ-3' => self::TIED_INSTANT]);

        $tied = AlertMessage::query()
            ->where('source', self::SOURCE)
            ->whereIn('identifier', ['TJ-2', 'TJ-3'])
            ->get();

        // The premise of the test, asserted rather than assumed.
        $this->assertCount(2, $tied);
        $this->assertCount(1, $tied->map(static fn (AlertMessage $m): string => $m->sent_at->format(
            AlertMessage::TIMESTAMP_FORMAT,
        ))->unique());

        $history = array_column(
            $this->overview->detail(self::SOURCE, 'TJ-1', SupportedLocale::English)['history'] ?? [],
            'identifier',
        );

        // Newest first, and the tied pair in identifier order between the two
        // messages whose instants are unambiguous.
        $this->assertSame(['TJ-4', 'TJ-2', 'TJ-3', 'TJ-1'], $history);
    }

    #[Test]
    public function the_tied_order_is_the_same_from_every_link_and_on_every_request(): void
    {
        $this->buildChain(['TJ-2' => self::TIED_INSTANT, 'TJ-3' => self::TIED_INSTANT]);

        $orders = [];

        foreach (['TJ-1', 'TJ-2', 'TJ-3', 'TJ-4', 'TJ-1'] as $link) {
            $orders[] = array_column(
                $this->overview->detail(self::SOURCE, $link, SupportedLocale::English)['history'] ?? [],
                'identifier',
            );
        }

        // One distinct order across five reads from four different links: the
        // ordering does not depend on which message was asked about, nor on
        // which row the walk happened to reach first.
        $this->assertCount(1, array_unique(array_map(
            static fn (array $order): string => implode(',', $order),
            $orders,
        )));
        $this->assertSame(['TJ-4', 'TJ-2', 'TJ-3', 'TJ-1'], $orders[0]);
    }

    /**
     * A restricted or test message in the same feed is not part of a public
     * history, and must not become readable through one.
     */
    #[Test]
    public function a_withheld_message_never_appears_in_a_public_chain(): void
    {
        $this->buildChain();

        $restricted = AlertMessage::factory()->restricted()->create([
            'source' => self::SOURCE,
            'identifier' => 'TJ-RESTRICTED',
            'headline_en' => 'Restricted operational warning',
        ]);

        // Attached to the chain at the database level, exactly as corrupted or
        // mixed-scope data would be.
        DB::table('alert_messages')
            ->where('id', $restricted->id)
            ->update([
                'superseded_by_id' => $this->message('TJ-4')->id,
                'superseded_at' => Carbon::parse('2026-01-20T05:00:00Z')->format(AlertMessage::TIMESTAMP_FORMAT),
            ]);

        $detail = $this->overview->detail(self::SOURCE, 'TJ-1', SupportedLocale::English);

        $this->assertNotNull($detail);
        $this->assertNotContains('TJ-RESTRICTED', array_column($detail['history'], 'identifier'));
    }

    #[Test]
    public function a_chain_from_another_source_is_never_mixed_in(): void
    {
        $this->buildChain();

        AlertMessage::factory()->create([
            'source' => 'fixture',
            'identifier' => 'TJ-1',
            'headline_en' => 'A different feed, same identifier',
        ]);

        $detail = $this->overview->detail(self::SOURCE, 'TJ-1', SupportedLocale::English);

        $this->assertNotNull($detail);
        $this->assertCount(4, $detail['history']);
    }

    /**
     * Corrupted data must terminate the walk, not the process.
     */
    #[Test]
    public function a_reference_cycle_does_not_hang_the_detail_endpoint(): void
    {
        $this->buildChain();

        // TJ-1 is already superseded by TJ-2; point TJ-4 back at TJ-1 so the
        // supersession graph closes into a loop.
        DB::table('alert_messages')
            ->where('id', $this->message('TJ-4')->id)
            ->update([
                'superseded_by_id' => $this->message('TJ-1')->id,
                'superseded_at' => Carbon::parse('2026-01-25T05:00:00Z')->format(AlertMessage::TIMESTAMP_FORMAT),
            ]);

        $detail = $this->overview->detail(self::SOURCE, 'TJ-2', SupportedLocale::English);

        $this->assertNotNull($detail);
        $this->assertCount(4, $detail['history']);
    }

    #[Test]
    public function a_single_message_chain_contains_only_itself(): void
    {
        AlertMessage::factory()->create([
            'source' => self::SOURCE,
            'identifier' => 'TJ-ALONE',
        ]);

        $detail = $this->overview->detail(self::SOURCE, 'TJ-ALONE', SupportedLocale::English);

        $this->assertNotNull($detail);
        $this->assertSame(['TJ-ALONE'], array_column($detail['history'], 'identifier'));
    }

    /**
     * A longer chain than any real warning produces, to prove the walk keeps
     * following links rather than stopping at a fixed depth.
     */
    #[Test]
    public function a_long_chain_is_returned_in_full(): void
    {
        $length = 12;
        $previous = null;

        for ($index = 1; $index <= $length; $index++) {
            $message = AlertMessage::factory()->create([
                'source' => self::SOURCE,
                'identifier' => 'TJ-LONG-'.$index,
                'sent_at' => Carbon::parse('2026-01-01T00:00:00Z')->addHours($index),
                'effective_at' => Carbon::parse('2026-01-01T00:00:00Z')->addHours($index),
            ]);

            if ($previous !== null) {
                $previous->update([
                    'superseded_by_id' => $message->id,
                    'superseded_at' => $message->sent_at,
                ]);
            }

            $previous = $message;
        }

        foreach ([1, 6, $length] as $link) {
            $detail = $this->overview->detail(self::SOURCE, 'TJ-LONG-'.$link, SupportedLocale::English);

            $this->assertNotNull($detail);
            $this->assertCount($length, $detail['history'], "Chain truncated when asked from link {$link}.");
        }
    }

    private function message(string $identifier): AlertMessage
    {
        return AlertMessage::query()
            ->where('source', self::SOURCE)
            ->where('identifier', $identifier)
            ->sole();
    }

    /**
     * `Alert → Update → Update → Cancel`, the shape the endpoint used to lose.
     *
     * @param  array<string, string>  $sentAtOverrides  Send instants by
     *                                                  identifier, for the
     *                                                  cases that need two
     *                                                  links to share one.
     */
    private function buildChain(array $sentAtOverrides = []): void
    {
        $sequence = [
            ['TJ-1', 'alert', '2026-01-10T05:00:00Z'],
            ['TJ-2', 'update', '2026-01-11T05:00:00Z'],
            ['TJ-3', 'update', '2026-01-12T05:00:00Z'],
            ['TJ-4', 'cancel', '2026-01-13T05:00:00Z'],
        ];

        $previous = null;

        foreach ($sequence as [$identifier, $type, $sentAt]) {
            $sentAt = $sentAtOverrides[$identifier] ?? $sentAt;
            $factory = AlertMessage::factory();

            if ($type === 'update' && $previous !== null) {
                $factory = $factory->update($previous->identifier);
            }

            if ($type === 'cancel' && $previous !== null) {
                $factory = $factory->cancellation($previous->identifier);
            }

            $message = $factory->create([
                'source' => self::SOURCE,
                'identifier' => $identifier,
                'sent_at' => Carbon::parse($sentAt),
                'effective_at' => Carbon::parse($sentAt),
            ]);

            $previous?->update([
                'superseded_by_id' => $message->id,
                'superseded_at' => Carbon::parse($sentAt),
            ]);

            $previous = $message;
        }
    }
}
