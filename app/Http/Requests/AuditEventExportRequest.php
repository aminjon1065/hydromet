<?php

namespace App\Http\Requests;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The audit log is the record of who did what. Reading it is an administrator
 * ability, so authorization is asserted here rather than left to the panel
 * resource that happens to link to this route.
 */
class AuditEventExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->role === UserRole::Administrator;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * The window is interpreted in UTC, matching the stored values and the
     * exported column name. A local-time reading would silently shift the
     * boundary by five hours.
     */
    public function windowStart(): ?CarbonImmutable
    {
        return $this->boundary('from');
    }

    public function windowEnd(): ?CarbonImmutable
    {
        return $this->boundary('to');
    }

    private function boundary(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->utc()
            : null;
    }
}
