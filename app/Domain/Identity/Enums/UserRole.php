<?php

namespace App\Domain\Identity\Enums;

/**
 * Administrative roles defined in docs/01-product-scope.md, section 3.2.
 *
 * Phase 1 only establishes the role skeleton and panel access. Per-action
 * permissions are added together with the administrative features they guard.
 */
enum UserRole: string
{
    case Administrator = 'administrator';
    case Operator = 'operator';
    case Editor = 'editor';

    public function label(): string
    {
        return __('identity.roles.'.$this->value);
    }

    /**
     * Whether the role may open the administration panel at all.
     */
    public function canAccessAdminPanel(): bool
    {
        return true;
    }
}
