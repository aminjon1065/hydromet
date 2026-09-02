<?php

namespace App\Domain\Alerts\Data;

use App\Domain\Alerts\Enums\AlertCertainty;
use App\Domain\Alerts\Enums\AlertMessageType;
use App\Domain\Alerts\Enums\AlertScope;
use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Enums\AlertStatus;
use App\Domain\Alerts\Enums\AlertUrgency;
use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectionReason;
use Illuminate\Support\Carbon;

/**
 * Canonical warning message, docs/03-data-contracts.md section 7.
 *
 * The only warning shape the portal's business code knows. It uses CAP 1.2
 * vocabulary because that is the interchange standard the contract names, but
 * it is not a CAP XML binding: an adapter for CAP Atom, WFS GeoJSON or a custom
 * JSON feed maps its own format into these same fields
 * (docs/04-smartmet-and-alerts.md, section 10). Nothing downstream reads a
 * provider field name.
 *
 * A record being constructible means it is structurally readable, not that it
 * is publishable: supersession, duplicate identifiers and persistence are
 * decided by the import service, which owns the tables.
 */
final readonly class AlertRecord
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $references
     * @param  array<string, string>  $parameters
     * @param  list<AlertAreaRecord>  $areas
     */
    public function __construct(
        public string $source,
        public string $identifier,
        public string $sender,
        public AlertStatus $status,
        public AlertMessageType $messageType,
        public AlertScope $scope,
        public string $eventCode,
        public AlertSeverity $severity,
        public AlertUrgency $urgency,
        public AlertCertainty $certainty,
        public array $categories,
        public array $references,
        public array $parameters,
        public Carbon $sentAt,
        public ?Carbon $effectiveAt,
        public ?Carbon $onsetAt,
        public Carbon $expiresAt,
        public string $headlineTj,
        public string $headlineRu,
        public string $headlineEn,
        public string $descriptionTj,
        public string $descriptionRu,
        public string $descriptionEn,
        public ?string $instructionTj,
        public ?string $instructionRu,
        public ?string $instructionEn,
        public array $areas,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidCanonicalRow
     */
    public static function fromCanonical(array $row): self
    {
        $reader = new CanonicalReader($row);
        $headline = $reader->localized('headline');
        $description = $reader->localized('description');
        $instruction = self::readOptionalLocalized($row, 'instruction');

        return new self(
            source: $reader->string('source'),
            identifier: $reader->string('identifier'),
            sender: $reader->string('sender'),
            status: $reader->enum('status', AlertStatus::class),
            messageType: $reader->enum('message_type', AlertMessageType::class),
            scope: $reader->enum('scope', AlertScope::class),
            eventCode: $reader->string('event_code'),
            severity: $reader->enum('severity', AlertSeverity::class),
            urgency: $reader->enum('urgency', AlertUrgency::class),
            certainty: $reader->enum('certainty', AlertCertainty::class),
            categories: $reader->stringList('category'),
            references: self::readReferences($row),
            parameters: self::readParameters($row),
            sentAt: $reader->dateTime('sent_at'),
            effectiveAt: $reader->nullableDateTime('effective_at'),
            onsetAt: $reader->nullableDateTime('onset_at'),
            expiresAt: $reader->dateTime('expires_at'),
            headlineTj: $headline['tj'],
            headlineRu: $headline['ru'],
            headlineEn: $headline['en'],
            descriptionTj: $description['tj'],
            descriptionRu: $description['ru'],
            descriptionEn: $description['en'],
            instructionTj: $instruction['tj'] ?? null,
            instructionRu: $instruction['ru'] ?? null,
            instructionEn: $instruction['en'] ?? null,
            areas: self::readAreas($row),
        );
    }

    /**
     * `references` is required by the contract but may be empty for a first
     * `Alert`. An absent key is a mapping fault; an empty list is a fact.
     *
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     *
     * @throws InvalidCanonicalRow
     */
    private static function readReferences(array $row): array
    {
        if (! array_key_exists('references', $row)) {
            throw new InvalidCanonicalRow(
                RejectionReason::MalformedRow,
                "Required field 'references' is missing.",
            );
        }

        if ($row['references'] === []) {
            return [];
        }

        return (new CanonicalReader($row))->stringList('references');
    }

    /**
     * Source-specific physical values, kept as a flat string map.
     *
     * The portal does not interpret them: Hydromet's parameter vocabulary for
     * warnings is not agreed (docs/08-hydromet-input-checklist.md, section 3),
     * so they are carried verbatim for display and never parsed into numbers
     * the portal would then have to justify.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, string>
     *
     * @throws InvalidCanonicalRow
     */
    private static function readParameters(array $row): array
    {
        $value = $row['parameters'] ?? [];

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                "Field 'parameters' must be an object of string values.",
            );
        }

        $parameters = [];

        foreach ($value as $name => $parameter) {
            if (! is_string($parameter)) {
                throw new InvalidCanonicalRow(
                    RejectionReason::InvalidFieldType,
                    "Field 'parameters' must hold string values only.",
                );
            }

            $parameters[(string) $name] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array{tj?: string, ru?: string, en?: string}
     *
     * @throws InvalidCanonicalRow
     */
    private static function readOptionalLocalized(array $row, string $key): array
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return [];
        }

        // Present means complete. A single-language instruction would force a
        // fallback at render time, and no approved rule says which language may
        // stand in for another (CLAUDE.md).
        return (new CanonicalReader($row))->localized($key);
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<AlertAreaRecord>
     *
     * @throws InvalidCanonicalRow
     */
    private static function readAreas(array $row): array
    {
        $value = $row['areas'] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                "Field 'areas' must be an array of affected areas.",
            );
        }

        if ($value === []) {
            throw new InvalidCanonicalRow(
                RejectionReason::MissingAffectedArea,
                'A warning must name at least one affected area.',
            );
        }

        $areas = [];

        foreach ($value as $index => $area) {
            if (! is_array($area)) {
                throw new InvalidCanonicalRow(
                    RejectionReason::InvalidFieldType,
                    "Field 'areas[{$index}]' must be an object.",
                );
            }

            $areas[] = AlertAreaRecord::fromCanonical($area);
        }

        return $areas;
    }

    /**
     * Safe reference for rejection reporting.
     */
    public function identity(): string
    {
        return $this->source.':'.$this->identifier;
    }

    /**
     * Attributes written to `alert_messages`, excluding identity and
     * supersession, which the import service owns.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'sender' => $this->sender,
            'status' => $this->status,
            'message_type' => $this->messageType,
            'scope' => $this->scope,
            'event_code' => $this->eventCode,
            'severity' => $this->severity,
            'urgency' => $this->urgency,
            'certainty' => $this->certainty,
            'categories' => $this->categories,
            'references' => $this->references,
            'parameters' => $this->parameters,
            'sent_at' => $this->sentAt,
            'effective_at' => $this->effectiveAt,
            'onset_at' => $this->onsetAt,
            'expires_at' => $this->expiresAt,
            'headline_tj' => $this->headlineTj,
            'headline_ru' => $this->headlineRu,
            'headline_en' => $this->headlineEn,
            'description_tj' => $this->descriptionTj,
            'description_ru' => $this->descriptionRu,
            'description_en' => $this->descriptionEn,
            'instruction_tj' => $this->instructionTj,
            'instruction_ru' => $this->instructionRu,
            'instruction_en' => $this->instructionEn,
        ];
    }
}
