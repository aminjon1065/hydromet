<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEvent;
use InvalidArgumentException;

final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $action,
        string $subjectType,
        string|int $subjectId,
        array $changes,
        ?int $actorId = null,
        ?string $subjectLabel = null,
    ): AuditEvent {
        $action = trim($action);
        $subjectType = trim($subjectType);
        $subjectId = trim((string) $subjectId);

        if ($action === '' || $subjectType === '' || $subjectId === '') {
            throw new InvalidArgumentException('Audit action and subject identity must not be blank.');
        }

        return AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'changes' => $changes,
        ]);
    }
}
