<?php

namespace App\Domain\Content\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return __('content.statuses.'.$this->value);
    }
}
