<?php

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable administrative evidence. Only AuditRecorder creates rows.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed> $changes
 * @property-read User|null $actor
 */
#[Fillable([
    'occurred_at',
    'actor_id',
    'action',
    'subject_type',
    'subject_id',
    'subject_label',
    'changes',
])]
class AuditEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Audit events are immutable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
