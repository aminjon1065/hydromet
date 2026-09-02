<?php

namespace App\Domain\Content\Enums;

enum ContentType: string
{
    case Page = 'page';
    case News = 'news';
    case Bulletin = 'bulletin';
    case HealthAdvice = 'health_advice';

    public function label(): string
    {
        return __('content.types.'.$this->value);
    }
}
