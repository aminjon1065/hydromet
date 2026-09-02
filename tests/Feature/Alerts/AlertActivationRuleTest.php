<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When a warning starts being in force.
 *
 * CAP makes `effective` optional and says a message takes effect when it was
 * sent if it is absent — not that it is in force from the beginning of time.
 * Treating an absent `effective_at` as "already started" published warnings
 * their sender had dated into the future, which is what these tests pin down.
 *
 * Every assertion passes an explicit moment: "active" is relative to an
 * instant, so letting the wall clock supply it would change the meaning of the
 * test as the suite ages.
 */
class AlertActivationRuleTest extends TestCase
{
    use RefreshDatabase;

    private const SENT_AT = '2026-03-01T12:00:00Z';

    #[Test]
    public function a_message_without_an_effective_time_starts_when_it_was_sent(): void
    {
        $message = $this->message(['effective_at' => null]);

        $this->assertEquals(Carbon::parse(self::SENT_AT), $message->startsAt());
    }

    #[Test]
    public function an_effective_time_overrides_the_sent_time_as_the_start(): void
    {
        $message = $this->message(['effective_at' => Carbon::parse('2026-03-05T00:00:00Z')]);

        $this->assertEquals(Carbon::parse('2026-03-05T00:00:00Z'), $message->startsAt());
    }

    /**
     * The defect this rule replaced: no `effective_at`, a `sent_at` in the
     * future, and the message counted as in force anyway.
     */
    #[Test]
    public function a_message_sent_into_the_future_without_an_effective_time_is_not_active(): void
    {
        $message = $this->message(['effective_at' => null]);
        $before = Carbon::parse('2026-02-28T23:59:59Z');

        $this->assertFalse($message->isActiveAt($before));
        $this->assertTrue($message->isScheduledAt($before));
        $this->assertSame(0, AlertMessage::query()->activeAt($before)->count());
        $this->assertSame(1, AlertMessage::query()->scheduledAt($before)->count());
    }

    #[Test]
    public function it_becomes_active_exactly_at_its_sent_time(): void
    {
        $message = $this->message(['effective_at' => null]);
        $at = Carbon::parse(self::SENT_AT);

        $this->assertTrue($message->isActiveAt($at));
        $this->assertFalse($message->isScheduledAt($at));
        $this->assertSame(1, AlertMessage::query()->activeAt($at)->count());
        $this->assertSame(0, AlertMessage::query()->scheduledAt($at)->count());
    }

    #[Test]
    public function a_future_effective_time_keeps_it_out_of_the_active_view(): void
    {
        $message = $this->message(['effective_at' => Carbon::parse('2026-03-10T00:00:00Z')]);
        $between = Carbon::parse('2026-03-05T00:00:00Z');

        // Already sent, so the naive "sent means live" reading would publish it.
        $this->assertTrue($message->sent_at->lessThan($between));
        $this->assertFalse($message->isActiveAt($between));
        $this->assertTrue($message->isScheduledAt($between));
        $this->assertSame(0, AlertMessage::query()->activeAt($between)->count());
    }

    #[Test]
    public function it_becomes_active_exactly_at_its_effective_time(): void
    {
        $this->message(['effective_at' => Carbon::parse('2026-03-10T00:00:00Z')]);
        $at = Carbon::parse('2026-03-10T00:00:00Z');

        $this->assertSame(1, AlertMessage::query()->activeAt($at)->count());
    }

    /**
     * Moments spanning every boundary of both shapes of the rule. A message
     * either has an effective time or it does not, and the SQL scope and the
     * object method have to reach the same verdict at every one of them —
     * otherwise the map, the API and the panel disagree about the same warning.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function boundaryCases(): array
    {
        $withoutEffective = ['effective_at' => null];
        $withEffective = ['effective_at' => '2026-03-10T00:00:00Z'];

        $cases = [];

        foreach ([
            'long before' => '2026-01-01T00:00:00Z',
            'one microsecond before sending' => '2026-03-01T11:59:59.999999Z',
            'exactly at sending' => '2026-03-01T12:00:00Z',
            'one microsecond after sending' => '2026-03-01T12:00:00.000001Z',
            'between sending and effect' => '2026-03-05T00:00:00Z',
            'one microsecond before effect' => '2026-03-09T23:59:59.999999Z',
            'exactly at effect' => '2026-03-10T00:00:00Z',
            'one microsecond after effect' => '2026-03-10T00:00:00.000001Z',
            'one microsecond before expiry' => '2026-04-01T11:59:59.999999Z',
            'exactly at expiry' => '2026-04-01T12:00:00Z',
            'after expiry' => '2026-05-01T00:00:00Z',
        ] as $label => $moment) {
            $cases['no effective time, '.$label] = [$withoutEffective, $moment];
            $cases['effective time, '.$label] = [$withEffective, $moment];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('boundaryCases')]
    public function the_query_scope_and_the_object_method_always_agree(array $overrides, string $moment): void
    {
        if (is_string($overrides['effective_at'] ?? null)) {
            $overrides['effective_at'] = Carbon::parse($overrides['effective_at']);
        }

        $message = $this->message($overrides);
        $at = Carbon::parse($moment);

        $this->assertSame(
            AlertMessage::query()->activeAt($at)->whereKey($message->id)->exists(),
            $message->isActiveAt($at),
            "activeAt() and isActiveAt() disagree at {$moment}.",
        );

        $this->assertSame(
            AlertMessage::query()->scheduledAt($at)->whereKey($message->id)->exists(),
            $message->isScheduledAt($at),
            "scheduledAt() and isScheduledAt() disagree at {$moment}.",
        );

        // The two states are mutually exclusive: a warning cannot be both
        // queued and in force.
        $this->assertFalse($message->isActiveAt($at) && $message->isScheduledAt($at));
    }

    #[Test]
    public function a_withheld_message_is_neither_active_nor_scheduled(): void
    {
        $restricted = AlertMessage::factory()->restricted()->create([
            'sent_at' => Carbon::parse(self::SENT_AT),
            'effective_at' => null,
            'expires_at' => Carbon::parse('2026-04-01T12:00:00Z'),
        ]);

        $before = Carbon::parse('2026-02-01T00:00:00Z');

        $this->assertFalse($restricted->isActiveAt($before));
        $this->assertFalse($restricted->isScheduledAt($before));
        $this->assertSame(0, AlertMessage::query()->scheduledAt($before)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function message(array $overrides = []): AlertMessage
    {
        return AlertMessage::factory()->create([
            'sent_at' => Carbon::parse(self::SENT_AT),
            'expires_at' => Carbon::parse('2026-04-01T12:00:00Z'),
            ...$overrides,
        ]);
    }
}
