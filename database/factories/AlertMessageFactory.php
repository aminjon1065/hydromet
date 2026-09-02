<?php

namespace Database\Factories;

use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Domain\Alerts\Models\AlertMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Test-only warnings. Every identifier, sender, event code and text is invented
 * and must never be presented as Hydromet data.
 *
 * The default is an active, public, single-message warning, because that is the
 * state most tests start from. Every state leaves the row satisfying the
 * table's CHECK constraints.
 *
 * @extends Factory<AlertMessage>
 */
class AlertMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sentAt = Carbon::parse('2026-01-15T05:00:00Z');
        $suffix = Str::lower(Str::random(8));

        return [
            'source' => 'test',
            'identifier' => 'test-alert-'.$suffix,
            'sender' => 'test-warning-desk',
            'status' => AlertStatus::Actual,
            'message_type' => AlertMessageType::Alert,
            'scope' => AlertScope::Public,
            'event_code' => 'TEST_EVENT',
            'severity' => AlertSeverity::Moderate,
            'urgency' => AlertUrgency::Expected,
            'certainty' => AlertCertainty::Likely,
            'categories' => ['Met'],
            'references' => [],
            'parameters' => [],
            'sent_at' => $sentAt,
            'effective_at' => $sentAt,
            'onset_at' => null,
            // Far enough ahead that the default warning is active whenever the
            // suite runs.
            'expires_at' => Carbon::parse('2030-01-01T00:00:00Z'),
            'headline_tj' => 'Огоҳии озмоишӣ',
            'headline_ru' => 'Тестовое предупреждение',
            'headline_en' => 'Test warning',
            'description_tj' => 'Тавсифи озмоишӣ.',
            'description_ru' => 'Тестовое описание.',
            'description_en' => 'Test description.',
            'instruction_tj' => null,
            'instruction_ru' => null,
            'instruction_en' => null,
            'superseded_by_id' => null,
            'superseded_at' => null,
            'imported_at' => $sentAt,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sent_at' => Carbon::parse('2026-01-01T00:00:00Z'),
            'effective_at' => Carbon::parse('2026-01-01T00:00:00Z'),
            'expires_at' => Carbon::parse('2026-01-02T00:00:00Z'),
        ]);
    }

    public function testStatus(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AlertStatus::Test,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => AlertScope::Restricted,
        ]);
    }

    public function cancellation(string $reference): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_type' => AlertMessageType::Cancel,
            'references' => [$reference],
        ]);
    }

    public function update(string $reference): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_type' => AlertMessageType::Update,
            'references' => [$reference],
        ]);
    }

    public function withInstruction(): static
    {
        return $this->state(fn (array $attributes): array => [
            'instruction_tj' => 'Дастури озмоишӣ.',
            'instruction_ru' => 'Тестовая инструкция.',
            'instruction_en' => 'Test instruction.',
        ]);
    }

    public function severity(AlertSeverity $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => $severity,
        ]);
    }
}
