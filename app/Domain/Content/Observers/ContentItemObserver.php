<?php

namespace App\Domain\Content\Observers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Content\Models\ContentItem;
use Illuminate\Support\Facades\Auth;

final class ContentItemObserver
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = [
        'type',
        'slug',
        'title_tj',
        'title_ru',
        'title_en',
        'summary_tj',
        'summary_ru',
        'summary_en',
        'body_tj',
        'body_ru',
        'body_en',
        'status',
        'published_at',
        'published_by',
    ];

    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function created(ContentItem $content): void
    {
        $after = $this->selectedAttributes($content->getAttributes(), self::AUDITED_FIELDS);

        $this->auditRecorder->record(
            action: 'content.created',
            subjectType: 'content_item',
            subjectId: $content->id,
            changes: [
                'fields' => array_keys($after),
                'before' => (object) [],
                'after' => $after,
            ],
            actorId: $this->creationActor($content),
            subjectLabel: $content->slug,
        );
    }

    public function updated(ContentItem $content): void
    {
        $changedFields = array_values(array_intersect(
            self::AUDITED_FIELDS,
            array_keys($content->getChanges()),
        ));

        if ($changedFields === []) {
            return;
        }

        $this->auditRecorder->record(
            action: 'content.updated',
            subjectType: 'content_item',
            subjectId: $content->id,
            changes: [
                'fields' => $changedFields,
                'before' => $this->selectedAttributes($content->getRawOriginal(), $changedFields),
                'after' => $this->selectedAttributes($content->getAttributes(), $changedFields),
            ],
            actorId: $this->updateActor($content),
            subjectLabel: $content->slug,
        );
    }

    /**
     * The signed-in administrator is the authoritative actor. Only when the
     * write happens outside a session does the provenance column stand in.
     */
    private function creationActor(ContentItem $content): ?int
    {
        return $this->identifier(Auth::id()) ?? $this->identifier($content->created_by);
    }

    /**
     * Outside a session, `updated_by` is trustworthy only when this very save
     * wrote it. A value left over from an earlier edit would blame the previous
     * editor for a change they did not make, so it is recorded as system.
     */
    private function updateActor(ContentItem $content): ?int
    {
        $authenticated = $this->identifier(Auth::id());

        if ($authenticated !== null) {
            return $authenticated;
        }

        return array_key_exists('updated_by', $content->getChanges())
            ? $this->identifier($content->updated_by)
            : null;
    }

    private function identifier(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function selectedAttributes(array $attributes, array $fields): array
    {
        $selected = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $attributes)) {
                $selected[$field] = $attributes[$field];
            }
        }

        return $selected;
    }
}
